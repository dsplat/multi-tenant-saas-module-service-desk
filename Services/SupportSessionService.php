<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Services;

use MultiTenantSaas\Modules\Conversation\Models\Conversation;

/**
 * 客服会话服务 —— 接待态镜像与轮次计数
 *
 * **零业务新表**：全部落 conversations.metadata（见 docs/service-desk-design.md §5.1）。
 *
 * 关于接待态的所有权（重要）：
 * 接待态由渠道（微信客服）托管，本地只是**镜像**。一致性问题一律以渠道为准
 * （回读 kf/service_state/get）；本服务只负责写入镜像值，不承担判定责任。
 * 渠道态读取在 M1-E 接入（需给渠道契约加服务态能力）。
 */
class SupportSessionService
{
    /**
     * 确保会话具备客服镜像字段（幂等）
     *
     * 首次见到一条客服会话时调用，写入初始接待态 —— 新会话由 AI 接待
     * （对应微信客服 service_state=1「由智能助手接待」）。
     */
    public function ensureSession(Conversation $conversation): void
    {
        $metadata = $this->metadata($conversation);

        if (array_key_exists($this->key('state'), $metadata)) {
            return;
        }

        $metadata[$this->key('state')] = $this->initialState();
        $metadata[$this->key('state_updated')] = now()->toIso8601String();
        $metadata[$this->key('unresolved')] = 0;

        $this->persist($conversation, $metadata);
    }

    /**
     * 读取镜像的接待态；未初始化返回 null
     */
    public function getState(Conversation $conversation): ?int
    {
        $state = $this->metadata($conversation)[$this->key('state')] ?? null;

        return $state !== null ? (int) $state : null;
    }

    /**
     * 写入接待态镜像
     *
     * @param  int|null  $state  null 表示渠道未返回，不覆盖既有镜像
     */
    public function markState(Conversation $conversation, ?int $state, ?string $servicerUserId = null): void
    {
        if ($state === null) {
            return;
        }

        $metadata = $this->metadata($conversation);
        $metadata[$this->key('state')] = $state;
        $metadata[$this->key('state_updated')] = now()->toIso8601String();

        if ($servicerUserId !== null && $servicerUserId !== '') {
            $metadata[$this->key('servicer')] = $servicerUserId;
        }

        $this->persist($conversation, $metadata);
    }

    /**
     * 当前接待人员（镜像值，可能为 null）
     */
    public function getServicer(Conversation $conversation): ?string
    {
        $servicer = $this->metadata($conversation)[$this->key('servicer')] ?? null;

        return is_string($servicer) && $servicer !== '' ? $servicer : null;
    }

    /**
     * AI 是否仍在接待（可自主回复）
     *
     * 人工接待中 AI 不得插话 —— 判定依据是渠道接待态。
     */
    public function isAiServing(Conversation $conversation): bool
    {
        $state = $this->getState($conversation);

        // 未初始化按 AI 接待处理（新会话的默认态）
        return $state === null || in_array($state, $this->aiStates(), true);
    }

    /**
     * 累加「连续未命中」轮数并返回新值
     *
     * 用于转人工判定：连续 N 轮未命中知识库 → 转人工。
     */
    public function incrementUnresolved(Conversation $conversation): int
    {
        $metadata = $this->metadata($conversation);
        $turns = (int) ($metadata[$this->key('unresolved')] ?? 0) + 1;

        $metadata[$this->key('unresolved')] = $turns;
        $this->persist($conversation, $metadata);

        return $turns;
    }

    /**
     * 命中知识库后清零
     */
    public function resetUnresolved(Conversation $conversation): void
    {
        $metadata = $this->metadata($conversation);

        if ((int) ($metadata[$this->key('unresolved')] ?? 0) === 0) {
            return;
        }

        $metadata[$this->key('unresolved')] = 0;
        $this->persist($conversation, $metadata);
    }

    public function getUnresolvedTurns(Conversation $conversation): int
    {
        return (int) ($this->metadata($conversation)[$this->key('unresolved')] ?? 0);
    }

    // ─── 内部 ───

    /**
     * @return array<string, mixed>
     */
    private function metadata(Conversation $conversation): array
    {
        $metadata = $conversation->metadata;

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function persist(Conversation $conversation, array $metadata): void
    {
        $conversation->metadata = $metadata;
        $conversation->save();
    }

    /**
     * 配置键前缀（避免与其它模块写在 metadata 下的字段撞名）
     */
    private function key(string $name): string
    {
        return match ($name) {
            'state' => (string) config('service-desk.state_sync.state_key', 'service_state'),
            'state_updated' => (string) config('service-desk.state_sync.state_updated_key', 'service_state_updated_at'),
            'servicer' => (string) config('service-desk.state_sync.servicer_key', 'servicer_userid'),
            'unresolved' => 'service_unresolved_turns',
            default => 'service_' . $name,
        };
    }

    /**
     * 新会话的默认接待态：由智能助手接待
     *
     * 与 WechatWorkApiClient::KF_STATE_AI_ASSISTANT 对齐；此处不引 SDK 常量，
     * 避免 ServiceDesk 对 WechatWork 模块产生编译期依赖（未装该模块时应可运行）。
     */
    private function initialState(): int
    {
        return 1;
    }

    /**
     * 视为「AI 可自主回复」的接待态
     *
     * 0 未处理 / 1 由智能助手接待；2 排队中、3 人工接待、4 已结束均不可由 AI 回复。
     *
     * @return array<int, int>
     */
    private function aiStates(): array
    {
        return [0, 1];
    }
}
