<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\ServiceDesk\Http\Controllers\AdminConfigController;
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

Route::prefix('service-desk')->group(function () {
    // 配置：读当前生效设置（含来源：租户自设 / 部署默认），写只覆盖可改项
    Route::get('config', [AdminConfigController::class, 'show'])
        ->middleware('rbac.permission:service_desk.view');
    Route::put('config', [AdminConfigController::class, 'update'])
        ->middleware('rbac.permission:service_desk.config');

    // 度量看板
    Route::get('stats', [AdminStatsController::class, 'index'])
        ->middleware('rbac.permission:service_desk.view');
});
