<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| 智能客服 — 终端用户侧接口（租户 user）
|--------------------------------------------------------------------------
| 基类附加 api + auth:sanctum + throttle:api + tenant.identify，前缀 api/v1。
| （无 VerifyOperatorTenant —— 终端用户不是 Operator。）
|
| ⚠ 本文件暂为空骨架。终端用户的主要入口是**渠道**（微信客服），
|   经已有的 /v1/{type}/webhook/{tenant_slug} 回调进入，不走这里。
|   本面留给 M1 后续：「生成入口带参链接」「身份提升」。
|
| 落地时形如：
|   Route::prefix('service-desk')->group(function () {
|       // 生成带 scene 的客服链接（身份桥接的入口）
|       Route::post('entry-link', [ServiceDeskEntryController::class, 'link']);
|       // 当前会话状态（供终端用户侧展示）
|       Route::get('session', [ServiceDeskSessionController::class, 'show']);
|       // 身份提升（实名）
|       Route::post('identity/promote', [ServiceDeskIdentityController::class, 'promote']);
|   });
*/
