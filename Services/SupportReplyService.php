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
        private readonly AgentChatClient $chatClient,
        private readonly UserAiRuntime $runtime,
        private readonly ChannelManager $channels,
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
        // ── 1. 人工接待中：AI 不插话 ─────────────────────────────
        if (! $this->sessions->isAiServing($conversation)) {
            // 不回复是有意的：人工正在接待，AI 插一句会打断对话。
            // 但也**不发**「请稍候」之类的话 —— 人工本来就在，多一句是噪音。
            Log::info('[ServiceDesk] 人工接待中，AI 不插话', [
                'conversation_id' => $conversation->conversation_id,
                'service_state' => $this->sessions->getState($conversation),
            ]);

            return;
        }

        $question = trim((string) $inbound->content);

        if ($question === '') {
            return;
        }

        // ── 2. 风险拦截：强制转人工（优先于用户的请求） ───────────
        $verdict = $this->risk->inspect($conversation, $question);

        if ($verdict !== null) {
            $this->handleRisk($conversation, $verdict, $inbound);

            return;
        }

        // ── 3. 用户主动要求转人工 ────────────────────────────────
        if ($this->wantsHuman($question)) {
            $this->requestHandoff($conversation, HandoffService::REASON_VISITOR_REQUEST);

            return;
        }

        // ── 4. 连续未命中达阈值 ─────────────────────────────────
        $maxUnresolved = max(1, (int) config('service-desk.handoff.max_unresolved_turns', 3));

        if ($this->sessions->getUnresolvedTurns($conversation) >= $maxUnresolved) {
            $this->requestHandoff($conversation, HandoffService::REASON_UNRESOLVED_TURNS);

            return;
        }

        // ── 5. AI 应答 ─────────────────────────────────────────
        $this->answer($conversation, $question, $inbound);
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

        // escalate：先转人工，再告知用户；**无论渠道流转是否成功都要通知到人**
        $synced = $this->handoff->toHuman($conversation, HandoffService::REASON_RISK);
        $this->notifyHumans($conversation, HandoffService::REASON_RISK, $verdict);

        $this->reply($conversation, $verdict->message
            ?? (string) config('service-desk.reply.handoff_notice', '已为您转接人工客服，请稍候。'));

        if (! $synced) {
            // 渠道没转成功但人已收到通知 —— 这正是把「通知」与「渠道流转」解耦的意义所在
            Log::error('[ServiceDesk] 风险转人工：渠道流转失败，已仅完成通知', [
                'conversation_id' => $conversation->conversation_id,
                'risk_reason' => $verdict->reason,
            ]);
        }
    }

    /**
     * 普通转人工（用户请求 / 轮次超限）
     */
    private function requestHandoff(Conversation $conversation, string $reason): void
    {
        $ok = $this->handoff->toHuman($conversation, $reason);

        $this->notifyHumans($conversation, $reason, null);

        $this->reply($conversation, $ok
            ? (string) config('service-desk.reply.handoff_notice', '已为您转接人工客服，请稍候。')
            // 转人工失败要如实告知，不能假装已转接 —— 否则用户就在那头干等
            : (string) config('service-desk.reply.handoff_failed_notice', '抱歉，人工客服暂时未能接入，您可以继续问我，或稍后再试。'));
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

        // 命中知识库则清零未解决计数，未命中则累加（下一轮可能触发转人工）
        if (($result['sources'] ?? []) === []) {
            $this->sessions->incrementUnresolved($conversation);
        } else {
            $this->sessions->resetUnresolved($conversation);
        }

        $this->reply($conversation, (string) ($result['answer'] ?? ''));
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
     * 发送一条客服回复（落库 + 出站）
     *
     * 回复以 sender_type=agent + metadata.sender_kind='ai' 入库：这样会话记录里
     * 「AI 说的」与「人工说的」可区分（设计 §5.1），质检与审计都要靠这个区分。
     */
    private function reply(Conversation $conversation, string $content): void
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
            $sent = $driver->sendMessage($conversation, ['msgtype' => 'text', 'text' => ['content' => $content]]);
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
    }

    /**
     * 记录风险标记（审计与质检用）
     */
    private function markRisk(Conversation $conversation, RiskVerdict $verdict, Message $inbound): void
    {
        $conversation->metadata = (is_array($conversation->metadata) ? $conversation->metadata : []) + [
            'risk_flag' => [
                'level' => $verdict->level,
                'reason' => $verdict->reason,
                'flagged_at' => now()->toIso8601String(),
            ],
        ];
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
    }

    /**
     * 广播「请人工介入」（含通知对象）
     */
    private function notifyHumans(Conversation $conversation, string $reason, ?RiskVerdict $verdict): void
    {
        SupportHandoffRequested::dispatch(
            conversation: $conversation,
            reason: $reason,
            notify: $verdict?->notify ?? [],
            verdict: $verdict,
        );
    }

    /**
     * 用户是否在要求转人工
     */
    private function wantsHuman(string $question): bool
    {
        if (! (bool) config('service-desk.handoff.allow_visitor_request', true)) {
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
        $limit = max(0, (int) config('service-desk.reply.history_turns', 6));

        if ($limit === 0) {
            return [];
        }

        $rows = Message::withoutGlobalScope(TenantScope::class)
            ->where('conversation_id', $conversation->conversation_id)
            ->where('message_id', '!=', $inbound->message_id)
            ->orderByDesc('message_id')
            ->limit($limit)
            ->get()
            ->reverse();

        $history = [];

        foreach ($rows as $row) {
            $content = trim((string) $row->content);

            if ($content === '') {
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
