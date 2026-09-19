<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Contracts;

use MultiTenantSaas\Modules\ServiceDesk\Dto\ConversationContext;

/**
 * 数据访问分级（场景扩展点）
 *
 * 框架提供的是**三档闸门 + 工具层强制校验**；**哪些数据属于哪档属于场景**。
 *
 * 三档（取值见 ActorContext::LEVEL_*）：
 *   anonymous      匿名 —— 仅通用政策/流程类问答
 *   authenticated  已识别渠道身份 —— 可做「与我相关」的只读查询
 *   verified       已核身 —— 可查个人信息（课表/成绩/缴费…）
 *
 * ⚠ 本契约是**判定**，不是**执行**。真正的过滤必须在工具实现层按
 * `ConversationContext::$userId` 显式加条件 —— 框架无法用通用机制保证
 * 行级数据隔离（主体归属列不齐：工单是 requester、订单是 buyer、会话是 created_by）。
 * 见 docs/user-ai-design.md §六。
 *
 * 注册：config('service-desk.extensions.data_access_policy')（单一实现）。
 */
interface DataAccessPolicyContract
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
