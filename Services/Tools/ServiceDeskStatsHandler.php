<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Services\Tools;

use Carbon\Carbon;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\ServiceDesk\Services\SupportStatsService;

/**
 * 客服统计摘要工具（AI 秘书可调用：今日接待概况）
 *
 * 无状态；租户隔离依赖显式 $tenantId。
 */
class ServiceDeskStatsHandler implements ToolHandlerContract
{
    public function __construct(private readonly SupportStatsService $stats) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->stats->summary(
            tenantId: $tenantId,
            from: isset($arguments['from']) ? Carbon::parse($arguments['from']) : null,
            to: isset($arguments['to']) ? Carbon::parse($arguments['to']) : null,
        );
    }
}
