<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Dto;

/**
 * 身份提升结果
 *
 * 由 IdentityUpgradeContract::promote() 返回。
 */
final class IdentityResult
{
    /**
     * @param  bool  $ok  是否提升成功
     * @param  string|null  $level  成功后的等级（ActorContext::LEVEL_*）
     * @param  string|null  $message  给用户的说明（失败原因 / 后续步骤）
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $level = null,
        public readonly ?string $message = null,
    ) {}

    public static function success(string $level, ?string $message = null): self
    {
        return new self(true, $level, $message);
    }

    public static function failure(string $message): self
    {
        return new self(false, null, $message);
    }
}
