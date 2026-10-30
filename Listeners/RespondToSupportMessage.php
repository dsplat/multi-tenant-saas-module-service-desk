<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Listeners;

use MultiTenantSaas\Modules\ServiceDesk\Events\SupportMessageReceived;
use MultiTenantSaas\Modules\ServiceDesk\Jobs\SendSupportReplyJob;
use MultiTenantSaas\Modules\ServiceDesk\Services\SupportReplyService;

/**
 * 客服应答接线
 *
 * 只做一件事：**入队**。应答本身（风险拦截、转人工决策、AI 合成）在队列里跑 ——
 * 渠道回调要求收到即 ACK，不能在请求内算模型。
 *
 * 决策顺序与失败语义见 {@see SupportReplyService}。
 */
class RespondToSupportMessage
{
    public function handle(SupportMessageReceived $event): void
    {
        SendSupportReplyJob::dispatch(
            (int) $event->conversation->conversation_id,
            (int) $event->message->message_id,
        );
    }
}
