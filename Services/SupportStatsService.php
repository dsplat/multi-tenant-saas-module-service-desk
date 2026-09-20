<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 客服度量
 *
 * 全部指标落在既有数据上（`conversations.metadata`），不新增表：
 *   handoff_reason / risk_flag / ticket_id / first_response_at / service_unresolved_turns
 *
 * **口径与限制必须写清**（看板数字被误读的代价比没有数字还大）：
 *
 * - 「AI 解决」是**代理口径**：无转人工、无工单即视为 AI 自己闭环。
 *   它不等于「用户满意」—— 用户可能问了没得到答案就离开了。要真实解决率需引入
 *   满意度或事后回访，属后续里程碑。
 * - 「首次响应时长」只反映 **AI 侧**：人工回复由坐席在企微工作台发出，
 *   渠道回调 origin=5 目前被 fetcher 过滤、不入库，因此人工响应时间本地看不到。
 * - 未解决轮数只统计**发生过的**（元数据计数器），是会话内的连续未命中数，
 *   不是「总共有多少问题没解决」。
 * - 满意度给出「评价率」而不只是满意率：采集依赖用户主动回复，评价率天然偏低，
 *   只报满意率会让人误以为剩下的人都满意。
 */
class SupportStatsService
{
    /** 默认统计窗口（天）与上限 —— 防止有人拉一整年的数据把看板拖死 */
    private const DEFAULT_DAYS = 30;

    private const MAX_DAYS = 90;

    /**
     * 汇总指标
     *
     * @return array{
     *     range: array{from: string, to: string, days: int},
     *     conversations: int,
     *     handoff: array{count: int, rate: float, by_reason: array<string, int>},
     *     ai_resolved: array{count: int, rate: float},
     *     risk: array{count: int, rate: float},
     *     tickets: int,
     *     first_response: array{measured: int, average_seconds: int|null},
     *     unresolved_turns: array{average: float, max: int}
     * }
     */
    public function summary(int $tenantId, ?CarbonInterface $from = null, ?CarbonInterface $to = null): array
    {
        $to ??= now();
        $from ??= now()->subDays(self::DEFAULT_DAYS);

        // 窗口上限：宁可少统计，也不让一个查询把库拖住
        $maxFrom = now()->subDays(self::MAX_DAYS);

        if ($from->lt($maxFrom)) {
            $from = $maxFrom;
        }

        $base = $this->conversations($tenantId, $from, $to);

        $total = (clone $base)->count();
        $handoffCount = (clone $base)->whereNotNull('metadata->handoff_reason')->count();
        $ticketCount = (clone $base)->whereNotNull('metadata->ticket_id')->count();
        $riskCount = (clone $base)->whereNotNull('metadata->risk_flag')->count();

        // AI 闭环 = 既没转人工、也没工单 —— **直接计数**，不要写成
        // 总数 - 转人工 - 工单：工单往往正是转人工引起的，两个集合重叠，
        // 减法会把同一批会话扣两次（实测踩到）。
        $aiResolvedCount = (clone $base)
            ->whereNull('metadata->handoff_reason')
            ->whereNull('metadata->ticket_id')
            ->count();

        return [
            'range' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
                'days' => (int) $from->diffInDays($to),
            ],
            'conversations' => $total,
            'handoff' => [
                'count' => $handoffCount,
                'rate' => $this->rate($handoffCount, $total),
                'by_reason' => $this->handoffByReason($base),
            ],
            'ai_resolved' => [
                'count' => $aiResolvedCount,
                'rate' => $this->rate($aiResolvedCount, $total),
            ],
            'risk' => [
                'count' => $riskCount,
                'rate' => $this->rate($riskCount, $total),
            ],
            'tickets' => $ticketCount,
            'first_response' => $this->firstResponse($base),
            'unresolved_turns' => $this->unresolvedTurns($base),
            'satisfaction' => $this->satisfaction($base, $total),
        ];
    }

    /**
     * 客服会话基础查询
     *
     * 绕过 TenantScope 并显式带 tenant_id：看板由运营在控制台打开，
     * 但这里不想依赖「调用方一定设过租户上下文」这一前提。
     */
    private function conversations(int $tenantId, CarbonInterface $from, CarbonInterface $to): Builder
    {
        return Conversation::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereIn('channel', (array) config('service-desk.channels', []))
            ->whereBetween('created_at', [$from, $to]);
    }

    /**
     * 按转人工原因分组
     *
     * 这是看板上最有用的一格：它直接回答「AI 最常因为什么把活交出去」——
     * 未解决轮数多说明知识库覆盖不足，风险多说明该看词表，用户主动要求多说明
     * 用户对 AI 的信任度低。
     *
     * @return array<string, int>
     */
    private function handoffByReason(Builder $base): array
    {
        $rows = (clone $base)
            ->whereNotNull('metadata->handoff_reason')
            ->get(['metadata']);

        $counts = [];

        foreach ($rows as $row) {
            $reason = (string) (($row->metadata ?? [])['handoff_reason'] ?? '');

            if ($reason !== '') {
                $counts[$reason] = ($counts[$reason] ?? 0) + 1;
            }
        }

        arsort($counts);

        return $counts;
    }

    /**
     * AI 首次响应时长（会话创建 → 首条 AI 回复）
     *
     * @return array{measured: int, average_seconds: int|null}
     */
    private function firstResponse(Builder $base): array
    {
        $rows = (clone $base)
            ->whereNotNull('metadata->first_response_at')
            ->get(['created_at', 'metadata']);

        $samples = [];

        foreach ($rows as $row) {
            $at = ($row->metadata ?? [])['first_response_at'] ?? null;

            if (! is_string($at) || $at === '') {
                continue;
            }

            try {
                $seconds = $row->created_at?->diffInSeconds(Carbon::parse($at));
            } catch (\Throwable) {
                continue;
            }

            if (is_numeric($seconds) && $seconds >= 0) {
                $samples[] = (int) $seconds;
            }
        }

        return [
            'measured' => count($samples),
            'average_seconds' => $samples === [] ? null : (int) round(array_sum($samples) / count($samples)),
        ];
    }

    /**
     * 未解决轮数（会话内连续未命中计数器）
     *
     * @return array{average: float, max: int}
     */
    private function unresolvedTurns(Builder $base): array
    {
        $rows = (clone $base)->get(['metadata']);

        $values = [];

        foreach ($rows as $row) {
            $turns = (int) (($row->metadata ?? [])['service_unresolved_turns'] ?? 0);
            $values[] = $turns;
        }

        if ($values === []) {
            return ['average' => 0.0, 'max' => 0];
        }

        return [
            'average' => round(array_sum($values) / count($values), 2),
            'max' => max($values),
        ];
    }

    /**
     * 满意度分布
     *
     * 「已评价」与「未评价」必须分开看：采集依赖用户主动回复，评价率天然偏低，
     * 只报「满意率」会让人误以为剩下的人都满意。故一并给出 collected（评价率）。
     *
     * @return array{collected: int, rate: float, by_value: array<string, int>}
     */
    private function satisfaction(Builder $base, int $total): array
    {
        $rows = (clone $base)->get(['metadata']);

        $byValue = [];

        foreach ($rows as $row) {
            $satisfaction = (($row->metadata ?? [])['satisfaction'] ?? null);
            $value = is_array($satisfaction) ? (string) ($satisfaction['value'] ?? '') : '';

            if ($value !== '') {
                $byValue[$value] = ($byValue[$value] ?? 0) + 1;
            }
        }

        // 稳定排序：计数降序，同数按选项名升序 —— 看板上同一份数据的顺序不该每次都变
        uksort($byValue, static function (string $a, string $b) use ($byValue) {
            return $byValue[$b] <=> $byValue[$a] ?: strcmp($a, $b);
        });

        return [
            'collected' => array_sum($byValue),
            'rate' => $this->rate(array_sum($byValue), $total),
            'by_value' => $byValue,
        ];
    }

    private function rate(int $part, int $total): float
    {
        return $total === 0 ? 0.0 : round($part / $total, 4);
    }
}
