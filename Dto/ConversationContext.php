<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Dto;

use MultiTenantSaas\Context\ActorContext;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;

/**
 * 扩展点上下文（只读）
 *
 * 传给 RiskInterceptorContract / DataAccessPolicyContract / IdentityUpgradeContract。
 * **扩展点不得回写会话** —— 需要变更状态请走模块服务，保持单一写入方。
 */
final class ConversationContext
{
    public function __construct(
        public readonly int $tenantId,
        public readonly int $conversationId,
        /** 终端用户在系统内的 ID；匿名访客为 null */
        public readonly ?int $userId,
        /** 渠道标识：wechat-kf / web / miniapp … */
        public readonly string $channel,
        /** 渠道侧身份（微信客服 external_userid） */
        public readonly ?string $channelIdentity,
        /** 身份等级，取值见 ActorContext::LEVEL_* */
        public readonly string $accessLevel,
        /** 渠道接待态（0-4，见 WechatWorkApiClient::KF_STATE_*）；未知为 null */
        public readonly ?int $serviceState,
        /** 本次会话内 AI 连续未命中知识库的轮数 */
        public readonly int $unresolvedTurns = 0,
    ) {}

    /**
     * 从既有会话构建（接待态等字段由调用方补齐）
     */
    public static function fromConversation(
        Conversation $conversation,
        string $accessLevel,
        ?string $channelIdentity = null,
        ?int $serviceState = null,
        int $unresolvedTurns = 0,
    ): self {
        $createdBy = $conversation->created_by;

        return new self(
            tenantId: (int) $conversation->tenant_id,
            conversationId: (int) $conversation->conversation_id,
            userId: $createdBy !== null ? (int) $createdBy : null,
            channel: (string) ($conversation->channel ?? ''),
            channelIdentity: $channelIdentity,
            accessLevel: $accessLevel,
            serviceState: $serviceState,
            unresolvedTurns: $unresolvedTurns,
        );
    }

    /**
     * 是否已核身（verified）
     */
    public function isVerified(): bool
    {
        return $this->accessLevel === ActorContext::LEVEL_VERIFIED;
    }
}
