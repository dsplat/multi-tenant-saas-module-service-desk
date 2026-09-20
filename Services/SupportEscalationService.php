<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\Conversation\Services\ConversationSummaryService;
use MultiTenantSaas\Modules\ServiceDesk\Dto\RiskVerdict;
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

    public function __construct(
        private readonly ConversationSummaryService $summaries,
        private readonly TicketService $tickets,
        private readonly ServiceDeskSettings $settings,
    ) {}

    /**
     * 执行闭环
     *
     * @param  array<int, string|int>  $notify  通知对象标识；其中数值型（已解析好的用户 ID）
     *                                          会被指派为工单处理人
     * @return Ticket|null 新建的工单；未启用 / 已建过 / 失败时 null
     */
    public function escalate(
        Conversation $conversation,
        string $reason,
        array $notify = [],
        ?RiskVerdict $verdict = null,
    ): ?Ticket {
        $this->refreshSummary($conversation);

        return $this->openTicket($conversation, $reason, $notify, $verdict);
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
     * 建工单（幂等：一个会话最多一张）
     */
    private function openTicket(
        Conversation $conversation,
        string $reason,
        array $notify,
        ?RiskVerdict $verdict,
    ): ?Ticket {
        if (! (bool) $this->settings->getForConversation($conversation, 'handoff.ticket_enabled', true)) {
            return null;
        }

        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];

        if (isset($metadata['ticket_id'])) {
            // 已经建过：重复转人工（用户反复说「转人工」、风险词重复命中）不该刷屏工单
            return null;
        }

        try {
            $ticket = $this->tickets->create([
                'subject' => $this->subject($reason),
                'description' => $this->description($conversation, $reason, $verdict, $metadata),
                'priority' => self::PRIORITY_BY_REASON[$reason] ?? 'medium',
                // 用原因码当分类，便于按「为什么转人工」筛选与统计
                'category' => $reason,
                // 归属：学生本人（身份桥接后的 users.user_id）；未关联则为 null
                'created_by' => $conversation->created_by,
                // 通知对象里的数值条目即已解析好的用户 ID，直接指派给他
                'assigned_to' => $this->firstNumericTarget($notify),
            ]);
        } catch (Throwable $e) {
            Log::error('[ServiceDesk] 转人工时建工单失败（不影响转人工）', [
                'conversation_id' => $conversation->conversation_id,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        // Ticket 没有 conversation_id 列：按「零新列」约定把关联写在会话 metadata
        // （方向与会话上的 handoff_* 一致，避免为此加一列并回填）
        $conversation->metadata = $metadata + [
            'ticket_id' => $ticket->ticket_id,
            'ticket_opened_at' => now()->toIso8601String(),
        ];
        $conversation->save();

        return $ticket;
    }

    private function subject(string $reason): string
    {
        $label = self::REASON_LABELS[$reason] ?? '需人工跟进';

        return "客服会话{$label}";
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function description(
        Conversation $conversation,
        string $reason,
        ?RiskVerdict $verdict,
        array $metadata,
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
