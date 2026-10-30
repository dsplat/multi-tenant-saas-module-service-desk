<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Exceptions\DomainException;

/**
 * 转人工来源账本（BL-180 P2 / G2）
 *
 * 一行 = 一个来源键 (tenant_id, conversation_id, escalation_key) → 一张工单。
 * 数据库唯一键是幂等的最终保证：并发/重试的第二个 INSERT 会被忽略（insertOrIgnore），
 * 调用方随后读回先到者的行；**先 INSERT 认领、再建单、最后绑定 ticket_id** 全部发生在
 * 调用方的事务里 —— 建单后绑定前中断会整体回滚，不会留下孤儿工单或半截来源。
 *
 * 指纹（request_fingerprint）只覆盖「请求身份」而不是上下文内容：escalation key 与
 * reason（申请类型）。摘要、建议话术、风险文案随消息变化，不能进指纹，否则同一来源的
 * 正常重试会被误判成冲突；换申请类型/换键才是真正的不同请求。
 *
 * 表缺失（迁移未执行）时 available() 为 false，由调用方退回既有 metadata 幂等路径并
 * 留告警 —— 与 TicketMatterDeliveryIntentStore 的裁剪部署策略一致，不在这里建表。
 */
final class EscalationSourceLedger
{
    /** 账本表名（迁移 2026_10_31_000004 创建） */
    public const TABLE = 'service_desk_escalation_sources';

    /** 调用方未显式声明来源键时的默认键：沿用「一个会话一张转人工工单」的历史语义 */
    public const DEFAULT_ESCALATION_KEY = 'handoff';

    public const MAX_ESCALATION_KEY_LENGTH = 128;

    /** 指纹版本：字段集合变化时递增，旧指纹自然与新请求判为不同 */
    public const FINGERPRINT_VERSION = 1;

    public function available(): bool
    {
        return Schema::hasTable(self::TABLE);
    }

    /**
     * 规范化来源键：省略（null）用默认键；空串/超长显式拒绝（不静默截断或退化成另一个键）。
     */
    public function normalizeKey(?string $escalationKey): string
    {
        if ($escalationKey === null) {
            return self::DEFAULT_ESCALATION_KEY;
        }

        $key = trim($escalationKey);

        if ($key === '') {
            throw new DomainException('escalation_key_invalid: escalation key 不能是空字符串（省略该参数即使用默认键 handoff）');
        }

        if (mb_strlen($key) > self::MAX_ESCALATION_KEY_LENGTH) {
            throw new DomainException(sprintf(
                'escalation_key_invalid: escalation key 超过 %d 字符上限（实际 %d）',
                self::MAX_ESCALATION_KEY_LENGTH,
                mb_strlen($key),
            ));
        }

        return $key;
    }

    /**
     * 请求指纹：同一来源键下，reason（申请类型）不同即视为不同请求。
     */
    public function fingerprint(string $escalationKey, string $reason): string
    {
        $canonical = [
            'v' => self::FINGERPRINT_VERSION,
            'escalation_key' => $escalationKey,
            'reason' => $reason,
        ];

        return hash('sha256', (string) json_encode(
            $canonical,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));
    }

    /**
     * 认领来源键：返回 true = 本 worker 抢到（新插入）；false = 键已存在（并发/重试）。
     *
     * 用 insertOrIgnore 而不是「先查后插」：查与插之间的窗口正是两 worker 各建一张单的
     * 来源；唯一键 + 单条 INSERT 让数据库做串行化。MySQL 下冲突 INSERT 会等待对方的
     * 未提交行，对方提交后返回 0，因此读到的必然是已提交的完整来源行。
     */
    public function claim(int $tenantId, int $conversationId, string $escalationKey, string $fingerprint): bool
    {
        $now = now();

        $inserted = DB::table(self::TABLE)->insertOrIgnore([
            'escalation_source_id' => app(IdGeneratorContract::class)->generate(),
            'tenant_id' => $tenantId,
            'conversation_id' => $conversationId,
            'escalation_key' => $escalationKey,
            'ticket_id' => null,
            'request_fingerprint' => $fingerprint,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $inserted === 1;
    }

    /**
     * 锁定并读取来源行（调用方事务内使用）。
     */
    public function lock(int $tenantId, int $conversationId, string $escalationKey): ?object
    {
        return DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $conversationId)
            ->where('escalation_key', $escalationKey)
            ->lockForUpdate()
            ->first();
    }

    /**
     * 绑定工单：必须命中唯一键行，未命中说明认领被并发删除/事务错位，显式失败。
     */
    public function bind(int $tenantId, int $conversationId, string $escalationKey, int $ticketId): void
    {
        $updated = DB::table(self::TABLE)
            ->where('tenant_id', $tenantId)
            ->where('conversation_id', $conversationId)
            ->where('escalation_key', $escalationKey)
            ->update([
                'ticket_id' => $ticketId,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new DomainException(sprintf(
                '转人工来源账本绑定失败：tenant=%d conversation=%d key=%s ticket=%d',
                $tenantId,
                $conversationId,
                $escalationKey,
                $ticketId,
            ));
        }
    }
}
