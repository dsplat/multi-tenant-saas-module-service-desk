<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\Conversation\Models\Participant;

/**
 * 身份桥接 —— 把渠道身份关联到系统用户
 *
 * 背景：终端用户在两个作用域各有一个标识（小程序 `unionid` ↔ 微信客服
 * `external_userid`），**不能自动互换，必须显式桥接**（见 docs/service-desk-design.md §3.2）：
 *
 *   宿主带参跳转客服 → 渠道进入会话事件带回 scene 短码
 *        → 兑换出 user_id → 关联会话到该用户
 *
 * 时机的坑（本类存在的主要原因）：
 * `enter_session` 事件**早于**首条消息，而会话是由 ConversationRouter 在**首条消息**
 * 时才创建的。事件到达时往往还没有 conversation 可关联，因此不能「到了就写会话」，
 * 而是先记一条**待绑定**，等会话出现时再落。
 *
 * 落点（§5.1，零业务新表）：
 *   conversations.created_by        = users.user_id（终端用户归属）
 *   participants(role='guest')      = 该用户作为访客参与者
 *   conversations.metadata          = 绑定时间与来源（可审计）
 */
class IdentityBridgeService
{
    private const PENDING_PREFIX = 'service-desk:identity-pending:';

    /**
     * 记一条待绑定（幂等：同一会话重复进入以最后一次为准）
     *
     * TTL 比短码本身长：用户点了链接之后可能过一会儿才开口说话，
     * 而会话要等第一条消息才创建。
     */
    public function rememberPending(int $tenantId, string $externalConvId, int $userId, ?string $source = null): void
    {
        if ($tenantId <= 0 || $userId <= 0 || $externalConvId === '') {
            return;
        }

        Cache::put(
            $this->pendingKey($tenantId, $externalConvId),
            [
                'user_id' => $userId,
                'source' => $source,
                'linked_at' => now()->toIso8601String(),
            ],
            max(1, (int) config('service-desk.identity_bridge.pending_ttl', 86400)),
        );
    }

    /**
     * 读待绑定（不消费），无则 null
     *
     * @return array{user_id: int, source: ?string, linked_at: string}|null
     */
    public function pending(int $tenantId, string $externalConvId): ?array
    {
        $value = Cache::get($this->pendingKey($tenantId, $externalConvId));

        return is_array($value) ? $value : null;
    }

    /**
     * 消费待绑定
     *
     * 幂等失败路径：会话已提前绑到同一个人时也会清除 —— 留着它没有任何用处，
     * 反而会在用户换号重新进入时把旧绑定又贴回来。
     */
    public function forgetPending(int $tenantId, string $externalConvId): void
    {
        Cache::forget($this->pendingKey($tenantId, $externalConvId));
    }

    /**
     * 把身份落到会话（幂等）
     *
     * @return bool 是否发生了新的绑定（已绑定同一人 / 换人 → false）
     */
    public function linkConversation(Conversation $conversation, int $userId, ?string $source = null): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $linkedUserId = (int) ($metadata[$this->metaKey('linked_user')] ?? 0);

        if ($linkedUserId === $userId) {
            return false;
        }

        // 已绑到别人：**不覆盖**。会话归属变更等于把一段对话交给另一个账号，
        // 属于账号安全范畴，不在这里静默处理（重复进入自己的会话不会走到这）。
        if ($linkedUserId > 0) {
            Log::warning('[ServiceDesk] 会话已绑定其他用户，拒绝改绑', [
                'tenant_id' => (int) $conversation->tenant_id,
                'conversation_id' => $conversation->conversation_id,
            ]);

            return false;
        }

        $conversation->created_by = $userId;
        $conversation->metadata = $metadata + [
            $this->metaKey('linked_user') => $userId,
            $this->metaKey('linked_at') => now()->toIso8601String(),
            $this->metaKey('linked_source') => $source,
        ];
        $conversation->save();

        // 访客参与者：participants.user_id 是 NOT NULL + FK users，关联前无法落行，
        // 这正是「关联后」才能补的一步。unique(conversation_id, user_id) 保证幂等。
        Participant::firstOrCreate(
            [
                'conversation_id' => $conversation->conversation_id,
                'user_id' => $userId,
            ],
            [
                'tenant_id' => (int) $conversation->tenant_id,
                'role' => 'guest',
            ],
        );

        return true;
    }

    /**
     * 已绑定的用户 ID（无则 null）
     */
    public function linkedUserId(Conversation $conversation): ?int
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $userId = (int) ($metadata[$this->metaKey('linked_user')] ?? 0);

        return $userId > 0 ? $userId : null;
    }

    private function pendingKey(int $tenantId, string $externalConvId): string
    {
        return self::PENDING_PREFIX . $tenantId . ':' . $externalConvId;
    }

    private function metaKey(string $name): string
    {
        return 'identity_' . $name;
    }
}
