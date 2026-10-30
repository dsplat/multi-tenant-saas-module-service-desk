<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Services;

use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\ServiceDesk\Dto\ConversationContext;

/**
 * 扩展点上下文构建（唯一入口）
 *
 * 风险拦截器与数据分级策略看到的必须是**同一份上下文** —— 各建一套的话，
 * 迟早出现「风险判定按 A 等级、分级判定按 B 等级」这种漂移，
 * 而这类漂移在安全路径上是很难事后发现的。
 *
 * 本类只做字段搬运：等级由 {@see AccessLevelResolver} 判定后传入，
 * 不在这里推断（判定口径只允许有一处）。
 */
class ConversationContextFactory
{
    public function __construct(
        private readonly SupportSessionService $sessions,
    ) {}

    public function make(Conversation $conversation, string $accessLevel): ConversationContext
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];

        return ConversationContext::fromConversation(
            conversation: $conversation,
            accessLevel: $accessLevel,
            channelIdentity: $this->channelIdentity($metadata),
            serviceState: $this->sessions->getState($conversation),
            unresolvedTurns: $this->sessions->getUnresolvedTurns($conversation),
        );
    }

    /**
     * 渠道侧身份（external_userid）：external_conv_id 形如 "{open_kfid}:{external_userid}"
     *
     * @param  array<string, mixed>  $metadata
     */
    private function channelIdentity(array $metadata): ?string
    {
        $externalConvId = (string) ($metadata['external_conv_id'] ?? '');

        if ($externalConvId === '') {
            return null;
        }

        $parts = explode(':', $externalConvId, 2);

        return $parts[1] ?? null;
    }
}
