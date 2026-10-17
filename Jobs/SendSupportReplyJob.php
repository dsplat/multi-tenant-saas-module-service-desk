<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Concerns\HasTaskAuthEnvelope;
use MultiTenantSaas\Context\CarriesTaskAuthEnvelope;
use MultiTenantSaas\Context\ExecutionIdentity;
use MultiTenantSaas\Context\ExecutionIdentityManager;
use MultiTenantSaas\Context\TaskAuthEnvelope;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Context\TrustedExecutionInput;
use MultiTenantSaas\Modules\Ai\Models\Agent;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\Conversation\Models\Message;
use MultiTenantSaas\Modules\ServiceDesk\Services\SupportAgentResolver;
use MultiTenantSaas\Modules\ServiceDesk\Services\SupportReplyService;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * 客服应答作业
 *
 * 为什么走队列：渠道回调要求**收到即 ACK**（超时会被重推，导致重复处理），
 * 而一次应答包含知识检索 + 模型合成，耗时不可控。故监听器只入队，不在请求内算。
 *
 * 只传 ID 不传模型：队列里没有认证/租户上下文，反序列化出来的模型经 TenantScope
 * 查询会因 fail-closed 拿不到（WHERE 1=0）。这里显式按 ID 直取并补上租户上下文。
 *
 * 执行身份（F-05 / ADR v4 §1.2 channel→queue→UserAi）：
 * 本 Job 是 channel 父作用域装载点（type=service, surface=channel），
 * **携带 Agent toolScope 上限**。UserAiRuntime 在父内 push user_ai 子作用域，
 * toolScope 取「父上限 ∩ 会话应得」交集 —— **异步载体不改变授权范围**，
 * 不得因跑在队列获得 system 权限。父作用域销毁归本 Job 边界（finally）。
 */
class SendSupportReplyJob implements CarriesTaskAuthEnvelope, ShouldQueue
{
    use Dispatchable, HasTaskAuthEnvelope, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private readonly int $conversationId,
        private readonly int $messageId,
    ) {
        // default enforce：渠道应答 Job 随任务持久化 service 信封
        // （channel 父作用域在 handle 内建立；信封只负责通过边界，不自动授 system）
        $this->withTaskAuthEnvelope(
            TaskAuthEnvelope::forService('send-support-reply', null, 'service-desk-inbound'),
        );
    }

    public function handle(SupportReplyService $reply): void
    {
        $conversation = Conversation::withoutGlobalScope(TenantScope::class)
            ->find($this->conversationId);

        if ($conversation === null) {
            // 会话已被删除（例如会话结束归档）：没什么可应答的
            return;
        }

        $message = Message::withoutGlobalScope(TenantScope::class)
            ->where('message_id', $this->messageId)
            ->first();

        if ($message === null) {
            return;
        }

        $previousTenantId = TenantContext::getId();
        TenantContext::setTenantId((string) $conversation->tenant_id);

        try {
            $this->runWithinChannelScope($conversation, $message, $reply);
        } catch (\Throwable $e) {
            // 应答失败不该把作业打进死循环重试（用户会收到重复回复）；
            // 记 error 供排障，链路本身有降级（转人工）
            Log::error('[ServiceDesk] 应答作业执行失败', [
                'conversation_id' => $this->conversationId,
                'message_id' => $this->messageId,
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
     * 在 channel 父作用域内执行应答（父携带 Agent toolScope 上限）
     *
     * 已有父作用域（嵌套同步队列）时不再 enterRoot，避免误清外层。
     */
    private function runWithinChannelScope(Conversation $conversation, Message $message, SupportReplyService $reply): void
    {
        $manager = $this->identityManager();
        $input = $this->channelInput($conversation);

        if ($manager->current() !== null) {
            // 嵌套任务：在既有父内压栈，pop 恢复外层
            $manager->runChild($input, fn () => $reply->handle($conversation, $message));

            return;
        }

        $manager->runRoot($input, fn () => $reply->handle($conversation, $message));
    }

    /**
     * channel 父作用域输入：type=service, surface=channel，toolScope 上限来自客服 Agent
     *
     * 上限是 Agent 声明的工具面（effectiveTools）；子作用域（UserAiRuntime）取
     * 「父上限 ∩ 会话应得」交集后才会真正执行工具。未配置 Agent 时上限为 null
     * （无额外限制），子作用域仍受 tool_surface 白名单约束。
     */
    private function channelInput(Conversation $conversation): TrustedExecutionInput
    {
        $ceiling = $this->agentToolCeiling($conversation);

        return new TrustedExecutionInput(
            identity: new ExecutionIdentity(
                type: ExecutionIdentity::TYPE_SERVICE,
                surface: ExecutionIdentity::SURFACE_CHANNEL,
                subjectId: 'service-desk',
                tenantId: (string) $conversation->tenant_id,
                level: ExecutionIdentity::LEVEL_AUTHENTICATED,
                authSource: 'queue',
                toolScope: $ceiling,
            ),
            toolScope: $ceiling,
        );
    }

    /**
     * 客服 Agent 的工具上限（只作为父作用域天花板，不在本层执行任何工具）
     *
     * @return array<int, string>|null
     */
    private function agentToolCeiling(Conversation $conversation): ?array
    {
        try {
            $agent = app(SupportAgentResolver::class)->resolveFor($conversation);

            if (! $agent instanceof Agent) {
                return null;
            }

            $tools = $agent->effectiveTools();

            return $tools === [] ? [] : array_values($tools);
        } catch (\Throwable) {
            // Agent 解析失败不阻断应答（与 SupportAgentResolver 同口径）；
            // 上限缺省为 null = 无额外限制，子作用域仍受白名单约束
            return null;
        }
    }

    /**
     * 唯一存储入口（与 ActorContext / 中间件同一绑定）
     */
    private function identityManager(): ExecutionIdentityManager
    {
        if (! app()->bound(ExecutionIdentityManager::class)) {
            app()->singleton(ExecutionIdentityManager::class);
        }

        return app(ExecutionIdentityManager::class);
    }
}
