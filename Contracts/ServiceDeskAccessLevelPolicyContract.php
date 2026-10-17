<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Contracts;

use MultiTenantSaas\Modules\ServiceDesk\Dto\ConversationContext;

/**
 * 数据访问分级（场景扩展点）——**工具等级判定**，非行级范围授权
 *
 * 与框架级 `MultiTenantSaas\Contracts\DataAccessPolicyContract`（行/列范围）不同概念。
 * 本契约只回答「会话能到哪一档 / 工具要哪一档」（三档：anonymous / authenticated / verified）。
 *
 * ⚠ 本契约是**判定**，不是**执行**。真正的过滤必须在工具实现层按
 * `ConversationContext::$userId` 显式加条件 —— 框架无法用通用机制保证
 * 行级数据隔离（主体归属列不齐：工单是 requester、订单是 buyer、会话是 created_by）。
 *
 * 注册：config('service-desk.extensions.data_access_policy')（单一实现）。
 *
 * 命名说明：原 `DataAccessPolicyContract` 已让名给框架级行/列契约（ADR v4 §2），
 * 本接口改名自旧名；旧名保留 `@deprecated` 空继承以兼容下游实现。
 */
interface ServiceDeskAccessLevelPolicyContract
{
    /**
     * 该会话当前能达到的等级
     *
     * 等级只能由服务端判定 —— 不得依据对话内容或用户自称提升。
     */
    public function levelFor(ConversationContext $ctx): string;

    /**
     * 指定工具需要的最低等级
     *
     * @return string|null null 表示不限制（通用工具，如知识检索）
     */
    public function requiredLevel(string $toolSlug): ?string;
}
