<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Listeners;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Events\ChannelEventReceived;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\ServiceDesk\Services\IdentityBridgeService;
use MultiTenantSaas\Modules\ServiceDesk\Services\SceneCodeService;
use MultiTenantSaas\Modules\ServiceDesk\Services\SupportSessionService;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 客服渠道事件接线
 *
 * 挂框架的 `ChannelEventReceived`（渠道无关的事件总入口），处理两类与客服相关的事件：
 *
 * 1. `enter_session`（用户进入会话）
 *    携带场景值（我们放的是 32 字节内的 scene 短码）。兑换出 user_id 后记一条
 *    **待绑定** —— 此刻会话往往还没创建（会话由首条消息触发创建），真正的关联
 *    由 {@see HandleInboundSupportMessage} 在会话出现时完成。
 *
 * 2. `session_status_change`（会话状态变更）
 *    接待人员在企微客户端接入 / 转接 / 结束会话时触发，是**接待态镜像**的事件源。
 *    注意官方注明该事件「均为接待人员在企业微信客户端操作触发」—— 我们自己经 API
 *    发起的转人工不会产生它，那种情况必须在调用后回读渠道态再写镜像（见 HandoffService）。
 *
 * 有意不做的事：
 *   - 不在此处创建会话（会话归属 ConversationRouter，事件不该凭空造会话）
 *   - 不在此处发消息（欢迎语属应答链路）
 */
class HandleChannelEvent
{
    /**
     * session_status_change 的 change_type → 接待态镜像
     *
     * 1 从接待池接入会话 / 2 转接会话 / 4 重新接入已结束或已转接会话 → 人工接待中
     * 3 结束会话 → 会话已结束
     *
     * 数值对齐 WechatWorkApiClient::KF_STATE_*；此处写字面量而非引 SDK 常量，
     * 避免 ServiceDesk 对 WechatWork 模块产生编译期依赖（未装该模块时应可运行）。
     */
    private const HUMAN_SERVING_CHANGE_TYPES = [1, 2, 4];

    private const STATE_HUMAN = 3;

    private const STATE_CLOSED = 4;

    public function __construct(
        private readonly SceneCodeService $sceneCodes,
        private readonly IdentityBridgeService $identity,
        private readonly SupportSessionService $sessions,
    ) {}

    public function handle(ChannelEventReceived $event): void
    {
        if (! in_array($event->channel, (array) config('service-desk.channels', []), true)) {
            return;
        }

        // 事件来自渠道回调，**无认证上下文**；处理期以事件里的租户为准，用毕还原
        // （与 HandleInboundSupportMessage 同一约定：不改变调用方的上下文状态）。
        $previousTenantId = TenantContext::getId();
        TenantContext::setTenantId((string) $event->tenantId);

        try {
            match ($event->eventType) {
                'enter_session' => $this->handleEnterSession($event),
                'session_status_change' => $this->handleSessionStatusChange($event),
                default => null,
            };
        } catch (\Throwable $e) {
            // 接线失败不得影响 webhook ACK（事件已消费，渠道不应重试）
            Log::error('[ServiceDesk] 渠道事件处理失败', [
                'tenant_id' => $event->tenantId,
                'channel' => $event->channel,
                'event_type' => $event->eventType,
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
     * 用户进入会话 → 兑换场景短码 → 记待绑定
     */
    private function handleEnterSession(ChannelEventReceived $event): void
    {
        $scene = $this->sceneValue($event);

        if ($scene === '') {
            // 没带场景值就是普通进入（用户从会话列表点进来不会带），无需桥接
            return;
        }

        $userId = $this->sceneCodes->consume($scene, $event->tenantId);

        if ($userId === null) {
            // 过期 / 已用过 / 伪造 / 跨租户 —— 一律静默降级为「未识别用户」，
            // 不打断会话（用户仍可提问，只是关联不到账号）
            Log::info('[ServiceDesk] scene 短码未能兑换，按未识别用户处理', [
                'tenant_id' => $event->tenantId,
                'scene_length' => strlen($scene),
            ]);

            return;
        }

        $this->identity->rememberPending(
            tenantId: $event->tenantId,
            externalConvId: $event->conversationExternalId,
            userId: $userId,
            source: 'enter_session',
        );
    }

    /**
     * 会话状态变更 → 更新接待态镜像
     */
    private function handleSessionStatusChange(ChannelEventReceived $event): void
    {
        $inner = $this->eventBody($event);
        $changeType = (int) ($inner['change_type'] ?? 0);

        if ($changeType === 0) {
            return;
        }

        $conversation = $this->findConversation($event);

        if ($conversation === null) {
            // 尚无会话（用户还没发过消息）—— 没有可镜像的对象，等消息进来时以渠道回读为准
            return;
        }

        if ($changeType === 3) {
            $this->sessions->markState($conversation, self::STATE_CLOSED);

            return;
        }

        if (in_array($changeType, self::HUMAN_SERVING_CHANGE_TYPES, true)) {
            $servicer = (string) ($inner['new_servicer_userid'] ?? '');

            $this->sessions->markState(
                $conversation,
                self::STATE_HUMAN,
                $servicer !== '' ? $servicer : null,
            );
        }
    }

    /**
     * 取场景值
     *
     * 官方结构里 `scene` 与 `scene_param` 都在 `event` 下：
     *   scene       开发者自定义场景值（本模块放短码，因为 ≤32 字节约束落在 scene 上）
     *   scene_param 链接里另拼的参数
     * 两者都看一下，谁带短码就用谁 —— 宿主若改用 scene_param 传参也不必改这里。
     */
    private function sceneValue(ChannelEventReceived $event): string
    {
        $inner = $this->eventBody($event);

        foreach (['scene', 'scene_param'] as $key) {
            $value = trim((string) ($inner[$key] ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * 事件体（官方结构：字段嵌在 `event` 下）
     *
     * @return array<string, mixed>
     */
    private function eventBody(ChannelEventReceived $event): array
    {
        $inner = $event->payload['event'] ?? [];

        return is_array($inner) ? $inner : [];
    }

    /**
     * 按渠道会话标识找会话
     *
     * ⚠ 必须绕过 TenantScope：渠道回调无租户上下文，而 TenantScope 在无上下文时
     * fail-closed（WHERE 1=0），用普通查询会恒返回 null、监听器静默失效。
     * 这里显式带上 tenant_id 作为隔离条件。
     */
    private function findConversation(ChannelEventReceived $event): ?Conversation
    {
        if ($event->conversationExternalId === '') {
            return null;
        }

        return Conversation::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $event->tenantId)
            ->where('channel', $event->channel)
            ->where('status', 'active')
            ->where('metadata->external_conv_id', $event->conversationExternalId)
            ->first();
    }
}
