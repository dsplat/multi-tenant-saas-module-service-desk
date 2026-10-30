<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Dto;

/**
 * 风险判定结果
 *
 * 由 RiskInterceptorContract 返回；null 表示未命中（正常放行）。
 */
final class RiskVerdict
{
    /** 直接拒答：不进 AI 链路，回复安全文案 */
    public const LEVEL_BLOCK = 'block';

    /** 强制转人工：不拒答，但不允许 AI 继续自助 */
    public const LEVEL_ESCALATE = 'escalate';

    /**
     * @param  string  $level  见 LEVEL_*
     * @param  string  $reason  原因码（落审计，便于事后核对命中规则）
     * @param  string|null  $message  给用户的文案；null 时用模块默认
     * @param  array<int, string>  $notify  通知对象标识（如班主任/心理老师），由场景解释
     * @param  string|null  $tag  规则打标用的标签机器标识；null 时由 conversation.tags.rule 配置推导
     */
    public function __construct(
        public readonly string $level,
        public readonly string $reason,
        public readonly ?string $message = null,
        public readonly array $notify = [],
        public readonly ?string $tag = null,
    ) {}

    /**
     * @param  string|null  $tag  规则打标标签（可选）
     */
    public static function block(string $reason, ?string $message = null, ?string $tag = null): self
    {
        return new self(self::LEVEL_BLOCK, $reason, $message, [], $tag);
    }

    /**
     * @param  array<int, string>  $notify
     * @param  string|null  $tag  规则打标标签（可选）
     */
    public static function escalate(string $reason, array $notify = [], ?string $message = null, ?string $tag = null): self
    {
        return new self(self::LEVEL_ESCALATE, $reason, $message, $notify, $tag);
    }

    public function shouldBlock(): bool
    {
        return $this->level === self::LEVEL_BLOCK;
    }

    public function shouldEscalate(): bool
    {
        return $this->level === self::LEVEL_ESCALATE;
    }
}
