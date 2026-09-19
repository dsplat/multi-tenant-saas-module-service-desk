<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\ServiceDesk\Http\Controllers\EntryLinkController;

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
Route::prefix('service-desk')->group(function () {
    // 签发入口带参客服链接：宿主（小程序）拿去做跳转，用户点进来即完成身份桥接
    Route::post('entry-link', [EntryLinkController::class, 'store']);
});
