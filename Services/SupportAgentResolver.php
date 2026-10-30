<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 客服 Agent 解析与绑定
 *
 * 客服会话要有一个「客服 Agent」来决定它的 AI 行为：人设、模型档位、（将来）知识库范围。
 * `conversations.agent_id` 字段一直存在但从未被写入 —— 于是客服链路只能用硬编码提示词，
 * 学校无法配置自己的人设。本类把这条线接上。
 *
 * 解析顺序：
 *   1. 会话已绑定的 agent（且该 agent 仍启用）—— 尊重既有绑定
 *   2. 租户下 `role = customer_service` 的启用 Agent（取 agent_id 最小的，稳定可预期）
 *   3. 都没有 → null，调用方回落到框架内置提示词（**不配也能用**）
 *
 * 为什么按 role 找而不是按名字：模板键（role）是框架定义的稳定标识，
 * 名字是租户可改的展示字段。
 */
class SupportAgentResolver
{
    /** 客服 Agent 的模板键（见 BuiltinAgentTemplates 的 customer_service） */
    public const SUPPORT_ROLE = 'customer_service';

    /**
     * 解析会话应使用的客服 Agent（不写库）
     *
     * **尽力而为**：Agent 属可选配置，查不到/查不动一律返回 null，调用方回落到
     * 框架内置提示词。绝不能因为「Agent 表不存在」或一次查询失败就把整条应答打断
     * （实测踩过：Ai 模块未装时 agents 表不存在，异常冒到编排层会把每条消息都判成
     * 「AI 应答异常」进而转人工 —— 一个可选环节拖垮了主链路）。
     */
    public function resolveFor(Conversation $conversation): ?Agent
    {
        try {
            return $this->boundAgent($conversation)
                ?? $this->defaultSupportAgent((int) $conversation->tenant_id);
        } catch (\Throwable $e) {
            Log::warning('[ServiceDesk] 客服 Agent 解析失败，回落内置提示词', [
                'tenant_id' => (int) $conversation->tenant_id,
                'conversation_id' => $conversation->conversation_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 租户当前的默认客服 Agent（配置台展示用，无会话上下文）
     *
     * 与 resolveFor 的差别：只看「租户默认」这一档，不涉及会话绑定。
     */
    public function resolveDefaultFor(int $tenantId): ?Agent
    {
        try {
            return $this->defaultSupportAgent($tenantId);
        } catch (\Throwable $e) {
            Log::warning('[ServiceDesk] 客服 Agent 查询失败', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * 解析并绑定到会话（幂等）
     *
     * 绑定后该会话的 AI 行为就固定在这个 Agent 上 —— 租户之后换了默认客服 Agent，
     * 老会话不会被中途换人设（对话中途换人设会让上下文突然错位）。
     *
     * @return Agent|null 绑定的 Agent；无可用 Agent 时 null
     */
    public function bindTo(Conversation $conversation): ?Agent
    {
        $agent = $this->resolveFor($conversation);

        if ($agent === null) {
            return null;
        }

        if ((int) $conversation->agent_id === (int) $agent->agent_id) {
            return $agent;
        }

        $conversation->agent_id = $agent->agent_id;
        $conversation->save();

        return $agent;
    }

    /**
     * 会话已绑定的 Agent（须仍启用 —— 停用的 Agent 不应继续对外应答）
     */
    private function boundAgent(Conversation $conversation): ?Agent
    {
        if ($conversation->agent_id === null) {
            return null;
        }

        return $this->tenantQuery((int) $conversation->tenant_id)
            ->where('agent_id', $conversation->agent_id)
            ->where('enabled', true)
            ->first();
    }

    private function defaultSupportAgent(int $tenantId): ?Agent
    {
        if ($tenantId <= 0) {
            return null;
        }

        return $this->tenantQuery($tenantId)
            ->where('role', self::SUPPORT_ROLE)
            ->where('enabled', true)
            ->orderBy('agent_id')
            ->first();
    }

    /**
     * 显式带 tenant_id 查询（绕过 TenantScope）
     *
     * webhook 场景的租户上下文由监听器临时设置，这里再显式带上 tenant_id，
     * 让隔离不依赖「调用方记得设上下文」这一前提。
     */
    private function tenantQuery(int $tenantId)
    {
        return Agent::withoutGlobalScope(TenantScope::class)->where('tenant_id', $tenantId);
    }
}
