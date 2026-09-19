<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\ServiceDesk\Contracts\DataAccessPolicyContract;
use MultiTenantSaas\Modules\ServiceDesk\Contracts\IdentityUpgradeContract;

/**
 * 场景扩展点解析
 *
 * 三个扩展点都按「config 里写类名 → 容器解析 → 校验契约」的方式取用
 * （对齐框架既有的 extra_*_classes 范式）。集中在一处是为了避免每个使用点
 * 各写一份校验：漏一处就会出现「配错了但静默不生效」。
 *
 * 配置错误（类不存在 / 没实现契约）**记 warning 但不抛** —— 这是部署配置问题，
 * 不该让用户的消息在客服入口炸掉。风险拦截器另有 RiskGuard 处理异常，
 * 那里允许继续问下一个实现；本类只管解析。
 */
class ScenarioExtensions
{
    public function dataAccessPolicy(): ?DataAccessPolicyContract
    {
        return $this->resolve(
            (string) config('service-desk.extensions.data_access_policy', ''),
            DataAccessPolicyContract::class,
        );
    }

    public function identityUpgrade(): ?IdentityUpgradeContract
    {
        return $this->resolve(
            (string) config('service-desk.extensions.identity_upgrade', ''),
            IdentityUpgradeContract::class,
        );
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return T|null
     */
    private function resolve(string $class, string $contract): ?object
    {
        if ($class === '') {
            return null;
        }

        if (! class_exists($class)) {
            Log::warning('[ServiceDesk] 扩展点配置的类不存在', [
                'class' => $class,
                'contract' => $contract,
            ]);

            return null;
        }

        $instance = app($class);

        if (! $instance instanceof $contract) {
            Log::warning('[ServiceDesk] 扩展点实现未遵循契约', [
                'class' => $class,
                'contract' => $contract,
            ]);

            return null;
        }

        return $instance;
    }
}
