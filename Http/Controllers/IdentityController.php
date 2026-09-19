<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\ServiceDesk\Services\IdentityUpgradeService;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 身份提升（终端用户侧）
 *
 * 从 authenticated 提升到 verified，提升后会话可达的工具集从「通用 + 与我相关」
 * 扩展到「个人信息查询」。
 *
 * 安全边界（本接口的要害）：
 * **只能提升当前登录用户自己的会话**。会话归属以 `conversations.created_by`
 * 为准（由身份桥接写入），绝不能由请求体指定 —— 否则任何人只要猜到会话 ID
 * 就能把别人的会话提升到已核身，再去查别人的课表与成绩。
 *
 * 核验材料由场景核验（`IdentityUpgradeContract`）；本接口只做归属校验与转发，
 * 不参与「谁是本人」的判定。
 */
class IdentityController extends Controller
{
    public function __construct(
        private readonly IdentityUpgradeService $upgrade,
    ) {}

    public function promote(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'conversation_id' => 'required|integer',
            'payload' => 'nullable|array',
        ]);

        $tenantId = (int) TenantContext::getId();
        $userId = (int) ($request->user()->user_id ?? 0);

        if ($tenantId <= 0 || $userId <= 0) {
            return response()->json([
                'success' => false,
                'message' => '缺少租户或用户上下文，无法执行核验',
            ], 403);
        }

        $conversation = $this->findConversation($tenantId, (int) $validated['conversation_id']);

        if ($conversation === null) {
            return response()->json(['success' => false, 'message' => '会话不存在'], 404);
        }

        // 会话必须先关联到当前用户（身份桥接的产物），且只能提升自己的会话
        if ((int) $conversation->created_by !== $userId) {
            return response()->json([
                'success' => false,
                'message' => '该会话未关联到当前账号，无法执行核验',
            ], 403);
        }

        $result = $this->upgrade->promote($conversation, $validated['payload'] ?? []);

        if (! $result->ok) {
            return response()->json([
                'success' => false,
                'message' => $result->message ?? '核验未通过',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'level' => $result->level,
                'message' => $result->message,
                'can_promote' => $this->upgrade->canPromote($conversation),
            ],
        ]);
    }

    /**
     * 取会话
     *
     * 走 withoutGlobalScope + 显式 tenant_id：即便 TenantContext 已在
     * tenant.identify 里解析，显式条件也让隔离不依赖中间件行为。
     */
    private function findConversation(int $tenantId, int $conversationId): ?Conversation
    {
        return Conversation::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $conversationId)
            ->first();
    }
}
