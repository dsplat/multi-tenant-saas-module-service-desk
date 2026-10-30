<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\EnsureModuleEnabled;
use MultiTenantSaas\Modules\ServiceDesk\Http\Controllers\EntryLinkController;
use MultiTenantSaas\Modules\ServiceDesk\Http\Controllers\IdentityController;

/*
|--------------------------------------------------------------------------
| 智能客服 — 终端用户侧接口（租户 user）
|--------------------------------------------------------------------------
| 基类附加 api + auth:sanctum + throttle:api + tenant.identify，前缀 api/v1。
| （无 VerifyOperatorTenant —— 终端用户不是 Operator。）
|
| 终端用户的主要入口是**渠道**（微信客服），经已有的
| /v1/{type}/webhook/{tenant_slug} 回调进入，不走这里。
| 本面只承担宿主侧要调的事：签发入口带参链接、（M2）身份提升。
|
| 不加 rbac.permission：RBAC 只作用于 Operator，User 不拥有角色（见设计 §6.1）。
*/
// module.enabled：租户在 tenant_modules 里关掉 service-desk 后，本组路由直接 404。
// 没有这道门控时「租户开关」只影响工具层与后台展示，路由永远可达 ——
// 开关形同虚设（main 的 module.enabled 原语正是为此而设，本模块是第一批消费者）。
Route::prefix('service-desk')
    ->middleware(EnsureModuleEnabled::class . ':service-desk')
    ->group(function () {
        // 签发入口带参客服链接：宿主（小程序）拿去做跳转，用户点进来即完成身份桥接
        Route::post('entry-link', [EntryLinkController::class, 'store']);

        // 身份核验（authenticated → verified）：提升后可达个人信息类工具。
        // 只能核验**当前登录用户自己的**会话 —— 归属以 conversations.created_by 为准，
        // 不接受请求体指定，否则猜中会话 ID 就能提升别人的会话。
        Route::post('identity/promote', [IdentityController::class, 'promote']);
    });
