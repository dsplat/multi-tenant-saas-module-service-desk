<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ServiceDesk 转人工来源账本（BL-180 P2 / G2）
 *
 * 为什么是**独立账本表**而不是给 conversations 加唯一键列：
 *   1. 语义是「一个来源键 → 一张工单」，键是 (tenant_id, conversation_id, escalation_key)
 *      三元组；同一会话可承载多个已显式命名的来源（不同申请类型），单列唯一键表达不了；
 *   2. 账本与领域写同事务提交，历史 metadata 关联可增量登记，不需要改写既有 metadata 结构；
 *   3. conversations 是共享热表，加唯一索引会把「转人工」的并发语义绑到整张会话表上；
 *      独立表让锁粒度落在来源行，且可独立清理/审计。
 *
 * 幂等设计（迁移可能在任何既有库上重放，也可能上次执行到一半）：
 *   - hasTable 守卫：表已存在则不再建表；
 *   - hasColumn 守卫：半途表逐列补齐（补列一律 nullable，避免既有行让 ALTER 失败）；
 *   - hasIndex 守卫：唯一键缺失时补建；补建失败（既有重复行）会显式报错而不是静默弱化。
 *
 * 唯一键 (tenant_id, conversation_id, escalation_key) 是幂等的最终保证：
 * 并发 worker 的第二个 INSERT 被数据库拒绝/忽略，只能读回先到者并返回同一张工单。
 * 列形态与 SQLite/MySQL 同形（Schema Builder，无驱动专有 DDL）。
 */
return new class extends Migration
{
    private const TABLE = 'service_desk_escalation_sources';

    private const UNIQUE_INDEX = 'service_desk_escalation_sources_source_unique';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            $this->createLedger();

            return;
        }

        $this->repairLedger();
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            Schema::drop(self::TABLE);
        }
    }

    private function createLedger(): void
    {
        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->unsignedBigInteger('escalation_source_id')
                ->primary()
                ->comment('IdGenerator 全局ID');
            $table->unsignedBigInteger('tenant_id')->comment('显式目标租户（不取环境上下文兜底）');
            $table->unsignedBigInteger('conversation_id')->comment('来源会话');
            $table->string('escalation_key', 128)->comment('来源键（调用方声明；省略时为 handoff）');
            $table->unsignedBigInteger('ticket_id')->nullable()
                ->comment('已绑定工单；同一事务内与建单一起写入');
            $table->string('request_fingerprint', 64)
                ->comment('请求指纹（sha256），同键异内容据此显式拒绝');
            $table->timestamps();

            // 幂等唯一键：一个来源键最多一行 → 最多一张工单
            $table->unique(
                ['tenant_id', 'conversation_id', 'escalation_key'],
                self::UNIQUE_INDEX,
            );
        });
    }

    /**
     * 半途执行/历史遗留表的自愈：只补缺失的列与唯一键，不重建、不清数据。
     *
     * 补列一律 nullable：既有行不会因为新列 NOT NULL 无默认值而让迁移中途失败；
     * 服务端写入时总会给全值。
     */
    private function repairLedger(): void
    {
        $missing = [
            'escalation_source_id' => static fn (Blueprint $table) => $table->unsignedBigInteger('escalation_source_id')->nullable(),
            'tenant_id' => static fn (Blueprint $table) => $table->unsignedBigInteger('tenant_id')->nullable(),
            'conversation_id' => static fn (Blueprint $table) => $table->unsignedBigInteger('conversation_id')->nullable(),
            'escalation_key' => static fn (Blueprint $table) => $table->string('escalation_key', 128)->nullable(),
            'ticket_id' => static fn (Blueprint $table) => $table->unsignedBigInteger('ticket_id')->nullable(),
            'request_fingerprint' => static fn (Blueprint $table) => $table->string('request_fingerprint', 64)->nullable(),
            'created_at' => static fn (Blueprint $table) => $table->timestamp('created_at')->nullable(),
            'updated_at' => static fn (Blueprint $table) => $table->timestamp('updated_at')->nullable(),
        ];

        foreach ($missing as $column => $definition) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                continue;
            }

            Schema::table(self::TABLE, function (Blueprint $table) use ($definition): void {
                $definition($table);
            });
        }

        if (! Schema::hasIndex(self::TABLE, self::UNIQUE_INDEX)
            && ! Schema::hasIndex(self::TABLE, ['tenant_id', 'conversation_id', 'escalation_key'], 'unique')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique(
                    ['tenant_id', 'conversation_id', 'escalation_key'],
                    self::UNIQUE_INDEX,
                );
            });
        }
    }
};
