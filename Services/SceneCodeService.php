<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;

/**
 * 入口短码 —— 身份桥接的凭据
 *
 * 渠道对场景值有硬约束（官方 kf/add_contact_way）：
 *   scene **不多于 32 字节**，字符集 `[0-9a-zA-Z_-]`
 * 因此**不能**沿用「自包含签名串 + user_id 明文」的思路 —— 装不下。
 * 改为**服务端短码查表**：
 *
 *   128-bit 随机 → base64url 22 字符（不可猜）
 *        → 短码 ↔ { tenant_id, user_id, issued_at } 落缓存（短 TTL）
 *        → 用户进入会话事件带回该值 → 查表 + **一次性消费** 解出 user_id
 *
 * 安全性由「随机不可猜 + 一次性消费 + 短 TTL」保证，而非签名。
 *
 * 存储用缓存而不是新建表：模块约定「零业务新表」（docs/service-desk-design.md §5.1）。
 * ⚠ 多实例部署必须用共享缓存（redis / memcached）：用 local / file 存储时，
 * 签发与兑换若落在不同实例上会兑换失败。
 */
class SceneCodeService
{
    /** 缓存键前缀 */
    private const STORAGE_PREFIX = 'service-desk:scene:';

    /** 随机字节数：16 字节 = 128 bit */
    private const CODE_BYTES = 16;

    /** 渠道对 scene 的硬上限（字节） */
    private const MAX_SCENE_BYTES = 32;

    /**
     * 签发短码：绑定 (tenant, user)，TTL 后自动失效
     *
     * @throws ServiceUnavailableException 缓存不可用或连续碰撞导致无法分配唯一短码
     */
    public function issue(int $tenantId, int $userId): string
    {
        $ttl = max(1, (int) config('service-desk.identity_bridge.scene_ttl', 900));

        $payload = [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'issued_at' => now()->toIso8601String(),
        ];

        // 128-bit 随机碰撞概率可忽略，但 Cache::add 的「不存在才写」语义正好
        // 可做原子占位 —— 用覆盖写会把别人尚未兑换的短码踩掉。
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $code = $this->composeCode();

            if (Cache::add(self::STORAGE_PREFIX . $code, $payload, $ttl)) {
                return $code;
            }
        }

        throw new ServiceUnavailableException('无法分配唯一 scene 短码（缓存不可用或短码空间异常）');
    }

    /**
     * 一次性兑换：成功即失效
     *
     * @return int|null 解出的用户 ID；无效 / 过期 / 已用过 / 租户不匹配一律 null
     */
    public function consume(string $code, int $tenantId): ?int
    {
        $code = trim($code);

        if (! $this->isWellFormed($code)) {
            return null;
        }

        // pull = 取走并删除：重放同一个短码拿不到第二次
        $payload = Cache::pull(self::STORAGE_PREFIX . $code);

        if (! is_array($payload)) {
            return null;
        }

        // 跨租户防护：A 租户签发的短码不得在 B 租户兑换。
        // 注意此处 payload 已被 pull 取走（包括不匹配的情况）—— 取走即销毁是对的，
        // 保留只会给重放留窗口。
        if ((int) ($payload['tenant_id'] ?? 0) !== $tenantId) {
            Log::warning('[ServiceDesk] scene 短码租户不匹配，已销毁', [
                'expected_tenant_id' => $tenantId,
                'code_length' => strlen($code),
            ]);

            return null;
        }

        $userId = (int) ($payload['user_id'] ?? 0);

        return $userId > 0 ? $userId : null;
    }

    /**
     * 短码是否合规（字符集与长度）
     *
     * 做输入校验而非直接查缓存：上游是渠道回调，值不可信。
     */
    public function isWellFormed(string $code): bool
    {
        if ($code === '' || strlen($code) > self::MAX_SCENE_BYTES) {
            return false;
        }

        return preg_match('/^[0-9a-zA-Z_-]+$/', $code) === 1;
    }

    /**
     * 组装短码：可选前缀 + '-' + 22 字符随机体
     *
     * 前缀只是便于在企微侧一眼看出来源，**不能因此顶破 32 字节上限**，
     * 故按剩余空间截断（超长前缀直接截短，不报错 —— 它不是安全要素）。
     */
    private function composeCode(): string
    {
        $body = rtrim(strtr(base64_encode(random_bytes(self::CODE_BYTES)), '+/', '-_'), '=');
        $prefix = $this->sanitizedPrefix();

        if ($prefix === '') {
            return $body;
        }

        $maxPrefixLength = self::MAX_SCENE_BYTES - strlen($body) - 1;

        if ($maxPrefixLength <= 0) {
            return $body;
        }

        $prefix = substr($prefix, 0, $maxPrefixLength);

        return $prefix === '' ? $body : $prefix . '-' . $body;
    }

    /**
     * 前缀清洗：渠道字符集只允许 [0-9a-zA-Z_-]，配置里写了别的字符会导致整个 scene 非法
     */
    private function sanitizedPrefix(): string
    {
        $prefix = (string) config('service-desk.identity_bridge.scene_prefix', 'sd');

        return (string) preg_replace('/[^0-9a-zA-Z_-]/', '', $prefix);
    }
}
