<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Exceptions;

use MultiTenantSaas\Exceptions\ConflictException;

/**
 * 同一来源键（tenant_id, conversation_id, escalation_key）承载了不同请求。
 *
 * 语义：显式拒绝，不静默复用既有工单 —— 否则「同一个键换一个申请类型」会被
 * 悄悄当成重复调用，调用方以为新申请已建单，实际什么都没发生。
 *
 * 可机读原因码：{@see self::REASON}（escalation_source_conflict），走 409；
 * 指纹不对外暴露正文，只暴露键与已绑定工单 ID，便于调用方定位。
 */
final class EscalationSourceConflictException extends ConflictException
{
    /** 稳定原因码（与 BL-180 §4 的 reason 语义一致，不另造同义枚举） */
    public const REASON = 'escalation_source_conflict';

    /** 可机读原因码（与 {@see self::reasonCode()} 同值，便于统一按属性读取） */
    public readonly string $reason;

    public function __construct(
        public readonly string $escalationKey,
        public readonly ?int $ticketId,
        public readonly string $existingFingerprint,
        public readonly string $requestFingerprint,
    ) {
        $this->reason = self::REASON;

        parent::__construct(sprintf(
            '%s: 来源键 "%s" 已绑定工单 %s，本次请求内容与既有来源不一致，拒绝静默复用',
            self::REASON,
            $escalationKey,
            $ticketId === null ? '(未绑定)' : (string) $ticketId,
        ));
    }

    /**
     * 可机读原因码（前端/调用方按此分支，不解析 message）
     */
    public function reasonCode(): string
    {
        return self::REASON;
    }
}
