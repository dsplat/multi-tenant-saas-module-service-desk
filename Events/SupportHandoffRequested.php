<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Events;

use Illuminate\Foundation\Events\Dispatchable;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\ServiceDesk\Dto\RiskVerdict;
use MultiTenantSaas\Modules\ServiceDesk\Listeners\NotifyHandoffTargets;

/**
 * 已请求转人工（含通知对象）
 *
 * 框架保证的制度性动作是「转人工成功 + 镜像接待态 + 广播本事件」；
 * **怎么通知、通知给谁** 属于场景（学校的班主任 / 心理老师从学生档案里算出来，
 * 电商的客服主管从排班表里算出来）—— 场景监听本事件自行投递。
 *
 * 框架的默认投递见 {@see NotifyHandoffTargets}：
 * 对 notify 里**数值型**条目（即已解析成用户 ID 的）发站内通知；
 * 非数值条目（角色名、工号等场景自定义标识）框架不认识，交给场景监听器。
 */
class SupportHandoffRequested
{
    use Dispatchable;

    /**
     * @param  string  $reason  转人工原因码（visitor_request / risk / unresolved_turns / ai_unavailable …）
     * @param  array<int, string|int>  $notify  通知对象标识
     */
    public function __construct(
        public readonly Conversation $conversation,
        public readonly string $reason,
        public readonly array $notify = [],
        public readonly ?RiskVerdict $verdict = null,
    ) {}
}
