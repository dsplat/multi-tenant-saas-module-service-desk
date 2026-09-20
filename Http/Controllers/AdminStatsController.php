<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\ServiceDesk\Services\SupportStatsService;

/**
 * 客服度量看板（Operator 面）
 *
 * 权限：`service_desk.view`（路由层挂 rbac.permission）。
 * 指标口径与限制见 {@see SupportStatsService} 的类注释 ——
 * 「AI 解决」是代理口径、「首次响应」只含 AI 侧，这两点在接口里一并返回，
 * 免得看板只拿到数字却不知道数字的含义。
 */
class AdminStatsController extends Controller
{
    public function __construct(
        private readonly SupportStatsService $stats,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'nullable|date',
            'to' => 'nullable|date|after_or_equal:from',
        ]);

        $tenantId = (int) TenantContext::getId();

        if ($tenantId <= 0) {
            return response()->json(['success' => false, 'message' => '缺少租户上下文'], 403);
        }

        $summary = $this->stats->summary(
            tenantId: $tenantId,
            from: isset($validated['from']) ? Carbon::parse($validated['from']) : null,
            to: isset($validated['to']) ? Carbon::parse($validated['to']) : null,
        );

        return response()->json([
            'success' => true,
            'data' => $summary + [
                'notes' => [
                    'ai_resolved' => '代理口径：无转人工且无工单即视为 AI 闭环，不等于用户满意',
                    'first_response' => '仅含 AI 侧响应；人工回复发生在企微工作台，origin=5 未入库',
                ],
            ],
        ]);
    }
}
