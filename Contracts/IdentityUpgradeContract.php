<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Contracts;

use MultiTenantSaas\Modules\ServiceDesk\Dto\ConversationContext;
use MultiTenantSaas\Modules\ServiceDesk\Dto\IdentityResult;

/**
 * 身份提升（场景扩展点）
 *
 * 框架提供的是**从 authenticated 提升到 verified 的流程与状态机**；
 * **核验方式属于场景**（学校用学号/统一身份，电商用手机号，医疗用就诊卡）。
 *
 * 提升后，会话可达的工具集从「通用 + 与我相关」扩展到「个人信息查询」。
 *
 * 注册：config('service-desk.extensions.identity_upgrade')（单一实现）。
 *
 * 实现约束：
 * - **核验结果不得来自对话内容** —— 用户自称「我是张三」不构成核验
 * - 提升动作必须落审计（谁、何时、凭据类型），由实现方调用 AuditLogService
 */
interface IdentityUpgradeContract
{
    /**
     * 当前会话是否具备提升条件（如已登录但未实名）
     */
    public function canPromote(ConversationContext $ctx): bool;

    /**
     * 执行提升
     *
     * @param  array<string, mixed>  $payload  场景自定义的核验材料（学号、验证码…）
     */
    public function promote(ConversationContext $ctx, array $payload): IdentityResult;
}
