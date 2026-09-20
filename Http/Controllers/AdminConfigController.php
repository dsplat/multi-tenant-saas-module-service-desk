<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\ServiceDesk\Services\ServiceDeskSettings;
use MultiTenantSaas\Modules\ServiceDesk\Services\SupportAgentResolver;

/**
 * 客服配置台（Operator 面）
 *
 * 权限：`service_desk.view` / `service_desk.config`（路由层挂 rbac.permission）。
 *
 * 写入**只覆盖模块自己的租户级设置**（转人工阈值、开关、历史轮数）。
 * 客服 Agent 的人设与模型档位**不在这里改** —— 那属于 Agent 资源，走既有的
 * `/api/v1/agents/{id}`。两个入口写同一批字段迟早出现校验不一致
 * （一边收下非法 model 一边拒掉），所以这里只返回 Agent 引用、让控制台跳过去改。
 */
class AdminConfigController extends Controller
{
    public function __construct(
        private readonly ServiceDeskSettings $settings,
        private readonly SupportAgentResolver $agents,
    ) {}

    public function show(): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        if ($tenantId <= 0) {
            return response()->json(['success' => false, 'message' => '缺少租户上下文'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'settings' => $this->settings->describe($tenantId),
                'agent' => $this->agentSummary($tenantId),
                'channels' => (array) config('service-desk.channels', []),
                // 对外可用工具面（执行咽喉据此放行）—— 只读展示，改动走 user-ai 配置
                'tool_surface' => (array) config('user-ai.tool_surface.allowed', []),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();

        if ($tenantId <= 0) {
            return response()->json(['success' => false, 'message' => '缺少租户上下文'], 403);
        }

        $validated = $request->validate([
            'settings' => 'required|array|min:1',
            'settings.*' => 'nullable',
        ]);

        $unknown = array_diff(array_keys($validated['settings']), array_keys(ServiceDeskSettings::EDITABLE));

        if ($unknown !== []) {
            // 明确拒绝而不是静默忽略：控制台拼错键名时应当立刻发现，
            // 而不是以为改成功了、实际没生效
            return response()->json([
                'success' => false,
                'message' => '存在不可修改的设置项：' . implode(', ', $unknown),
            ], 422);
        }

        foreach ($validated['settings'] as $key => $value) {
            $this->settings->set($tenantId, (string) $key, $value);
        }

        return response()->json([
            'success' => true,
            'data' => ['settings' => $this->settings->describe($tenantId)],
        ]);
    }

    /**
     * 客服 Agent 概要（会话绑定的角色 + 当前生效的 Agent）
     *
     * @return array<string, mixed>
     */
    private function agentSummary(int $tenantId): array
    {
        $agent = $this->agents->resolveDefaultFor($tenantId);

        if ($agent === null) {
            return [
                'role' => SupportAgentResolver::SUPPORT_ROLE,
                'agent_id' => null,
                'configured' => false,
                'hint' => '尚未配置客服 Agent，当前使用框架内置提示词与人设',
            ];
        }

        return [
            'role' => SupportAgentResolver::SUPPORT_ROLE,
            'agent_id' => (int) $agent->agent_id,
            'name' => (string) $agent->name,
            'enabled' => (bool) $agent->enabled,
            'configured' => true,
            'model_config' => (array) ($agent->model_config ?? []),
            // 人设只回摘要：完整提示词请走 Agent 详情接口
            'persona_preview' => mb_substr($agent->effectiveSystemPrompt(), 0, 120),
        ];
    }
}
