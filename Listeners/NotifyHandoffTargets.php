<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Listeners;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Notification\Services\InAppNotificationService;
use MultiTenantSaas\Modules\ServiceDesk\Events\SupportHandoffRequested;

/**
 * 转人工通知的**默认**投递
 *
 * 只处理框架能理解的形态：notify 里的**数值型**条目视为已解析好的用户 ID，
 * 给他们发站内通知。非数值条目（「班主任」「心理老师」这类场景语义标识）框架不认识，
 * 由场景自己监听 SupportHandoffRequested 投递 —— 把角色名映射到具体的人是场景数据。
 *
 * 失败一律 fail-open：通知发不出去不能影响「已经转人工」这个事实。
 */
class NotifyHandoffTargets
{
    public function __construct(
        private readonly InAppNotificationService $notifications,
    ) {}

    public function handle(SupportHandoffRequested $event): void
    {
        $userIds = $this->numericTargets($event->notify);

        if ($userIds === []) {
            return;
        }

        // 站内通知带 tenant_id（NOT NULL + BelongsToTenant 自动填），而本监听器可能
        // 从队列里触发、也可能在无认证上下文的回调里触发 —— 必须以事件里的会话租户为准，
        // 不能依赖环境上下文（缺失时插入会直接违反 NOT NULL）。
        $previousTenantId = TenantContext::getId();
        TenantContext::setTenantId((string) $event->conversation->tenant_id);

        try {
            $this->deliver($userIds, $event);
        } finally {
            if ($previousTenantId === null) {
                TenantContext::clear();
            } else {
                TenantContext::setTenantId($previousTenantId);
            }
        }
    }

    /**
     * @param  array<int, int>  $userIds
     */
    private function deliver(array $userIds, SupportHandoffRequested $event): void
    {
        $title = '有一条客服会话需要人工介入';
        $body = $event->verdict?->message
            ?? '用户消息触发了转人工，请尽快接入会话。';

        // 附上 AI 生成的建议话术：坐席在企微工作台里，回 console 看会话不现实，
        // 通知正文是他们唯一方便读到建议的地方
        $suggestions = (array) (($event->conversation->metadata ?? [])['suggested_replies'] ?? []);

        if ($suggestions !== []) {
            $body .= "\n\n建议话术：";
            foreach ($suggestions as $i => $suggestion) {
                $body .= "\n" . ($i + 1) . '. ' . $suggestion;
            }
        }

        foreach ($userIds as $userId) {
            try {
                $this->notifications->create([
                    'user_id' => $userId,
                    'title' => $title,
                    'body' => $body,
                    'link' => null,
                    'metadata' => [
                        'conversation_id' => (int) $event->conversation->conversation_id,
                        'tenant_id' => (int) $event->conversation->tenant_id,
                        'reason' => $event->reason,
                        'risk_reason' => $event->verdict?->reason,
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::warning('[ServiceDesk] 转人工通知投递失败', [
                    'user_id' => $userId,
                    'conversation_id' => $event->conversation->conversation_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param  array<int, string|int>  $notify
     * @return array<int, int>
     */
    private function numericTargets(array $notify): array
    {
        $userIds = [];

        foreach ($notify as $target) {
            if (is_int($target) || (is_string($target) && ctype_digit($target))) {
                $userIds[] = (int) $target;
            }
        }

        return array_values(array_unique($userIds));
    }
}
