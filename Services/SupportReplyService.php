<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\SupportChannelContract;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Ai\Services\Agent\AgentChatClient;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\Conversation\Models\Message;
use MultiTenantSaas\Modules\Conversation\Services\ConversationRuleTaggingService;
use MultiTenantSaas\Modules\ServiceDesk\Dto\RiskVerdict;
use MultiTenantSaas\Modules\ServiceDesk\Events\SupportHandoffRequested;
use MultiTenantSaas\Modules\UserAi\Dto\UserAiContext;
use MultiTenantSaas\Modules\UserAi\Services\UserAiRuntime;
use MultiTenantSaas\Scopes\TenantScope;
use MultiTenantSaas\Services\Channel\ChannelManager;
use Throwable;

/**
 * 客服应答编排
 *
 * 一条入站消息进来后的全部决策在此，顺序不可调换：
 *
 *   1. **人工接待中 → AI 不插话**（判定依据是渠道接待态，不是本地开关）
 *   2. **风险拦截**（强制转人工）—— 优先于一切，包括用户自己的转人工请求
 *   3. 用户主动要求转人工
 *   4. 连续 N 轮未命中知识库 → 转人工
 *   5. AI 应答（走 UserAiRuntime：入站守护 → 检索咽喉 → 出站守护）
 *
 * 边界：本服务只负责「决策 + 发出答复」，消息入库由渠道管道完成、审计由 UserAiRuntime 完成。
 */
class SupportReplyService
{
    /** 用户主动要求转人工的表述（粗筛，精判交给风险拦截器的意图识别） */
    private const HUMAN_REQUEST_KEYWORDS = [
        '转人工', '人工客服', '真人', '找客服', '人工服务', '转接人工',
    ];

    public function __construct(
        private readonly SupportSessionService $sessions,
        private readonly RiskGuard $risk,
        private readonly HandoffService $handoff,
        private readonly AccessLevelResolver $accessLevels,
        private readonly SupportAgentResolver $agents,
        private readonly SupportEscalationService $escalations,
        private readonly ServiceDeskSettings $settings,
        private readonly SatisfactionService $satisfaction,
        private readonly AgentChatClient $chatClient,
        private readonly UserAiRuntime $runtime,
        private readonly ChannelManager $channels,
        private readonly ?ConversationRuleTaggingService $ruleTagging = null,
    ) {}

    /**
     * 处理一条客服入站消息
     *
     * 本方法会写会话元数据、落 AI 消息、发站内通知 —— 全是租户作用域的行。
     * 上下文以**会话自身的租户**为准（不依赖调用方是否设过）：由队列作业调用时固然有，
     * 但直接调用时没有，而缺上下文会让 BelongsToTenant 填不出 tenant_id
     * （NOT NULL 违反）或让 TenantScope fail-closed 静默查空。
     */
    public function handle(Conversation $conversation, Message $inbound): void
    {
        $previousTenantId = TenantContext::getId();
        TenantContext::setTenantId((string) $conversation->tenant_id);

        try {
            $this->decide($conversation, $inbound);
        } finally {
            if ($previousTenantId === null) {
                TenantContext::clear();
            } else {
                TenantContext::setTenantId($previousTenantId);
            }
        }
    }

    /**
     * 决策主体（上下文已就绪）
     */
    private function decide(Conversation $conversation, Message $inbound): void
    {
        // ── 0. 接待态回读（镜像可能已陈旧） ─────────────────────────
        // 判定依据设计上是「渠道接待态」，本地只是镜像。两类陈旧都会出事：
        //   人工接待结束后渠道回到待接入态，本地若停在 4（已结束）→ AI 永久沉默；
        //   人工刚接手时本地若还是 1 → AI 插话（违背铁律）。
        // 故在「可能陈旧」的情形下回读一次；读不到时 fail-closed（见 refreshStateIfStale）。
        $this->refreshStateIfStale($conversation);

        // ── 1. 满意度反馈：先记录，且**不再当成问题去答** ──────────
        // 放在「人工不插话」之前，是因为评价的对象往往正是那次人工服务，
        // 放在后面就永远采不到。匹配限定在「已邀评」窗口内，避免把真实提问吞掉。
        $text = trim((string) $inbound->content);

        if ($text !== '' && $this->satisfaction->recordIfFeedback($conversation, $text)) {
            $this->reply($conversation, $this->satisfaction->thanksText());

            return;
        }

        // ── 2. 人工接待中：AI 不插话 ─────────────────────────────
        if (! $this->sessions->isAiServing($conversation)) {
            Log::info('[ServiceDesk] 人工接待中，AI 不插话', [
                'conversation_id' => $conversation->conversation_id,
                'service_state' => $this->sessions->getState($conversation),
            ]);

            // 只发一次「有人在看」的提示：人工本来就在，重复提醒是噪音；
            // 但完全不发会让用户以为没人理。
            $this->noticeHumanServing($conversation);

            return;
        }

        $question = trim((string) $inbound->content);

        if ($question === '') {
            return;
        }

        // ── 2.5 自动招呼：首次用户消息时立即回复，降低等待焦虑 ──────
        $this->sendAutoGreeting($conversation);

        // ── 3. 风险拦截：强制转人工（优先于用户的请求） ───────────
        $verdict = $this->risk->inspect($conversation, $question);

        if ($verdict !== null) {
            $this->handleRisk($conversation, $verdict, $inbound);

            return;
        }

        // ── 4. 用户主动要求转人工 ────────────────────────────────
        if ($this->wantsHuman($conversation, $question)) {
            $this->requestHandoff($conversation, HandoffService::REASON_VISITOR_REQUEST);

            return;
        }

        // ── 5. 连续未命中达阈值 ─────────────────────────────────
        $maxUnresolved = max(1, (int) $this->settings->getForConversation(
            $conversation,
            'handoff.max_unresolved_turns',
            3,
        ));

        if ($this->sessions->getUnresolvedTurns($conversation) >= $maxUnresolved) {
            $this->requestHandoff($conversation, HandoffService::REASON_UNRESOLVED_TURNS);

            return;
        }

        // ── 6. AI 应答 ─────────────────────────────────────────
        $this->answer($conversation, $question, $inbound);
    }

    /**
     * 接待态回读（镜像可能陈旧时）
     *
     * 信任本地镜像的**唯一**安全前提：本地是 AI 接待态（0/1）且本会话从未转人工。
     * 其余情况一律回读渠道，避免陈旧镜像把 AI 永久锁死：
     *   - 本地无镜像（state_sync 关闭 / 事件链路失败）
     *   - 本地是排队(2)/人工接待(3)：坐席可在企微客户端随时接管或放手，
     *     渠道可能已回到 0/1，不回读则本地 3 会静默陈旧 → AI 永久沉默
     *   - 本地是已结束（4）：客户新消息后渠道会回到待处理，不回读则 AI 永久沉默
     *   - 会话有过转人工记录（handoff_reason）：人工可能正在接待，不回读则 AI 会插话
     *
     * 回读不到且曾转人工 → **fail-closed**：把镜像暂写为「人工接待」，
     * 让 AI 保持沉默。宁可少答一次，不可在人工接待时插话（铁律）。
     * 代价是渠道临时不可用时这些会话会沉默；日志记录以便排查。
     */
    private function refreshStateIfStale(Conversation $conversation): void
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $state = $this->sessions->getState($conversation);
        $hadHandoff = isset($metadata['handoff_reason']);

        // 只有「AI 正在接待(0/1) 且 从未转人工」才信任本地；镜像是 2/3/4 或无镜像一律回读
        if (($state === 0 || $state === 1) && ! $hadHandoff) {
            return;
        }

        $read = $this->handoff->syncState($conversation);

        if ($read !== null) {
            return;
        }

        if ($hadHandoff) {
            Log::warning('[ServiceDesk] 曾转人工但渠道接待态读取失败，按人工接待处理（AI 不插话）', [
                'conversation_id' => $conversation->conversation_id,
                'state' => $state,
            ]);

            // 3 = 人工接待（与 WechatWorkApiClient::KF_STATE_HUMAN 对齐）
            $this->sessions->markState($conversation, 3);
        }
    }

    /**
     * 人工接待中：机器人侧**不发消息**（一会话只记录一次）
     *
     * 会话在「待接入池 / 人工接待」态时，企微禁止用 kf/send_msg 普通接口主动发消息
     * （errcode 95018：会话状态不允许发送），而这条提示由普通入站消息触发、
     * 没有可用的事件 code，因此它**永远发不出去**——过去每条消息都尝试一次，
     * 只留下 95018 噪音，还把 human_serving_notice_at 标成「已发」（其实没发）。
     * 人工态下用户由坐席在企微客户端回复，机器人不该、也无法再插一句。
     */
    private function noticeHumanServing(Conversation $conversation): void
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];

        if (isset($metadata['human_serving_notice_at'])) {
            return;
        }

        // 仅作「已确认一次」的去重标记，不代表真的发出了消息（发不出去，见方法注释）
        $conversation->metadata = $metadata + ['human_serving_notice_at' => now()->toIso8601String()];
        $conversation->save();

        Log::info('[ServiceDesk] 人工接待中，机器人侧不发消息（企微 95018 限制），仅记录一次', [
            'conversation_id' => $conversation->conversation_id,
        ]);
    }

    /**
     * 自动招呼：会话首次用户消息时立即发送，不等 AI 生成完成
     *
     * 降低用户等待焦虑：AI 应答需经 RAG + 模型推理，耗时可达数十秒；
     * 先即时发一句「正在为您查询」，用户知道系统已收到。
     * 只在会话维度触发一次（metadata['greeting_sent'] 去重）。
     */
    private function sendAutoGreeting(Conversation $conversation): void
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];

        if (isset($metadata['greeting_sent'])) {
            return;
        }

        $enabled = (bool) $this->settings->getForConversation($conversation, 'reply.auto_greeting', true);

        if (! $enabled) {
            // 关闭时也打标，避免每次消息都进这个分支查询
            $conversation->metadata = $metadata + ['greeting_sent' => false];
            $conversation->save();

            return;
        }

        $text = (string) $this->settings->getForConversation(
            $conversation,
            'reply.greeting_text',
            '您好，我是智能客服助手，正在为您查询，请稍候…',
        );

        $this->reply($conversation, $text);

        // 重新读取 metadata（reply 内 markFirstResponse 可能已修改）
        $conversation->refresh();
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $conversation->metadata = $metadata + ['greeting_sent' => now()->toIso8601String()];
        $conversation->save();
    }

    /**
     * 风险命中：block 拒答 / escalate 强制转人工
     */
    private function handleRisk(Conversation $conversation, RiskVerdict $verdict, Message $inbound): void
    {
        $this->markRisk($conversation, $verdict, $inbound);

        if ($verdict->shouldBlock()) {
            $this->reply($conversation, $verdict->message
                ?? (string) config('service-desk.reply.risk_block_notice', '抱歉，这个我帮不了您，请联系工作人员。'));

            return;
        }

        $this->escalate(
            conversation: $conversation,
            reason: HandoffService::REASON_RISK,
            verdict: $verdict,
        );
    }

    /**
     * 普通转人工（用户请求 / 轮次超限）
     */
    private function requestHandoff(Conversation $conversation, string $reason): void
    {
        $this->escalate($conversation, $reason);
    }

    /**
     * 转人工统一出口（所有触发条件都走这里）
     *
     * 顺序不可换：先转渠道接待态 → 再通知到人 → 再补齐坐席所需（摘要、工单）→ 最后答复用户。
     *
     * 「通知」与「渠道流转」刻意解耦且**都无条件执行**：渠道流转失败也要把人叫来
     * （风险场景尤其如此），把通知绑在流转成功分支里会得到「渠道一挂、人也不知道」。
     *
     * 答复用户时按流转结果给不同文案：没转成功就说没转成功 —— 假装已转接会让用户干等。
     */
    private function escalate(
        Conversation $conversation,
        string $reason,
        ?RiskVerdict $verdict = null,
    ): void {
        $synced = $this->handoff->toHuman($conversation, $reason);

        $notify = $verdict?->notify ?? [];

        // 先备好坐席所需（摘要 / 建议话术 / 工单，失败不影响转人工本身），
        // 再通知 —— 这样通知正文里能带上建议话术，坐席在企微里就能直接用
        $this->escalations->escalate($conversation, $reason, $notify, $verdict);

        $this->notifyHumans($conversation, $reason, $verdict, $notify);

        if (! $synced) {
            Log::error('[ServiceDesk] 转人工：渠道流转失败，已仅完成通知', [
                'conversation_id' => $conversation->conversation_id,
                'reason' => $reason,
            ]);
        }

        // 文案分支：渠道**没**转成功时，拦截器自带的话术也必须让位 ——
        // 否则用户收到「已为您转接」，实际却没人在（自己举手反而更糟）。
        if (! $synced) {
            $this->reply($conversation, (string) config(
                'service-desk.reply.handoff_failed_notice',
                '抱歉，人工客服暂时未能接入，您可以继续问我，或稍后再试。',
            ));

            return;
        }

        // 转接成功后会话已在渠道侧进入排队/人工态：普通 kf/send_msg 会被 95018 拒，
        // 必须用转接返回的 msg_code 经 kf/send_msg_on_event 下发（HandoffService 已记进 metadata）。
        $this->reply(
            $conversation,
            $verdict?->message ?? (string) $this->settings->getForConversation(
                $conversation,
                'reply.handoff_notice',
                '已为您转人工，正在排队等待客服接入，可能需要稍等，请耐心等待；非工作时间将顺延。',
            ),
            $this->handoffMsgCode($conversation),
        );
    }

    /**
     * 转接返回的 msg_code（HandoffService::recordHandoff 写进 conversations.metadata）
     *
     * 企微在会话状态变更为待接入池/人工接待时返回一个一次性 code，只能用它经
     * kf/send_msg_on_event 下发消息；这些态下普通 kf/send_msg 会被 95018 拒。
     */
    private function handoffMsgCode(Conversation $conversation): ?string
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $code = $metadata['handoff_msg_code'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    /**
     * 走 UserAiRuntime 应答
     */
    private function answer(Conversation $conversation, string $question, Message $inbound): void
    {
        $tenantId = (int) $conversation->tenant_id;

        try {
            $result = $this->runtime->ask(
                question: $question,
                tenantId: $tenantId,
                visitorKey: $this->visitorKey($conversation, $inbound),
                history: $this->history($conversation, $inbound),
                context: $this->aiContext($conversation),
            );
        } catch (Throwable $e) {
            // AI 可选性铁律：AI 不可用时降级为转人工，而不是把错误抛给用户
            Log::error('[ServiceDesk] AI 应答异常，降级转人工', [
                'conversation_id' => $conversation->conversation_id,
                'error' => $e->getMessage(),
            ]);

            $this->requestHandoff($conversation, HandoffService::REASON_AI_UNAVAILABLE);

            return;
        }

        // 命中知识库：清零未解决计数，正常回复
        if (($result['sources'] ?? []) !== []) {
            $this->sessions->resetUnresolved($conversation);
            $this->reply($conversation, (string) ($result['answer'] ?? ''));

            return;
        }

        // 未命中知识库：累加未解决计数（供 max_unresolved_turns 兜底），再按租户配置决定处理方式
        $this->sessions->incrementUnresolved($conversation);

        // 自动模式：首轮未命中即转人工，不等用户确认
        if ((bool) $this->settings->getForConversation($conversation, 'handoff.auto_on_no_answer', false)) {
            $this->requestHandoff($conversation, HandoffService::REASON_UNRESOLVED_TURNS);

            return;
        }

        // 提醒模式（默认）：优先采纳模型的引导回复（UserAi 层无资料时会接住寒暄并追问
        // 澄清诉求），仅当模型空回复时，才退回可配的「提醒转人工」文案 reply.no_answer。
        $guidance = trim((string) ($result['answer'] ?? ''));

        if ($guidance !== '') {
            $this->reply($conversation, $guidance);

            return;
        }

        $noAnswer = trim((string) $this->settings->getForConversation($conversation, 'reply.no_answer', ''));

        $this->reply($conversation, $noAnswer);
    }

    /**
     * 组装对外问答上下文：身份等级 + 客服 Agent 的人设与模型档位
     *
     * 等级由服务端判定（会话已关联用户 → authenticated；已核身 → verified），
     * 经执行咽喉决定哪些工具可达 —— 与客户端输入完全无关。
     *
     * 人设来自租户配置的客服 Agent（`effectiveSystemPrompt()`，含运行时日期注入）；
     * 未配置客服 Agent 时传 null，框架用内置提示词（不配也能用）。
     */
    private function aiContext(Conversation $conversation): UserAiContext
    {
        $agent = $this->agents->resolveFor($conversation);

        return new UserAiContext(
            accessLevel: $this->accessLevels->levelFor($conversation),
            actorId: $conversation->created_by !== null ? (string) $conversation->created_by : null,
            persona: $agent?->effectiveSystemPrompt(),
            // Agent 的配置词表（preferred_model/preferred_provider）与模型调用的选项名
            // （model/provider）不同，映射放在这里 —— UserAi 层不该认识 Agent 的字段名
            modelOptions: $agent !== null ? $this->modelOptionsFrom($agent) : [],
            toolScope: $agent !== null ? $this->toolScopeFrom($agent) : null,
        );
    }

    /**
     * Agent 的模型档位 → 模型调用选项
     *
     * @return array<string, mixed>
     */
    private function modelOptionsFrom(Agent $agent): array
    {
        $config = $this->chatClient->resolveModelConfig($agent);

        return array_filter([
            'model' => $config['preferred_model'] ?? null,
            'provider' => $config['preferred_provider'] ?? null,
            'temperature' => $config['temperature'] ?? null,
            'max_tokens' => $config['max_tokens'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    /**
     * 客服 Agent 的工具范围 = Agent 声明的工具 ∩ 暴露面白名单
     *
     * 与暴露面的关系是**交集，不是替代**：白名单是硬边界（外部主体永远够不到未登记的工具），
     * Agent 只能在边界之内再缩范围。这样「按 Agent 收窄工具面」这个动作就不会
     * 变成一条新的越权通道。
     *
     * 空列表的语义：Agent 一个工具都没声明 → 收窄为空（对该 Agent 而言无工具可用）。
     * 但**未配置客服 Agent 时返回 null**（不做额外限制），保持改造前的行为。
     *
     * @return array<int, string>|null
     */
    private function toolScopeFrom(Agent $agent): ?array
    {
        $allowed = array_keys((array) config('user-ai.tool_surface.allowed', []));
        $declared = $agent->effectiveTools();

        return array_values(array_intersect($allowed, $declared));
    }

    /**
     * 发送一条客服回复（落库 + 出站）
     *
     * 回复以 sender_type=agent + metadata.sender_kind='ai' 入库：这样会话记录里
     * 「AI 说的」与「人工说的」可区分（设计 §5.1），质检与审计都要靠这个区分。
     *
     * @param  string|null  $eventCode  转接返回的 msg_code；非空时走 kf/send_msg_on_event
     *                                  （会话已转入排队/人工态，普通 kf/send_msg 会被 95018 拒）
     */
    private function reply(Conversation $conversation, string $content, ?string $eventCode = null): void
    {
        $content = trim($content);

        if ($content === '') {
            return;
        }

        $driver = $this->supportDriver($conversation);

        if ($driver === null) {
            Log::warning('[ServiceDesk] 无法回复：渠道不支持客服语义', [
                'conversation_id' => $conversation->conversation_id,
                'channel' => (string) $conversation->channel,
            ]);

            return;
        }

        try {
            $sent = $eventCode !== null && $eventCode !== ''
                ? $driver->sendEventReply($eventCode, $content)
                : $driver->sendMessage($conversation, ['msgtype' => 'text', 'text' => ['content' => $content]]);
        } catch (Throwable $e) {
            Log::error('[ServiceDesk] 回复发送失败', [
                'conversation_id' => $conversation->conversation_id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (! $sent) {
            Log::warning('[ServiceDesk] 回复未被渠道接受', [
                'conversation_id' => $conversation->conversation_id,
            ]);

            return;
        }

        Message::create([
            'conversation_id' => $conversation->conversation_id,
            'tenant_id' => (int) $conversation->tenant_id,
            'sender_id' => null,
            'sender_type' => 'agent',
            'type' => 'text',
            'content' => $content,
            'metadata' => [
                'channel' => (string) $conversation->channel,
                // AI 与人工的区分口径（人工发送由企微侧完成，origin=5，不入库）
                'sender_kind' => 'ai',
            ],
        ]);

        $this->markFirstResponse($conversation);
    }

    /**
     * 记录首次响应时刻（度量「首次响应时长」的起点是会话的 queued_at）
     *
     * ⚠ 口径限制：这里记的是 **AI 侧**首次响应。人工回复由坐席在企微工作台发出，
     * 渠道回调的 origin=5 目前被 fetcher 过滤掉、不入库，因此人工响应时间本地看不到。
     * 要让「首次响应」包含人工，需把 origin=5 也入库 —— 那会改变会话记录的构成，
     * 属产品决策（同时影响会话摘要与质检的输入）。
     */
    private function markFirstResponse(Conversation $conversation): void
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];

        if (isset($metadata['first_response_at'])) {
            return;
        }

        $conversation->metadata = $metadata + ['first_response_at' => now()->toIso8601String()];
        $conversation->save();
    }

    /**
     * 记录风险标记（审计与质检用）
     */
    private function markRisk(Conversation $conversation, RiskVerdict $verdict, Message $inbound): void
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];

        // 覆盖写：同一会话可能多次命中风险，看板要的是**最新**一次，不是第一次
        $metadata['risk_flag'] = [
            'level' => $verdict->level,
            'reason' => $verdict->reason,
            'flagged_at' => now()->toIso8601String(),
        ];

        $conversation->metadata = $metadata;
        $conversation->save();

        $messageMetadata = is_array($inbound->metadata) ? $inbound->metadata : [];

        $inbound->metadata = $messageMetadata + [
            'risk_flag' => [
                'level' => $verdict->level,
                'reason' => $verdict->reason,
            ],
        ];
        $inbound->save();

        Log::warning('[ServiceDesk] 命中风险拦截', [
            'conversation_id' => $conversation->conversation_id,
            'level' => $verdict->level,
            'reason' => $verdict->reason,
        ]);

        // 规则打标（打标计划 T5）：与风险拦截是同一件事的两面。
        // 放在 markRisk 末尾、且 fail-open —— 转人工/通知主链路不能被标签拖住。
        $this->ruleTagging?->tagFromRisk(
            (int) $conversation->tenant_id,
            (int) $conversation->conversation_id,
            $verdict->reason,
            $verdict->tag,
        );
    }

    /**
     * 广播「请人工介入」（含通知对象）
     */
    private function notifyHumans(
        Conversation $conversation,
        string $reason,
        ?RiskVerdict $verdict,
        array $notify,
    ): void {
        SupportHandoffRequested::dispatch(
            conversation: $conversation,
            reason: $reason,
            notify: $notify,
            verdict: $verdict,
        );
    }

    /**
     * 用户是否在要求转人工
     */
    private function wantsHuman(Conversation $conversation, string $question): bool
    {
        if (! (bool) $this->settings->get((int) $conversation->tenant_id, 'handoff.allow_visitor_request', true)) {
            return false;
        }

        foreach (self::HUMAN_REQUEST_KEYWORDS as $keyword) {
            if (mb_stripos($question, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * 会话历史（供多轮应答）
     *
     * 取最近 N 条，倒序后正序；排除本轮消息（它作为 question 单独传）。
     *
     * @return array<int, array{role: string, content: string}>
     */
    private function history(Conversation $conversation, Message $inbound): array
    {
        $limit = max(0, (int) $this->settings->getForConversation($conversation, 'reply.history_turns', 6));

        if ($limit === 0) {
            return [];
        }

        // 会话内顺序的事实源是 sequence（HasConversationSequence 在 creating 时分配，
        // (conversation_id, sequence) 唯一）。message_id 是 HasGlobalId 的**随机** ID，
        // 用它排序等于随机顺序 —— 曾经错在这里。不加次级键：sequence 本身在会话内唯一。
        $rows = Message::withoutGlobalScope(TenantScope::class)
            ->where('conversation_id', $conversation->conversation_id)
            ->where('message_id', '!=', $inbound->message_id)
            ->orderByDesc('sequence')
            ->limit($limit)
            ->get()
            ->reverse();

        $history = [];

        foreach ($rows as $row) {
            $content = trim((string) $row->content);

            if ($content === '') {
                continue;
            }

            // system（接待态通知、系统播报等）不是对话内容：塞进多轮历史
            // 会被模型当成「用户说过的话」。UserAiRuntime 也会滤掉越界角色，
            // 但在这里就过滤掉，避免把噪声带给下游。
            if ($row->sender_type === 'system') {
                continue;
            }

            $history[] = [
                'role' => $row->sender_type === 'agent' ? 'assistant' : 'user',
                'content' => $content,
            ];
        }

        return $history;
    }

    /**
     * 访客标识（审计关联用）：优先系统用户，其次渠道身份
     */
    private function visitorKey(Conversation $conversation, Message $inbound): ?string
    {
        if ($conversation->created_by !== null) {
            return (string) $conversation->created_by;
        }

        $metadata = is_array($inbound->metadata) ? $inbound->metadata : [];

        return isset($metadata['external_from']) ? (string) $metadata['external_from'] : null;
    }

    private function supportDriver(Conversation $conversation): ?SupportChannelContract
    {
        $channel = (string) ($conversation->channel ?? '');
        $tenantId = (int) $conversation->tenant_id;

        if ($channel === '' || $tenantId <= 0 || ! $this->channels->hasDriver($channel)) {
            return null;
        }

        try {
            $driver = $this->channels->resolve($channel, $tenantId);
        } catch (Throwable) {
            return null;
        }

        return $driver instanceof SupportChannelContract ? $driver : null;
    }
}
