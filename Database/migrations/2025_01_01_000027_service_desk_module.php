<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Contracts\IdGeneratorContract;

/**
 * 智能客服：RBAC 权限注册（**零业务新表**）
 *
 * 会话与消息复用 Conversation 模块的既有表（conversations / messages / participants），
 * 接待态镜像落 conversations.metadata —— 本模块不建任何业务表，因此本迁移只注册权限。
 * 见 docs/service-desk-design.md §5.1。
 *
 * 幂等设计（模块迁移可能在任何环境的既有库上重放）：
 *   - hasTable 守卫：下游库可能没有 RBAC 表
 *   - 权限 name 唯一键守卫：已存在的权限复用，不重复插入
 * 权限随模块迁移注册，模块卸载时由 down() 一并回收。
 */
return new class extends Migration
{
    /**
     * 权限点与 Routes/ 下 rbac.permission:service_desk.* 一一对应。
     *
     * 命名用下划线而非连字符 —— 与既有先例一致（模块名 `developer-portal`、
     * 权限名 `developer_portal.api_key`）。租户级模块不加 platform. 前缀。
     */
    private const PERMISSIONS = [
        ['name' => 'service_desk.view', 'display_name' => '查看客服会话与看板'],
        ['name' => 'service_desk.config', 'display_name' => '配置客服 AI 与接待人员'],
        ['name' => 'service_desk.servicer', 'display_name' => '管理客服接待人员'],
    ];

    public function up(): void
    {
        $this->registerPermissions();
    }

    public function down(): void
    {
        if (Schema::hasTable('permissions')) {
            DB::table('permissions')
                ->whereIn('name', array_column(self::PERMISSIONS, 'name'))
                ->delete();
        }
    }

    private function registerPermissions(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $idGenerator = app(IdGeneratorContract::class);
        $now = now();

        foreach (self::PERMISSIONS as $permission) {
            $exists = DB::table('permissions')->where('name', $permission['name'])->exists();

            if ($exists) {
                continue;
            }

            DB::table('permissions')->insert([
                'permission_id' => $idGenerator->generate(),
                'name' => $permission['name'],
                'display_name' => $permission['display_name'],
                'group' => 'service_desk',
                'description' => $permission['display_name'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
