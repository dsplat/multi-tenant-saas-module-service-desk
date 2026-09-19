<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk;

use Illuminate\Support\Facades\Event;
use MultiTenantSaas\Contracts\ToolAccessRequirementContract;
use MultiTenantSaas\Events\ChannelEventReceived;
use MultiTenantSaas\Events\MessageReceived;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\ServiceDesk\Events\SupportHandoffRequested;
use MultiTenantSaas\Modules\ServiceDesk\Events\SupportMessageReceived;
use MultiTenantSaas\Modules\ServiceDesk\Listeners\HandleChannelEvent;
use MultiTenantSaas\Modules\ServiceDesk\Listeners\HandleInboundSupportMessage;
use MultiTenantSaas\Modules\ServiceDesk\Listeners\NotifyHandoffTargets;
use MultiTenantSaas\Modules\ServiceDesk\Listeners\RespondToSupportMessage;
use MultiTenantSaas\Modules\ServiceDesk\Services\AccessLevelResolver;
use MultiTenantSaas\Modules\ServiceDesk\Services\ConversationContextFactory;
use MultiTenantSaas\Modules\ServiceDesk\Services\HandoffService;
use MultiTenantSaas\Modules\ServiceDesk\Services\IdentityBridgeService;
use MultiTenantSaas\Modules\ServiceDesk\Services\IdentityUpgradeService;
use MultiTenantSaas\Modules\ServiceDesk\Services\PolicyToolAccessRequirement;
use MultiTenantSaas\Modules\ServiceDesk\Services\RiskGuard;
use MultiTenantSaas\Modules\ServiceDesk\Services\ScenarioExtensions;
use MultiTenantSaas\Modules\ServiceDesk\Services\SceneCodeService;
use MultiTenantSaas\Modules\ServiceDesk\Services\SupportReplyService;
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
        $this->app->singleton(SceneCodeService::class);
        $this->app->singleton(IdentityBridgeService::class);
        $this->app->singleton(ScenarioExtensions::class);
        $this->app->singleton(ConversationContextFactory::class);
        $this->app->singleton(AccessLevelResolver::class);
        $this->app->singleton(RiskGuard::class);
        $this->app->singleton(HandoffService::class);
        $this->app->singleton(IdentityUpgradeService::class);
        $this->app->singleton(SupportReplyService::class);

        // 把场景的数据分级策略接进执行咽喉：只抬高门槛、不放行白名单外的工具。
        // 未配置策略时 requiredLevel() 返回 null，等价于「无额外要求」。
        $this->app->singleton(ToolAccessRequirementContract::class, PolicyToolAccessRequirement::class);
    }

    protected function bootModule(): void
    {
        $this->registerInboundWiring();
        $this->registerChannelEventWiring();
        $this->registerReplyWiring();
        $this->registerHandoffNotificationWiring();
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

    /**
     * 客服渠道事件接线
     *
     * 挂框架的 ChannelEventReceived（渠道无关事件总入口）：
     *   enter_session        → 身份桥接（scene 短码兑换）
     *   session_status_change → 接待态镜像
     *
     * 同 MessageReceived 的处理方式：事件由渠道层搬运，语义由本模块解释。
     */
    private function registerChannelEventWiring(): void
    {
        Event::listen(
            ChannelEventReceived::class,
            HandleChannelEvent::class,
        );
    }

    /**
     * 应答接线
     *
     * SupportMessageReceived → 入队（渠道回调要求收到即 ACK，模型合成不能在请求内做）
     */
    private function registerReplyWiring(): void
    {
        Event::listen(
            SupportMessageReceived::class,
            RespondToSupportMessage::class,
        );
    }

    /**
     * 转人工通知接线
     *
     * 框架的**默认**投递：notify 里数值型的条目当作已解析好的用户 ID 发站内通知。
     * 场景语义标识（「班主任」等）由场景自行监听 SupportHandoffRequested 投递。
     */
    private function registerHandoffNotificationWiring(): void
    {
        Event::listen(
            SupportHandoffRequested::class,
            NotifyHandoffTargets::class,
        );
    }
}
