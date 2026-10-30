<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\Conversation\Services\ConversationSummaryService;
use MultiTenantSaas\Modules\ServiceDesk\Dto\RiskVerdict;
use MultiTenantSaas\Modules\ServiceDesk\Exceptions\EscalationSourceConflictException;
use MultiTenantSaas\Modules\Ticket\Models\Ticket;
use MultiTenantSaas\Modules\Ticket\Services\TicketService;
use Throwable;

/**
 * 转人工后的闭环：把「人工接手需要什么」准备好
 *
 * 转人工本身只是把人叫过来，坐席接手时还缺两样东西，本服务负责补齐：
 *
 *   1. **会话摘要**（`conversations.summary`）—— 坐席最需要的是「这段会话讲了什么」，
 *      而不是自己翻几十条消息。复用已有的 ConversationSummaryService。
 *   2. **工单**（未解决时）—— 人工队列不该是漏斗底部：AI 答不了、转人工又没接住的，
 *      要有可跟进、可统计的落点。
 *
 * 两件都是**尽力而为**，任一失败都不得影响「已经转人工」这个事实（fail-open）：
 * 摘要失败只是坐席要自己翻消息，建单失败只是少一条跟进记录，都比整个转人工流程
 * 报错好得多。
 *
 * 建单幂等（BL-180 P2 / G2，`existingOrCreated` 语义）：
 *   - 来源唯一键 = (tenant_id, conversation_id, escalation_key)，由
 *     {@see EscalationSourceLedger} 的账本表唯一约束兜底；省略 key 时用默认键
 *     `handoff`，沿用「一个会话一张转人工工单」的历史语义。
 *   - 认领来源、建单、绑定 ticket_id、回写会话 metadata **在同一个数据库事务内**完成；
 *     事务内不调用外部网络（摘要/建议话术在事务外先做完），任一环节失败整体回滚 ——
 *     建单后关联前中断不会留下孤儿工单，重试会补出且只补出一张。
 *   - 重复调用、并发 worker 都读回同一张工单；同一来源键但请求内容不同（reason 变化）
 *     抛 {@see EscalationSourceConflictException}（reason = escalation_source_conflict，
 *     409），不静默复用旧单。
 *   - 历史 metadata 里已有 `ticket_id` 的会话不重建工单：按 existingOrCreated 返回既有
 *     工单，并把该关联增量登记进来源账本（后续同键不同请求才可被拒绝）。
 *   - 来源账本表缺失（迁移未执行）时退回既有 metadata 幂等路径并留告警，不静默丢单。
 */
class SupportEscalationService
{
    /** 转人工原因 → 工单优先级 */
    private const PRIORITY_BY_REASON = [
        'risk' => 'urgent',
        'unresolved_turns' => 'high',
        'ai_unavailable' => 'high',
        'visitor_request' => 'medium',
    ];

    /** 原因码 → 人话（写进工单主题，供坐席一眼扫） */
    private const REASON_LABELS = [
        'risk' => '风险内容需人工介入',
        'unresolved_turns' => '连续多轮未解决',
        'ai_unavailable' => 'AI 不可用',
        'visitor_request' => '用户主动要求转人工',
    ];

    /**
     * 会话 metadata 里的「来源账本已接管」标记。
     *
     * 用于区分「升级前就存在的历史 ticket_id」（要复用、要登记）与「本服务按来源键
     * 建过的工单」（键作用域语义，不能被新的来源键当成历史关联复用）。
     */
    private const METADATA_LEDGER_MARKER = 'escalation_source_ledger';

    private const METADATA_LEDGER_MARKER_VERSION = 1;

    public function __construct(
        private readonly ConversationSummaryService $summaries,
        private readonly SupportAssistService $assist,
        private readonly TicketService $tickets,
        private readonly ServiceDeskSettings $settings,
        private readonly EscalationSourceLedger $ledger,
    ) {}

    /**
     * 执行闭环
     *
     * @param  array<int, string|int>  $notify  通知对象标识；其中数值型（已解析好的用户 ID）
     *                                          会被指派为工单处理人
     * @param  string|null  $escalationKey  来源唯一键（同一会话内区分不同转人工来源）；
     *                                      省略时使用默认键 `handoff`
     * @return Ticket|null 建出或复用的工单；未启用 / 失败时 null（existingOrCreated）
     *
     * @throws EscalationSourceConflictException 同一来源键承载了不同请求
     * @throws DomainException 来源键非法（reason 前缀 escalation_key_invalid）
     */
    public function escalate(
        Conversation $conversation,
        string $reason,
        array $notify = [],
        ?RiskVerdict $verdict = null,
        ?string $escalationKey = null,
    ): ?Ticket {
        // 顺序有依赖：先摘要（坐席要「讲了什么」）→ 再据摘要生成建议话术 → 最后建单
        // （单子描述里同时带上两者，工单阅读者不必再回 console）。
        // 摘要/建议是外部 AI 调用，必须留在建单事务之外：事务内不调外部网络。
        $this->refreshSummary($conversation);

        $suggestions = $this->assist->suggestReplies($conversation->refresh());

        return $this->openTicket($conversation, $reason, $notify, $verdict, $suggestions, $escalationKey);
    }

    /**
     * 刷新会话摘要（坐席接手时最需要「讲了什么」）
     */
    private function refreshSummary(Conversation $conversation): void
    {
        if (! (bool) $this->settings->getForConversation($conversation, 'handoff.summary_on_handoff', true)) {
            return;
        }

        try {
            $this->summaries->generateSummary([
                'conversation_id' => (string) $conversation->conversation_id,
                'tenant_id' => (int) $conversation->tenant_id,
            ]);
        } catch (Throwable $e) {
            Log::warning('[ServiceDesk] 转人工时生成会话摘要失败（不影响转人工）', [
                'conversation_id' => $conversation->conversation_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 建工单（幂等：同一来源键最多一张）
     *
     * @param  array<int, string>  $suggestions
     */
    private function openTicket(
        Conversation $conversation,
        string $reason,
        array $notify,
        ?RiskVerdict $verdict,
        array $suggestions = [],
        ?string $escalationKey = null,
    ): ?Ticket {
        if (! (bool) $this->settings->getForConversation($conversation, 'handoff.ticket_enabled', true)) {
            return null;
        }

        $tenantId = (int) $conversation->tenant_id;
        $conversationId = (int) $conversation->conversation_id;

        if ($tenantId <= 0 || $conversationId <= 0) {
            Log::error('[ServiceDesk] 转人工建单跳过：会话缺少有效 tenant_id / conversation_id', [
                'conversation_id' => $conversation->conversation_id,
                'tenant_id' => $conversation->tenant_id,
            ]);

            return null;
        }

        // 跨租户防御：上下文租户与会话租户不一致时不得按上下文租户建单
        // （TicketService::create 取的是上下文租户）。无上下文仍按既有行为继续。
        $contextTenantId = TenantContext::getId();

        if ($contextTenantId !== null && (int) $contextTenantId !== $tenantId) {
            Log::error('[ServiceDesk] 转人工建单跳过：上下文租户与会话租户不一致', [
                'conversation_id' => $conversationId,
                'conversation_tenant_id' => $tenantId,
                'context_tenant_id' => (int) $contextTenantId,
            ]);

            return null;
        }

        // 键合法性先校验：空串/超长是调用方错误，不能静默退化成别的键。
        $escalationKey = $this->ledger->normalizeKey($escalationKey);

        if (! $this->ledger->available()) {
            return $this->openTicketWithoutSourceLedger($conversation, $reason, $notify, $verdict, $suggestions);
        }

        $fingerprint = $this->ledger->fingerprint($escalationKey, $reason);

        try {
            return DB::transaction(function () use (
                $tenantId,
                $conversationId,
                $reason,
                $notify,
                $verdict,
                $suggestions,
                $escalationKey,
                $fingerprint,
            ): ?Ticket {
                $locked = $this->lockConversation($tenantId, $conversationId);
                $link = $this->conversationLink($locked);

                if ($this->ledger->claim($tenantId, $conversationId, $escalationKey, $fingerprint)) {
                    return $this->createAndBind(
                        $locked,
                        $link['legacy'],
                        $link['replace_link'],
                        $reason,
                        $notify,
                        $verdict,
                        $suggestions,
                        $escalationKey,
                    );
                }

                // 键已存在：并发 worker / 重试。读回先到者的行，按指纹判定「同一请求」还是冲突。
                $source = $this->ledger->lock($tenantId, $conversationId, $escalationKey);

                if ($source === null) {
                    throw new DomainException(sprintf(
                        '转人工来源账本行在事务内消失：tenant=%d conversation=%d key=%s',
                        $tenantId,
                        $conversationId,
                        $escalationKey,
                    ));
                }

                if (! hash_equals((string) $source->request_fingerprint, $fingerprint)) {
                    throw new EscalationSourceConflictException(
                        $escalationKey,
                        $source->ticket_id === null ? null : (int) $source->ticket_id,
                        (string) $source->request_fingerprint,
                        $fingerprint,
                    );
                }

                if ($source->ticket_id !== null) {
                    $ticket = $this->findTicket($tenantId, (int) $source->ticket_id);

                    if ($ticket === null) {
                        // 关联指向不可见工单（硬删/跨租户）：返回 null 而不是再建一张重复工单
                        Log::warning('[ServiceDesk] 转人工来源账本指向的工单不可见，不重复建单', [
                            'conversation_id' => $conversationId,
                            'ticket_id' => (int) $source->ticket_id,
                            'escalation_key' => $escalationKey,
                        ]);
                    }

                    return $ticket;
                }

                // 可恢复的半截来源（历史中断留下的「已认领、未绑定」行）：补建并绑定
                return $this->createAndBind(
                    $locked,
                    $link['legacy'],
                    $link['replace_link'],
                    $reason,
                    $notify,
                    $verdict,
                    $suggestions,
                    $escalationKey,
                );
            }, 3);
        } catch (EscalationSourceConflictException $exception) {
            // 显式拒绝：不吞、不静默复用
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('[ServiceDesk] 转人工时建工单失败（不影响转人工）', [
                'conversation_id' => $conversation->conversation_id,
                'reason' => $reason,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 账本表缺失（迁移未执行）时的兼容路径：保留既有 metadata 幂等语义。
     *
     * 该路径没有唯一键兜底（并发仍可能重复），只作为裁剪部署/未迁移库的过渡；
     * 正常情况下 openTicket() 永远走账本路径。
     *
     * @param  array<int, string>  $suggestions
     */
    private function openTicketWithoutSourceLedger(
        Conversation $conversation,
        string $reason,
        array $notify,
        ?RiskVerdict $verdict,
        array $suggestions,
    ): ?Ticket {
        $tenantId = (int) $conversation->tenant_id;
        $conversationId = (int) $conversation->conversation_id;

        Log::warning('[ServiceDesk] 转人工来源账本表缺失（迁移未执行），建单退回 metadata 幂等路径', [
            'conversation_id' => $conversationId,
            'tenant_id' => $tenantId,
        ]);

        try {
            return DB::transaction(function () use ($tenantId, $conversationId, $reason, $notify, $verdict, $suggestions): ?Ticket {
                $locked = $this->lockConversation($tenantId, $conversationId);

                // 该路径没有账本，不能写「账本已接管」标记：否则迁移执行后会被误判为
                // 账本管理的会话，历史 metadata 关联就再也不被复用。
                $legacy = $this->metadataTicket($locked);

                if ($legacy !== null) {
                    return $legacy;
                }

                $ticket = $this->tickets->create($this->ticketAttributes($locked, $reason, $notify, $verdict, $suggestions));

                $this->linkConversation($locked, $ticket, replaceLink: true, ledgerManaged: false);

                return $ticket;
            }, 3);
        } catch (Throwable $exception) {
            Log::error('[ServiceDesk] 转人工时建工单失败（不影响转人工）', [
                'conversation_id' => $conversationId,
                'reason' => $reason,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    // ─────────────────────── 事务内组装 ───────────────────────

    /**
     * 建单并绑定来源（调用方事务内执行；本方法不调外部网络）。
     *
     * @param  Ticket|null  $legacy  历史 metadata 关联的既有工单（有则复用，不重建）
     * @param  array<int, string>  $suggestions
     */
    private function createAndBind(
        Conversation $locked,
        ?Ticket $legacy,
        bool $replaceLink,
        string $reason,
        array $notify,
        ?RiskVerdict $verdict,
        array $suggestions,
        string $escalationKey,
    ): Ticket {
        $tenantId = (int) $locked->tenant_id;
        $conversationId = (int) $locked->conversation_id;

        if ($legacy !== null) {
            // 历史关联不重建：登记进来源账本后返回既有工单（existingOrCreated）
            $this->ledger->bind($tenantId, $conversationId, $escalationKey, (int) $legacy->ticket_id);
            $this->linkConversation($locked, $legacy, replaceLink: false);

            Log::info('[ServiceDesk] 转人工复用历史 metadata 关联工单并登记来源账本', [
                'conversation_id' => $conversationId,
                'ticket_id' => (int) $legacy->ticket_id,
                'escalation_key' => $escalationKey,
            ]);

            return $legacy;
        }

        $ticket = $this->tickets->create($this->ticketAttributes($locked, $reason, $notify, $verdict, $suggestions));

        $this->ledger->bind($tenantId, $conversationId, $escalationKey, (int) $ticket->ticket_id);
        $this->linkConversation($locked, $ticket, $replaceLink);

        return $ticket;
    }

    /**
     * 锁住会话行并读回最新 metadata：并发 worker 在这里串行化，关联回写不会互相覆盖。
     */
    private function lockConversation(int $tenantId, int $conversationId): Conversation
    {
        $locked = Conversation::query()
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $conversationId)
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            throw new DomainException(sprintf(
                '转人工建单时找不到会话行：tenant=%d conversation=%d',
                $tenantId,
                $conversationId,
            ));
        }

        return $locked;
    }

    /**
     * 判定会话上的既有工单关联属于「历史 metadata」还是「已被来源账本接管」。
     *
     * @return array{legacy: Ticket|null, replace_link: bool}
     */
    private function conversationLink(Conversation $conversation): array
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $linked = $this->metadataTicket($conversation);

        if ((int) ($metadata[self::METADATA_LEDGER_MARKER] ?? 0) >= self::METADATA_LEDGER_MARKER_VERSION) {
            // 账本已接管：metadata 的 ticket_id 只是「会话首个工单」的展示链接，不再当历史来源
            return ['legacy' => null, 'replace_link' => $linked === null];
        }

        if ($linked !== null) {
            return ['legacy' => $linked, 'replace_link' => false];
        }

        return ['legacy' => null, 'replace_link' => true];
    }

    /**
     * 读取 metadata.ticket_id 指向的**同租户**工单；不存在/跨租户/已删除一律视为不可用。
     */
    private function metadataTicket(Conversation $conversation): ?Ticket
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $linkedId = $metadata['ticket_id'] ?? null;

        if (! is_numeric($linkedId)) {
            return null;
        }

        $ticket = $this->findTicket((int) $conversation->tenant_id, (int) $linkedId);

        if ($ticket === null) {
            Log::warning('[ServiceDesk] 会话 metadata 的 ticket_id 不可用（不存在/跨租户/已删除），按无历史关联处理', [
                'conversation_id' => (int) $conversation->conversation_id,
                'tenant_id' => (int) $conversation->tenant_id,
                'metadata_ticket_id' => $linkedId,
            ]);
        }

        return $ticket;
    }

    /**
     * 回写「会话首张工单」的展示链接 + 账本接管标记。
     *
     * 历史关联（$replaceLink = false）保留原 ticket_id 与 ticket_opened_at，不改写旧事实。
     * $ledgerManaged = false 用于账本表缺失的过渡路径：这时不能写接管标记，否则
     * 迁移执行后会把历史 metadata 关联误判成账本管理，导致重复建单。
     */
    private function linkConversation(
        Conversation $conversation,
        Ticket $ticket,
        bool $replaceLink,
        bool $ledgerManaged = true,
    ): void {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];

        if ($replaceLink) {
            $metadata['ticket_id'] = $ticket->ticket_id;
            $metadata['ticket_opened_at'] = now()->toIso8601String();
        }

        if ($ledgerManaged) {
            $metadata[self::METADATA_LEDGER_MARKER] = self::METADATA_LEDGER_MARKER_VERSION;
        } else {
            unset($metadata[self::METADATA_LEDGER_MARKER]);
        }

        $conversation->metadata = $metadata;
        $conversation->save();
    }

    /**
     * @param  array<int, string|int>  $notify
     * @param  array<int, string>  $suggestions
     * @return array<string, mixed>
     */
    private function ticketAttributes(
        Conversation $conversation,
        string $reason,
        array $notify,
        ?RiskVerdict $verdict,
        array $suggestions,
    ): array {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];

        return [
            'subject' => $this->subject($reason),
            'description' => $this->description($conversation, $reason, $verdict, $metadata, $suggestions),
            'priority' => self::PRIORITY_BY_REASON[$reason] ?? 'medium',
            // 用原因码当分类，便于按「为什么转人工」筛选与统计
            'category' => $reason,
            // 归属：学生本人（身份桥接后的 users.user_id）；未关联则为 null
            'created_by' => $conversation->created_by,
            // 通知对象里的数值条目即已解析好的用户 ID，直接指派给他
            'assigned_to' => $this->firstNumericTarget($notify),
        ];
    }

    /**
     * 按租户显式收窄的工单读取：跨租户（或已删除）一律视为不可见。
     */
    private function findTicket(int $tenantId, int $ticketId): ?Ticket
    {
        if ($tenantId <= 0 || $ticketId <= 0) {
            return null;
        }

        return Ticket::query()
            ->where('tenant_id', $tenantId)
            ->where('ticket_id', $ticketId)
            ->first();
    }

    private function subject(string $reason): string
    {
        $label = self::REASON_LABELS[$reason] ?? '需人工跟进';

        return "客服会话{$label}";
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  array<int, string>  $suggestions
     */
    private function description(
        Conversation $conversation,
        string $reason,
        ?RiskVerdict $verdict,
        array $metadata,
        array $suggestions = [],
    ): string {
        $lines = [
            '来源：智能客服转人工',
            '会话 ID：' . $conversation->conversation_id,
            '渠道：' . (string) ($conversation->channel ?? ''),
            '原因：' . (self::REASON_LABELS[$reason] ?? $reason) . "（{$reason}）",
            '未解决轮数：' . (int) ($metadata['service_unresolved_turns'] ?? 0),
        ];

        if ($conversation->created_by !== null) {
            $lines[] = '学生用户 ID：' . $conversation->created_by;
        }

        if ($verdict !== null) {
            $lines[] = '风险判定：' . $verdict->level . ' / ' . $verdict->reason;
        }

        // 摘要内嵌：工单阅读者未必会去 console 打开会话
        $summary = trim((string) ($conversation->summary ?? ''));

        if ($summary !== '') {
            $lines[] = '';
            $lines[] = '【会话摘要】';
            $lines[] = mb_substr($summary, 0, 1000);
        }

        if ($suggestions !== []) {
            // 建议话术直接放在工单里：坐席在企微工作台，回 console 看会话不现实
            $lines[] = '';
            $lines[] = '【建议话术】';
            foreach ($suggestions as $i => $suggestion) {
                $lines[] = ($i + 1) . '. ' . $suggestion;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string|int>  $notify
     */
    private function firstNumericTarget(array $notify): ?int
    {
        foreach ($notify as $target) {
            if (is_int($target) || (is_string($target) && ctype_digit($target))) {
                return (int) $target;
            }
        }

        return null;
    }
}
