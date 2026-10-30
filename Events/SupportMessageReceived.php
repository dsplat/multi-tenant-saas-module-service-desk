<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\Conversation\Models\Message;

/**
 * 客服收到客户消息（模块内事件）
 *
 * 由 HandleInboundSupportMessage 在识别出客服会话后派发，作为**后续能力的挂点**：
 * 风险拦截、AI 应答、转人工决策、接待态同步都挂这里，而不是继续塞进那个监听器。
 *
 * 与框架的 MessageReceived 的区别：
 *   MessageReceived         渠道无关的入站总事件（所有渠道、所有用途）
 *   SupportMessageReceived  已确认是「客服场景」且已完成会话初始化
 */
class SupportMessageReceived
{
    use Dispatchable;

    public function __construct(
        public readonly Conversation $conversation,
        public readonly Message $message,
        public readonly string $channel,
    ) {}
}
