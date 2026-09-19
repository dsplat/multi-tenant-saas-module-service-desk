<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\ServiceDesk\Contracts\RiskInterceptorContract;
use MultiTenantSaas\Modules\ServiceDesk\Dto\RiskVerdict;

/**
 * 风险拦截执行器（框架侧）
 *
 * 框架只提供**链路**：按配置顺序问一遍场景注册的拦截器，任一给出判定即生效。
 * 词表与规则属场景（学校的心理危机、电商的退款纠纷、医疗的用药安全，
 * 形态相同、内容不同）。
 *
 * 失败语义：拦截器自身抛异常 → **记 error 后继续问下一个**，不让消息链路挂掉。
 * 这是有意的取舍：拦截器故障不该导致客服整体不可用；代价是可能漏检一次，
 * 由 error 日志兜住可观测性。
 */
class RiskGuard
{
    public function __construct(
        private readonly AccessLevelResolver $accessLevels,
    ) {}

    /**
     * 检查一条用户消息
     *
     * @return RiskVerdict|null 未命中 / 未配置拦截器时返回 null
     */
    public function inspect(Conversation $conversation, string $message): ?RiskVerdict
    {
        $interceptors = $this->configuredInterceptors();

        if ($interceptors === []) {
            return null;
        }

        // 上下文与等级走统一入口：风险判定与分级判定必须看到同一份事实
        $context = $this->accessLevels->contextFor($conversation);

        foreach ($interceptors as $class) {
            try {
                $verdict = $class::inspect($context, $message);
            } catch (\Throwable $e) {
                Log::error('[ServiceDesk] 风险拦截器执行异常，已跳过', [
                    'interceptor' => $class,
                    'tenant_id' => (int) $conversation->tenant_id,
                    'conversation_id' => $conversation->conversation_id,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if ($verdict instanceof RiskVerdict) {
                return $verdict;
            }
        }

        return null;
    }

    /**
     * 配置里登记且形态合法的拦截器类
     *
     * 配错（类不存在 / 没实现契约）只记日志不抛：这是配置问题，
     * 不该把用户的消息卡死在客服入口。
     *
     * @return array<int, class-string<RiskInterceptorContract>>
     */
    private function configuredInterceptors(): array
    {
        $configured = config('service-desk.extensions.risk_interceptors', []);

        if (! is_array($configured)) {
            return [];
        }

        $valid = [];

        foreach ($configured as $class) {
            if (! is_string($class) || $class === '') {
                continue;
            }

            if (! class_exists($class) || ! is_subclass_of($class, RiskInterceptorContract::class)) {
                Log::warning('[ServiceDesk] 风险拦截器配置无效（类不存在或未实现契约）', [
                    'interceptor' => $class,
                ]);

                continue;
            }

            $valid[] = $class;
        }

        return $valid;
    }
}
