<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\ActorContext;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\ServiceDesk\Contracts\DataAccessPolicyContract;
use MultiTenantSaas\Modules\ServiceDesk\Dto\ConversationContext;
use Throwable;

/**
 * 会话身份等级判定（唯一口径）
 *
 * 三档（ActorContext::LEVEL_*）：
 *   anonymous      匿名 —— 仅通用政策/流程类问答
 *   authenticated  已识别渠道身份 —— 可做「与我相关」的只读查询
 *   verified       已核身 —— 可查个人信息
 *
 * 判定顺序（不可调换）：
 *   1. `metadata.access_level` —— 提升结果。**只有 IdentityUpgradeService 会写它**，
 *      因此它是可信来源；用户无法通过对话内容或请求参数影响它
 *   2. 场景策略 `DataAccessPolicyContract::levelFor()`（配置了才用）
 *   3. 兜底：渠道身份已关联到系统用户 → authenticated；否则 anonymous
 *
 * 铁律：**等级只能由服务端判定**，不得依据对话内容或用户自称提升 —— 用户说
 * 「我是张三」不构成核验。本类接收的输入只有会话与元数据，没有任何请求体入参。
 */
class AccessLevelResolver
{
    public function __construct(
        private readonly ConversationContextFactory $contexts,
        private readonly ScenarioExtensions $extensions,
    ) {}

    /**
     * 会话当前等级
     */
    public function levelFor(Conversation $conversation): string
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];

        $promoted = $this->normalize($metadata['access_level'] ?? null);

        if ($promoted !== null) {
            return $promoted;
        }

        $policy = $this->extensions->dataAccessPolicy();

        if ($policy !== null) {
            try {
                $decided = $this->normalize($policy->levelFor(
                    $this->contexts->make($conversation, $this->fallbackLevel($conversation)),
                ));

                if ($decided !== null) {
                    return $decided;
                }
            } catch (Throwable $e) {
                // 策略故障时**降级到兜底等级**（而不是放行到更高档）：
                // 分级是安全判定，取不到结论只能按更保守的档处理。
                Log::error('[ServiceDesk] 数据分级策略执行异常，降级为兜底等级', [
                    'conversation_id' => $conversation->conversation_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $this->fallbackLevel($conversation);
    }

    /**
     * 带已判定等级的上下文（供扩展点使用）
     */
    public function contextFor(Conversation $conversation): ConversationContext
    {
        return $this->contexts->make($conversation, $this->levelFor($conversation));
    }

    /**
     * 兜底等级：渠道身份是否已关联到系统用户
     *
     * 关联动作发生在身份桥接（进会话短码兑换）—— 那是「我们确认了这个人是谁」，
     * 但**不等于核身**，故最高只到 authenticated。
     */
    private function fallbackLevel(Conversation $conversation): string
    {
        return $conversation->created_by !== null
            ? ActorContext::LEVEL_AUTHENTICATED
            : ActorContext::LEVEL_ANONYMOUS;
    }

    /**
     * 非法等级值一律丢弃（回落到下一判定源），避免脏元数据把闸门打开
     */
    private function normalize(mixed $level): ?string
    {
        return is_string($level) && in_array($level, ActorContext::LEVELS, true) ? $level : null;
    }
}
