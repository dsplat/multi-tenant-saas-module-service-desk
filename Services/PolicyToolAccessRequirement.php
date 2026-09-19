<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Services;

use MultiTenantSaas\Contracts\ToolAccessRequirementContract;

/**
 * 把场景的数据分级策略适配成执行咽喉能识别的要求
 *
 * 为什么需要这层适配：执行咽喉在 Ai 模块，它不该反向依赖 ServiceDesk 的
 * `DataAccessPolicyContract`（模块边界，且客服模块可能没装）。框架因此定义
 * `ToolAccessRequirementContract`，客服侧用本类把自己接上去。
 *
 * 语义边界（很重要）：本类提出的要求只能在**暴露面白名单之内抬高门槛**，
 * 不能让任何工具变得可访问。未配置策略时返回 null，等价于「无额外要求」。
 */
class PolicyToolAccessRequirement implements ToolAccessRequirementContract
{
    public function __construct(
        private readonly ScenarioExtensions $extensions,
    ) {}

    public function requiredLevel(string $slug): ?string
    {
        return $this->extensions->dataAccessPolicy()?->requiredLevel($slug);
    }
}
