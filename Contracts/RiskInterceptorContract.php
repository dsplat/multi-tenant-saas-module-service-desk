<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Contracts;

use MultiTenantSaas\Modules\ServiceDesk\Dto\ConversationContext;
use MultiTenantSaas\Modules\ServiceDesk\Dto\RiskVerdict;

/**
 * 风险拦截器（场景扩展点）
 *
 * 框架提供的是**链路**：命中 → block 拒答 / escalate 强制转人工 → 通知指定人。
 * **词表与判定规则属于场景** —— 学校的心理危机与霸凌词表、电商的退款纠纷与法务风险、
 * 医疗的用药安全，形态相同、内容不同。
 *
 * 注册：config('service-desk.extensions.risk_interceptors') 里写类名数组，
 * 按顺序判定，**任一返回非 null 即生效**（不再继续问后续实现）。
 * 对齐框架既有的 extra_*_classes 范式。
 *
 * 实现约束：
 * - 必须是纯函数式判定（静态方法、无副作用），便于单测与顺序组合
 * - **不得回写会话**（Context 是只读的）
 * - 追求「宁可误转，不可漏转」：命中即 escalate 的代价（多一个老师看一眼）
 *   远小于漏判的代价
 */
interface RiskInterceptorContract
{
    /**
     * 检查单条用户消息
     *
     * @param  ConversationContext  $ctx  只读上下文
     * @param  string  $message  用户本次输入
     * @return RiskVerdict|null 未命中返回 null
     */
    public static function inspect(ConversationContext $ctx, string $message): ?RiskVerdict;
}
