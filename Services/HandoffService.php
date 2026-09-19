<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\SupportChannelContract;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Services\Channel\ChannelManager;
use Throwable;

/**
 * 转人工 —— 接待态的读写与流转
 *
 * 一条铁律：**接待态由渠道托管，渠道是唯一事实源**。本地 `conversations.metadata`
 * 只是镜像。因此本服务的写法是「改渠道 → 立刻回读渠道 → 用回读结果写镜像」，
 * 而不是「本地记一笔就完事」—— 后者会在渠道拒绝或状态被别人改掉时留下错误镜像。
 *
 * 触发条件（任一命中即转，具体名单由场景经扩展点扩充，见设计 §3.4）：
 *   1. 用户主动要求转人工
 *   2. 风险拦截器命中（强制）
 *   3. 连续 N 轮未命中知识库
 *   4. AI 不可用（降级为转人工，而不是整条链路报错）
 */
class HandoffService
{
    /**
     * 转人工的原因码
     */
    public const REASON_VISITOR_REQUEST = 'visitor_request';

    public const REASON_RISK = 'risk';

    public const REASON_UNRESOLVED_TURNS = 'unresolved_turns';

    public const REASON_AI_UNAVAILABLE = 'ai_unavailable';

    /** 渠道接待态：待接入池排队中 */
    private const STATE_QUEUED = 2;

    /** 渠道接待态：指定接待人员 */
    private const STATE_HUMAN = 3;

    public function __construct(
        private readonly SupportSessionService $sessions,
        private readonly ChannelManager $channels,
    ) {}

    /**
     * 转人工
     *
     * @param  string  $reason  见 REASON_*
     *                          只做「渠道流转 + 写镜像 + 记元数据」，**不负责通知** ——
     *                          通知由调用方派发 SupportHandoffRequested：风险场景下即使渠道流转失败也必须通知到人，
     *                          把通知绑在本方法的成功分支里会让「渠道一挂、人也不知道」。
     * @param  string|null  $servicerUserId  指定接待人员；null 则进待接入池排队
     * @return bool 渠道侧是否已成功流转
     */
    public function toHuman(
        Conversation $conversation,
        string $reason,
        ?string $servicerUserId = null,
    ): bool {
        $driver = $this->supportDriver($conversation);
        $identity = $this->externalIdentity($conversation);

        if ($driver === null || $identity === null) {
            // 渠道不支持客服语义 / 会话缺少渠道身份 —— 无法在渠道侧流转。
            // 注意此处**不写镜像**：写一个本地编出来的接待态，正是「两套状态源漂移」的开端。
            Log::warning('[ServiceDesk] 无法在渠道侧转人工', [
                'tenant_id' => (int) $conversation->tenant_id,
                'conversation_id' => $conversation->conversation_id,
                'channel' => (string) $conversation->channel,
                'reason' => $reason,
            ]);

            return false;
        }

        [$openKfId, $externalUserId] = $identity;
        $targetState = $servicerUserId !== null && $servicerUserId !== ''
            ? self::STATE_HUMAN
            : self::STATE_QUEUED;

        $result = $driver->transServiceState($openKfId, $externalUserId, $targetState, $servicerUserId);

        if ($result === null) {
            // 渠道拒绝（如 state=3 的接待人员未激活 → 企微 95014）：不写镜像、不广播，
            // 让调用方按「转人工失败」处理（AI 可选性铁律：宁可继续答，不可装作已转）
            Log::error('[ServiceDesk] 渠道转人工失败', [
                'tenant_id' => (int) $conversation->tenant_id,
                'conversation_id' => $conversation->conversation_id,
                'target_state' => $targetState,
                'reason' => $reason,
            ]);

            return false;
        }

        // 以渠道为唯一事实源：回读后写镜像，而不是假定刚设的状态生效了
        $this->syncState($conversation, $driver, $openKfId, $externalUserId);

        $this->recordHandoff($conversation, $reason, $servicerUserId, $result['msg_code'] ?? null);

        return true;
    }

    /**
     * 回读渠道接待态并写镜像（一致性校准）
     *
     * @return array{service_state: int, servicer_userid: ?string}|null
     */
    public function syncState(
        Conversation $conversation,
        ?SupportChannelContract $driver = null,
        ?string $openKfId = null,
        ?string $externalUserId = null,
    ): ?array {
        $driver ??= $this->supportDriver($conversation);

        if ($driver === null) {
            return null;
        }

        if ($openKfId === null || $externalUserId === null) {
            $identity = $this->externalIdentity($conversation);

            if ($identity === null) {
                return null;
            }

            [$openKfId, $externalUserId] = $identity;
        }

        $state = $driver->serviceState($openKfId, $externalUserId);

        if ($state === null) {
            return null;
        }

        $this->sessions->markState($conversation, $state['service_state'], $state['servicer_userid']);

        return $state;
    }

    /**
     * 记录转人工元数据（度量用：排队时刻、原因、接待人员）
     */
    private function recordHandoff(
        Conversation $conversation,
        string $reason,
        ?string $servicerUserId,
        ?string $msgCode,
    ): void {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];

        $conversation->metadata = $metadata + [
            'handoff_reason' => $reason,
            'handoff_at' => now()->toIso8601String(),
            // 度量口径（§5.1）：排队时刻用于算「首次响应时长」
            'queued_at' => $metadata['queued_at'] ?? now()->toIso8601String(),
        ];

        if ($servicerUserId !== null && $servicerUserId !== '') {
            $conversation->metadata = $conversation->metadata + ['handoff_servicer' => $servicerUserId];
        }

        if ($msgCode !== null && $msgCode !== '') {
            // 渠道在变更为 2 / 3 时返回接待语 code，需要用 send_msg_on_event 下发
            $conversation->metadata = $conversation->metadata + ['handoff_msg_code' => $msgCode];
        }

        $conversation->save();
    }

    /**
     * 取渠道的客服能力面（不支持返回 null）
     *
     * ChannelManager::hasDriver 只说明「装了驱动」，不代表会客服；必须 instanceof 探测。
     */
    private function supportDriver(Conversation $conversation): ?SupportChannelContract
    {
        $channel = (string) ($conversation->channel ?? '');
        $tenantId = (int) $conversation->tenant_id;

        if ($channel === '' || $tenantId <= 0 || ! $this->channels->hasDriver($channel)) {
            return null;
        }

        try {
            $driver = $this->channels->resolve($channel, $tenantId);
        } catch (Throwable) {
            return null;
        }

        return $driver instanceof SupportChannelContract ? $driver : null;
    }

    /**
     * 解析渠道侧身份：`external_conv_id` = "{open_kfid}:{external_userid}"
     *
     * @return array{0: string, 1: string}|null
     */
    private function externalIdentity(Conversation $conversation): ?array
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $externalConvId = (string) ($metadata['external_conv_id'] ?? '');

        if ($externalConvId === '') {
            return null;
        }

        $parts = explode(':', $externalConvId, 2);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }

        return [$parts[0], $parts[1]];
    }
}
