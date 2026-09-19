<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\ActorContext;
use MultiTenantSaas\Modules\Ai\Services\Agent\AuditLogService;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\ServiceDesk\Dto\IdentityResult;

/**
 * 身份提升（authenticated → verified）
 *
 * 框架提供流程与状态机，**核验方式属场景**（学校用学号/统一身份，电商用手机号，
 * 医疗用就诊卡）—— 经 `IdentityUpgradeContract` 注入。
 *
 * 三条不可让步的约束：
 * 1. **核验材料必须来自场景核验** —— 用户自称「我是张三」不构成核验，
 *    对话内容永不参与判定
 * 2. 提升结果只落 `conversations.metadata.access_level`，
 *    该字段是 {@see AccessLevelResolver} 判定的**首个可信来源**
 * 3. 未配置提升实现时**拒绝**而非放行（fail-closed）—— 没有核验方式就没有提升
 */
class IdentityUpgradeService
{
    /** 提升结果写入的元数据键（与 AccessLevelResolver 读取的键必须一致） */
    private const META_ACCESS_LEVEL = 'access_level';

    private const META_UPGRADED_AT = 'identity_upgraded_at';

    public function __construct(
        private readonly ScenarioExtensions $extensions,
        private readonly AccessLevelResolver $accessLevels,
        private readonly ?AuditLogService $auditLog = null,
    ) {}

    /**
     * 当前会话能否提升
     */
    public function canPromote(Conversation $conversation): bool
    {
        if ($this->currentLevel($conversation) === ActorContext::LEVEL_VERIFIED) {
            return false;
        }

        $upgrade = $this->extensions->identityUpgrade();

        if ($upgrade === null) {
            return false;
        }

        try {
            return $upgrade->canPromote($this->accessLevels->contextFor($conversation));
        } catch (\Throwable $e) {
            Log::error('[ServiceDesk] 身份提升条件判定异常', [
                'conversation_id' => $conversation->conversation_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * 执行提升
     *
     * @param  array<string, mixed>  $payload  场景自定义的核验材料（学号、验证码…）
     */
    public function promote(Conversation $conversation, array $payload): IdentityResult
    {
        if ($this->currentLevel($conversation) === ActorContext::LEVEL_VERIFIED) {
            return IdentityResult::success(ActorContext::LEVEL_VERIFIED, '已完成核身，无需重复提交。');
        }

        $upgrade = $this->extensions->identityUpgrade();

        if ($upgrade === null) {
            // fail-closed：没有核验方式就没有提升，绝不因为「没配」而默认放行
            Log::warning('[ServiceDesk] 未配置身份提升实现，拒绝提升', [
                'conversation_id' => $conversation->conversation_id,
            ]);

            return IdentityResult::failure('当前未开通身份核验，请联系工作人员。');
        }

        try {
            $result = $upgrade->promote($this->accessLevels->contextFor($conversation), $payload);
        } catch (\Throwable $e) {
            Log::error('[ServiceDesk] 身份提升执行异常', [
                'conversation_id' => $conversation->conversation_id,
                'error' => $e->getMessage(),
            ]);

            return IdentityResult::failure('核验服务暂时不可用，请稍后再试。');
        }

        if (! $result->ok) {
            return $result;
        }

        // 场景返回的等级只接受 verified —— 提升接口的语义就是「升到已核身」，
        // 允许场景返回更低等级会让「提升」变成降级通道。
        if ($result->level !== ActorContext::LEVEL_VERIFIED) {
            Log::error('[ServiceDesk] 身份提升返回了非 verified 等级，已拒绝', [
                'conversation_id' => $conversation->conversation_id,
                'level' => $result->level,
            ]);

            return IdentityResult::failure('核验结果异常，请稍后再试。');
        }

        $this->markVerified($conversation, $payload);
        $this->audit($conversation, $payload);

        return $result;
    }

    /**
     * 当前等级（复用统一判定口径，避免两处各判一次）
     */
    private function currentLevel(Conversation $conversation): string
    {
        return $this->accessLevels->levelFor($conversation);
    }

    /**
     * 落提升结果
     *
     * 只写等级与时间戳，**不写核验材料** —— 学号/身份证号这类原始凭据属于场景，
     * 落进会话元数据等于把敏感信息散到一个到处被读的字段里。
     *
     * @param  array<string, mixed>  $payload
     */
    private function markVerified(Conversation $conversation, array $payload): void
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];

        $conversation->metadata = $metadata + [
            self::META_ACCESS_LEVEL => ActorContext::LEVEL_VERIFIED,
            self::META_UPGRADED_AT => now()->toIso8601String(),
            // 只记核验材料的**键名**，供事后核对用了哪种核验，不记值
            'identity_upgrade_method' => implode(',', array_map('strval', array_keys($payload))),
        ];
        $conversation->save();
    }

    /**
     * 提升必须可审计（谁、何时、哪一类会话）
     *
     * fail-open：审计失败不影响已完成的提升（用户已经核身了，不该因为日志问题回滚）。
     *
     * @param  array<string, mixed>  $payload
     */
    private function audit(Conversation $conversation, array $payload): void
    {
        if ($this->auditLog === null) {
            return;
        }

        try {
            $this->auditLog->log(
                action: 'service_desk_identity_upgraded',
                summary: null,
                agentId: null,
                conversationId: (int) $conversation->conversation_id,
                operatorId: null,
                targetType: 'conversation',
                targetId: (string) $conversation->conversation_id,
                detail: [
                    'tenant_id' => (int) $conversation->tenant_id,
                    'user_id' => $conversation->created_by,
                    'level' => ActorContext::LEVEL_VERIFIED,
                    'method_keys' => array_map('strval', array_keys($payload)),
                ],
                status: 'success',
            );
        } catch (\Throwable $e) {
            Log::warning('[ServiceDesk] 身份提升审计写入失败', [
                'conversation_id' => $conversation->conversation_id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
