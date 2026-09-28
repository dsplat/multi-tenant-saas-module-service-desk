<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\ServiceDesk\Services\ServiceDeskSettings;

/**
 * 客服配置读取工具（AI 秘书可查询当前策略设置）
 *
 * 无状态；租户隔离依赖显式 $tenantId。
 */
class ServiceDeskConfigHandler implements ToolHandlerContract
{
    public function __construct(private readonly ServiceDeskSettings $settings) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        return $this->settings->describe($tenantId);
    }
}
