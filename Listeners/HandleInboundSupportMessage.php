<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Listeners;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Events\MessageReceived;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\ServiceDesk\Events\SupportMessageReceived;
use MultiTenantSaas\Modules\ServiceDesk\Services\IdentityBridgeService;
use MultiTenantSaas\Modules\ServiceDesk\Services\SupportAgentResolver;
use MultiTenantSaas\Modules\ServiceDesk\Services\SupportSessionService;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 客服入站接线：把渠道入站消息接入客服场景
 *
 * 挂框架的 `MessageReceived`（渠道无关的入站总事件），职责四件：
 *   1. **识别**：这条消息是否属于客服场景（渠道 + 会话类型）
 *   2. **初始化**：补齐租户上下文与接待态镜像（新会话默认由 AI 接待）
 *   3. **绑定客服 Agent**：把会话固定到一个「客服 Agent」上（人设与模型档位由它决定）
 *   4. **身份绑定**：若此前有「待绑定」（用户在进入会话事件里带来了 scene 短码），
 *      此刻会话已存在，把身份落到会话上
 *   5. **派发**：发出 SupportMessageReceived，供后续能力挂载
 *
 * 第 3 步为什么在这里而不是事件监听器里：会话由 ConversationRouter 在**首条消息**
 * 时才创建，而 enter_session 事件通常早于首条消息，那时无会话可绑 —— 两块必须分开。
 *
 * 有意**不做**的事（各自有归属，不塞进本监听器）：
 *   - AI 应答 → M1-D，挂 SupportMessageReceived，走队列（webhook 要求收到即 ACK）
 *   - 风险拦截 → M1-F，挂 SupportMessageReceived
 *   - 渠道接待态读取 → M1-E，需给渠道契约加服务态能力
 *
 * 运行上下文：由 webhook 触发，**无认证上下文**，租户从会话取；
 * 本监听器仅在当前请求内写上下文，结束即清（不依赖调用方善后）。
 */
class HandleInboundSupportMessage
{
    public function __construct(
        private readonly SupportSessionService $sessions,
        private readonly IdentityBridgeService $identity,
        private readonly SupportAgentResolver $agents,
    ) {}

    public function handle(MessageReceived $event): void
    {
        $channel = $event->channel;

        if (! $this->isSupportChannel($channel)) {
            return;
        }

        $conversation = $this->resolveConversation($event);

        if ($conversation === null) {
            return;
        }

        $tenantId = (int) $conversation->tenant_id;

        // 处理期间必须以该会话的租户为上下文（消息归属先于既有上下文）。
        // 用完**还原**而非粗暴清除：本监听器不应改变调用方的上下文状态。
        $previousTenantId = TenantContext::getId();
        TenantContext::setTenantId((string) $tenantId);

        try {
            // 会话初始化（幂等）：写入接待态镜像初始值
            if ((bool) config('service-desk.state_sync.enabled', true)) {
                $this->sessions->ensureSession($conversation);
            }

            // 绑定客服 Agent（幂等）：它的 system_prompt / model_config 决定这条会话的
            // AI 人设与模型档位。未配置客服 Agent 时返回 null，链路回落到内置提示词。
            $this->agents->bindTo($conversation);

            $this->applyPendingIdentity($conversation, $tenantId);

            SupportMessageReceived::dispatch($conversation, $event->message, $channel);
        } catch (\Throwable $e) {
            // 接线失败不阻断既有落库链路（消息已入库），记录后可观测
            Log::error('[ServiceDesk] 客服入站处理失败', [
                'tenant_id' => $tenantId,
                'conversation_id' => $conversation->conversation_id,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($previousTenantId === null) {
                TenantContext::clear();
            } else {
                TenantContext::setTenantId($previousTenantId);
            }
        }
    }

    /**
     * 落身份绑定（若有待绑定）
     *
     * 用户点带参链接进入会话时，短码已在 HandleChannelEvent 里兑换成 user_id 并存为
     * 待绑定；此刻会话刚被创建，是把它落到 conversations.created_by 的时机。
     */
    private function applyPendingIdentity(Conversation $conversation, int $tenantId): void
    {
        $externalConvId = (string) (($conversation->metadata ?? [])['external_conv_id'] ?? '');

        if ($externalConvId === '') {
            return;
        }

        $pending = $this->identity->pending($tenantId, $externalConvId);

        if ($pending === null) {
            return;
        }

        $userId = (int) ($pending['user_id'] ?? 0);

        // 无论绑定成功与否都清掉待绑定：留着只会让后续换人进入时贴回旧绑定。
        // 失败路径（已绑到别人）由 linkConversation 内部记日志。
        $this->identity->linkConversation($conversation, $userId, $pending['source'] ?? null);
        $this->identity->forgetPending($tenantId, $externalConvId);
    }

    /**
     * 是否客服渠道
     *
     * `wechat-kf` = 微信客服（企微接管版），首个场景的唯一入站渠道。
     * 未来接公众号 / 网页客服时在此扩展。
     */
    private function isSupportChannel(string $channel): bool
    {
        return in_array($channel, ['wechat-kf', 'wechat_kf'], true);
    }

    /**
     * 取会话
     *
     * ⚠ 必须绕过 TenantScope：webhook 场景**尚无租户上下文**，而 TenantScope
     * 在无上下文时 fail-closed（WHERE 1=0），直接用 find() 会恒返回 null —— 监听器
     * 会静默失效。这与 docs/channel.md 里「webhook 无认证上下文，租户解析硬豁免
     * TenantScope」是同一处理。
     *
     * 安全性：conversation_id 来自刚落库的 message（服务端生成），非外部可控。
     */
    private function resolveConversation(MessageReceived $event): ?Conversation
    {
        $conversationId = $event->message->conversation_id ?? null;

        if ($conversationId === null) {
            return null;
        }

        return Conversation::withoutGlobalScope(TenantScope::class)
            ->find($conversationId);
    }
}
