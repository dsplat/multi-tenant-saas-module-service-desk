<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk;

use Illuminate\Support\Facades\Event;
use MultiTenantSaas\Events\MessageReceived;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\ServiceDesk\Listeners\HandleInboundSupportMessage;
use MultiTenantSaas\Modules\ServiceDesk\Services\SupportSessionService;

/**
 * 智能客服模块
 *
 * 定位：租户级对外客服场景。**建立在 UserAi 基座之上** —— 信任边界
 * （ActorContext / 工具暴露层闸门 / 出站内容守护）由 UserAi 提供，
 * 本模块负责客服场景本身：渠道接入、会话、接待态、转人工、工单。
 *
 * 依赖：ai（ToolRegistry / 审计）、knowledge（RAG）、conversation（会话存储）、
 *      ticket（工单）、notification（坐席提醒）；渠道驱动复用 src/Services/Channel。
 *
 * 路由 / 迁移 / 视图由基类 ModuleServiceProvider 按约定自动加载：
 *   Routes/api.php     → api/v1       （api + auth:sanctum + tenant.identify + VerifyOperatorTenant）
 *   Routes/tenant.php  → api/v1       （api + auth:sanctum + tenant.identify）
 *   Routes/public.php  → api/v1       （api）
 * 不要在此重写 loadModuleRoutes()：会导致同一路由文件注册两次并丢掉中间件。
 *
 * 见 docs/service-desk-design.md。
 */
class ServiceDeskServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'service-desk';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(SupportSessionService::class);
    }

    protected function bootModule(): void
    {
        $this->registerInboundWiring();
    }

    /**
     * 客服入站接线
     *
     * 挂框架的 MessageReceived（渠道无关入站总事件），由监听器自行判断
     * 是否属于客服场景 —— 这样监听器与渠道解耦，未来接公众号 / 网页客服
     * 只需在监听器的渠道白名单里加一项。
     */
    private function registerInboundWiring(): void
    {
        Event::listen(
            MessageReceived::class,
            HandleInboundSupportMessage::class,
        );
    }
}
