<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\Conversation\Models\Message;
use MultiTenantSaas\Modules\ServiceDesk\Services\SupportReplyService;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 客服应答作业
 *
 * 为什么走队列：渠道回调要求**收到即 ACK**（超时会被重推，导致重复处理），
 * 而一次应答包含知识检索 + 模型合成，耗时不可控。故监听器只入队，不在请求内算。
 *
 * 只传 ID 不传模型：队列里没有认证/租户上下文，反序列化出来的模型经 TenantScope
 * 查询会因 fail-closed 拿不到（WHERE 1=0）。这里显式按 ID 直取并补上租户上下文。
 */
class SendSupportReplyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $conversationId,
        private readonly int $messageId,
    ) {}

    public function handle(SupportReplyService $reply): void
    {
        $conversation = Conversation::withoutGlobalScope(TenantScope::class)
            ->find($this->conversationId);

        if ($conversation === null) {
            // 会话已被删除（例如会话结束归档）：没什么可应答的
            return;
        }

        $message = Message::withoutGlobalScope(TenantScope::class)
            ->where('message_id', $this->messageId)
            ->first();

        if ($message === null) {
            return;
        }

        $previousTenantId = TenantContext::getId();
        TenantContext::setTenantId((string) $conversation->tenant_id);

        try {
            $reply->handle($conversation, $message);
        } catch (\Throwable $e) {
            // 应答失败不该把作业打进死循环重试（用户会收到重复回复）；
            // 记 error 供排障，链路本身有降级（转人工）
            Log::error('[ServiceDesk] 应答作业执行失败', [
                'conversation_id' => $this->conversationId,
                'message_id' => $this->messageId,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($previousTenantId === null) {
                TenantContext::clear();
            } else {
                TenantContext::setTenantId($previousTenantId);
            }
        }
    }
}
