<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

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
| ⚠ 本文件暂为空骨架：配置台接口在 M1 后续步骤落地
|   （AI 参数 / 接待人员 / 度量看板，见 docs/service-desk-design.md §八）。
|   刻意不放「返回空数据的假端点」——那比没有端点更难排查。
|
| 落地时形如：
|   Route::prefix('service-desk')->group(function () {
|       Route::get('config', [ServiceDeskConfigController::class, 'show'])
|           ->middleware('rbac.permission:service_desk.view');
|       Route::put('config', [ServiceDeskConfigController::class, 'update'])
|           ->middleware('rbac.permission:service_desk.config');
|       Route::get('sessions', [ServiceDeskSessionController::class, 'index'])
|           ->middleware('rbac.permission:service_desk.view');
|       Route::get('stats', [ServiceDeskStatsController::class, 'index'])
|           ->middleware('rbac.permission:service_desk.view');
|   });
*/
