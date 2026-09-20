<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\EnsureModuleEnabled;
use MultiTenantSaas\Modules\ServiceDesk\Http\Controllers\AdminConfigController;
use MultiTenantSaas\Modules\ServiceDesk\Http\Controllers\AdminServicerController;
use MultiTenantSaas\Modules\ServiceDesk\Http\Controllers\AdminStatsController;

/*
|--------------------------------------------------------------------------
| 智能客服 — 管理配置接口（Operator 面）
|--------------------------------------------------------------------------
| 基类 ModuleServiceProvider 统一附加：api + auth:sanctum + throttle:api
| + tenant.identify + VerifyOperatorTenant，前缀 api/v1。
| 这里只声明资源与 RBAC 权限，不写认证中间件。
|
| 权限名用下划线（service_desk.*）—— 与既有先例一致
| （模块名 developer-portal、权限名 developer_portal.api_key）。
|
| 待做：接待人员（需渠道侧的 servicer 列表能力，企微 kf 接口待核实后接入）。
*/

// module.enabled：管理配置台接口同样受租户级模块开关约束（开关关掉就不该还能改配置）
Route::prefix('service-desk')
    ->middleware(EnsureModuleEnabled::class . ':service-desk')
    ->group(function () {
        // 配置：读当前生效设置（含来源：租户自设 / 部署默认），写只覆盖可改项
        Route::get('config', [AdminConfigController::class, 'show'])
            ->middleware('rbac.permission:service_desk.view');
        Route::put('config', [AdminConfigController::class, 'update'])
            ->middleware('rbac.permission:service_desk.config');

        // 度量看板
        Route::get('stats', [AdminStatsController::class, 'index'])
            ->middleware('rbac.permission:service_desk.view');

        // 接待人员（渠道持有，框架只做读写代理，不落库）
        Route::get('servicers', [AdminServicerController::class, 'index'])
            ->middleware('rbac.permission:service_desk.view');
        Route::post('servicers', [AdminServicerController::class, 'store'])
            ->middleware('rbac.permission:service_desk.servicer');
    });
