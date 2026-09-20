<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\SupportChannelContract;
use MultiTenantSaas\Services\Channel\ChannelManager;
use Throwable;

/**
 * 客服接待人员管理（Operator 面）
 *
 * 权限：`service_desk.servicer`（路由层挂 rbac.permission）。
 *
 * 接待人员列表**由渠道持有**（企微侧配置），框架不落库、不改写 —— 和接待态一样，
 * 渠道是唯一事实源。本地存一份只会与渠道漂移。
 *
 * 官方返回项有两种形态，接口统一归一化后返回：
 *   成员 `{userid, status, stop_type}` → `{type: 'user', userid, serving: bool, suspended: bool}`
 *   部门 `{department_id}`             → `{type: 'department', department_id}`
 * 不归一化的话，前端迟早出现「部门被当成 userid 显示成空白」这类毛病。
 */
class AdminServicerController extends Controller
{
    private const CHANNEL = 'wechat-kf';

    public function __construct(
        private readonly ChannelManager $channels,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'open_kfid' => 'required|string|max:64',
        ]);

        $driver = $this->supportDriver();

        if ($driver === null) {
            return $this->channelUnavailable();
        }

        $servicers = $driver->servicers((string) $validated['open_kfid']);

        if ($servicers === null) {
            return response()->json([
                'success' => false,
                'message' => '读取接待人员失败，请检查客服账号 ID 与渠道凭证（接待人员须在应用可见范围内）',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'servicers' => array_values(array_filter(array_map([$this, 'normalize'], $servicers))),
                // 官方上限，供配置台校验与提示
                'limits' => ['max_servicers' => 2000, 'max_departments' => 20],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'open_kfid' => 'required|string|max:64',
            'userid_list' => 'nullable|array|max:100',
            'userid_list.*' => 'string|max:64',
            'department_id_list' => 'nullable|array|max:20',
            'department_id_list.*' => 'integer',
        ]);

        $userIds = array_values($validated['userid_list'] ?? []);
        $departmentIds = array_values($validated['department_id_list'] ?? []);

        if ($userIds === [] && $departmentIds === []) {
            // 官方要求至少一个；提前在本地拦下，省一次注定失败的远程调用
            return response()->json([
                'success' => false,
                'message' => 'userid_list 与 department_id_list 至少需要提供一个',
            ], 422);
        }

        $driver = $this->supportDriver();

        if ($driver === null) {
            return $this->channelUnavailable();
        }

        try {
            $ok = $driver->addServicers((string) $validated['open_kfid'], $userIds, $departmentIds);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => '添加接待人员失败：' . $e->getMessage(),
            ], 502);
        }

        if (! $ok) {
            return response()->json([
                'success' => false,
                'message' => '添加接待人员失败，请检查客服账号 ID、成员是否在应用可见范围内',
            ], 502);
        }

        return response()->json(['success' => true, 'data' => ['added' => true]]);
    }

    /**
     * 归一化官方两种列表项
     *
     * @return array<string, mixed>|null 无法识别返回 null（由调用方过滤）
     */
    private function normalize(mixed $item): ?array
    {
        if (! is_array($item)) {
            return null;
        }

        $userid = $item['userid'] ?? null;

        if (is_string($userid) && $userid !== '') {
            return [
                'type' => 'user',
                'userid' => $userid,
                // status: 0 接待中 / 1 停止接待
                'serving' => (int) ($item['status'] ?? 0) === 0,
                'suspended' => (int) ($item['stop_type'] ?? 0) === 1,
            ];
        }

        $departmentId = $item['department_id'] ?? null;

        if (is_numeric($departmentId)) {
            return ['type' => 'department', 'department_id' => (int) $departmentId];
        }

        return null;
    }

    /**
     * 取渠道的客服能力面（不支持则 null）
     */
    private function supportDriver(): ?SupportChannelContract
    {
        $tenantId = (int) TenantContext::getId();

        if ($tenantId <= 0 || ! $this->channels->hasDriver(self::CHANNEL)) {
            return null;
        }

        try {
            $driver = $this->channels->resolve(self::CHANNEL, $tenantId);
        } catch (Throwable) {
            return null;
        }

        return $driver instanceof SupportChannelContract ? $driver : null;
    }

    private function channelUnavailable(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => '微信客服渠道当前不可用（未接入或凭证无效）',
        ], 404);
    }
}
