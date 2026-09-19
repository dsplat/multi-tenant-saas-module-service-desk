<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\SupportChannelContract;
use MultiTenantSaas\Modules\ServiceDesk\Services\SceneCodeService;
use MultiTenantSaas\Services\Channel\ChannelManager;
use Throwable;

/**
 * 入口带参链接（终端用户侧）
 *
 * 身份桥接的起点：宿主（首个场景是小程序）请求后端签发带参客服链接，用户点击后
 * 进入客服会话，渠道把场景值原样带回，后端据此把渠道身份关联到系统用户。
 *
 * 安全边界（这是本接口唯一的安全职责）：
 * 短码**只能由服务端签发**，且绑定**当前登录用户** —— 用户 ID 取自认证上下文，
 * 绝不接受请求体传入。若允许客户端指定 user_id，等于把任意账号的身份交出去。
 *
 * 鉴权：走 `Routes/tenant.php`（api + auth:sanctum + tenant.identify）。
 * 终端用户不是 Operator，因此**不加 rbac.permission**（RBAC 只作用于 Operator）。
 */
class EntryLinkController extends Controller
{
    public function __construct(
        private readonly SceneCodeService $sceneCodes,
        private readonly ChannelManager $channels,
    ) {}

    /**
     * 签发入口链接
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // 院系维度：一个院系 = 一个客服账号（open_kfid）
            'open_kfid' => 'required|string|max:64',
            'channel' => 'nullable|string|max:32',
        ]);

        $tenantId = (int) TenantContext::getId();
        $userId = (int) ($request->user()->user_id ?? 0);

        if ($tenantId <= 0 || $userId <= 0) {
            return response()->json([
                'success' => false,
                'message' => '缺少租户或用户上下文，无法签发入口链接',
            ], 403);
        }

        $channel = (string) ($validated['channel'] ?? 'wechat-kf');
        $driver = $this->supportDriver($channel, $tenantId);

        if ($driver === null) {
            // 渠道未接入 / 未开通 / 不支持客服语义 —— 明确报错，不返回一个点进去没反应的链接
            return response()->json([
                'success' => false,
                'message' => "渠道 [{$channel}] 当前不可用于客服入口（未接入或未开通）",
            ], 404);
        }

        $scene = $this->sceneCodes->issue($tenantId, $userId);

        $url = $driver->supportEntryLink((string) $validated['open_kfid'], $scene);

        if ($url === null) {
            // 短码已签发但链接没拿到：无需回收（TTL 内自然过期，且一次性消费），
            // 客户端可重试 —— 重试会签发新短码，不会留下可用的孤儿短码。
            return response()->json([
                'success' => false,
                'message' => '客服链接生成失败，请检查渠道凭证是否有效',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'url' => $url,
                'scene' => $scene,
                'expires_in' => max(1, (int) config('service-desk.identity_bridge.scene_ttl', 900)),
            ],
        ]);
    }

    /**
     * 取渠道的客服能力面；不支持则 null
     *
     * 用 instanceof 显式探测能力，而不是假定「有驱动就能做客服」——
     * 企微自建应用、公众号等驱动虽有 ChannelContract，却没有接待态与客服账号概念。
     */
    private function supportDriver(string $channel, int $tenantId): ?SupportChannelContract
    {
        if (! $this->channels->hasDriver($channel)) {
            return null;
        }

        try {
            $driver = $this->channels->resolve($channel, $tenantId);
        } catch (Throwable) {
            // 凭证缺失或驱动构造失败，等价于「该渠道当前不可用」
            return null;
        }

        return $driver instanceof SupportChannelContract ? $driver : null;
    }
}
