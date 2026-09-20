<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| 智能客服模块配置
|--------------------------------------------------------------------------
|
| 模块本体（通用机制）在此配置；**行业逻辑不进这里** —— 场景相关的
| 风险词表、数据分级规则、身份核验方式，由 `extensions` 段注入实现类。
| 见 docs/service-desk-design.md §四。
|
*/

return [

    // 路由前缀（基类加载 Routes/*.php 时统一挂在 api/v1 下）
    'route_prefix' => '',

    /*
    | 应答行为
    */
    'reply' => [
        // RAG 无命中时的兜底
        'no_answer' => '抱歉，我暂时没有找到相关信息。您可以换个说法再问，或者我帮您转接人工。',
        // 转人工时给用户的提示
        'handoff_notice' => '已为您转接人工客服，请稍候。',
        // 转人工**失败**时的提示（不能假装已转接，否则用户在那头干等）
        'handoff_failed_notice' => '抱歉，人工客服暂时未能接入，您可以继续问我，或稍后再试。',
        // 命中 block 级风险时的安全文案
        'risk_block_notice' => '抱歉，这个我帮不了您，请联系工作人员。',
        // 人工接待中收到新消息时的静默提示（AI 不插话，但要让用户知道有人在看）
        'human_serving_notice' => '客服人员正在为您处理，请稍候。',
        // 带入 AI 合成的历史轮数上限（0 = 不带历史，退化为单轮）
        'history_turns' => (int) env('SERVICE_DESK_HISTORY_TURNS', 6),
    ],

    /*
    | 转人工触发条件
    |
    | 全部为「任一命中即转」；具体阈值按租户场景调整。
    */
    'handoff' => [
        // 连续 N 轮未命中知识库 → 转人工
        'max_unresolved_turns' => (int) env('SERVICE_DESK_MAX_UNRESOLVED_TURNS', 3),
        // 是否允许用户主动要求转人工（关键词 + 意图）
        'allow_visitor_request' => true,
        // 转人工时刷新会话摘要（坐席接手时最需要「这段会话讲了什么」）
        'summary_on_handoff' => (bool) env('SERVICE_DESK_SUMMARY_ON_HANDOFF', true),
        // 转人工时建工单（人工队列不该是漏斗底部：要有可跟进、可统计的落点）
        'ticket_enabled' => (bool) env('SERVICE_DESK_TICKET_ENABLED', true),
    ],

    /*
    | 接待态镜像
    |
    | 接待态由渠道（微信客服）托管，本地只做镜像写入 conversations.metadata；
    | 一致性问题以渠道为准（回读 kf/service_state/get）。
    */
    'state_sync' => [
        'enabled' => (bool) env('SERVICE_DESK_STATE_SYNC', true),
        // 镜像字段名（写在 conversations.metadata 下）
        'state_key' => 'service_state',
        'state_updated_key' => 'service_state_updated_at',
        'servicer_key' => 'servicer_userid',
    ],

    /*
    | 身份桥接
    |
    | 入口带参：渠道生成带 scene 的客服链接，用户进入会话事件把 scene_param 原样带回，
    | 后端据此把渠道身份关联到系统用户。
    |
    | 官方长度约束（docs/service-desk-design.md §3.2）：
    |   scene ≤ 32 字节，字符集 [0-9a-zA-Z_-]；非空时才可用 scene_param
    |   scene_param 需 urlencode，encode 前 ≤ 128 字节
    */
    'identity_bridge' => [
        // scene 短码 TTL（秒）—— 一次性消费
        'scene_ttl' => (int) env('SERVICE_DESK_SCENE_TTL', 900),
        // scene 值前缀，便于在企微侧识别来源
        'scene_prefix' => env('SERVICE_DESK_SCENE_PREFIX', 'sd'),
        // 待绑定保留时长（秒）
        //
        // 比 scene_ttl 长：用户点了链接之后可能过一会儿才开口，而会话要等首条消息
        // 才由 ConversationRouter 创建 —— 待绑定必须活到那一刻。
        'pending_ttl' => (int) env('SERVICE_DESK_IDENTITY_PENDING_TTL', 86400),
    ],

    /*
    | 场景扩展点（框架定义接口，场景提供实现）
    |
    | 注册方式对齐框架既有的 extra_*_classes 范式（config/ai.php 的
    | extra_template_classes / extra_chain_classes）：配置里写类名。
    |
    | - risk_interceptors   实现 RiskInterceptorContract，静态 inspect()；
    |                       多个按顺序判定，任一返回 escalate/block 即生效
    | - data_access_policy  实现 DataAccessPolicyContract（单一实现）
    | - identity_upgrade    实现 IdentityUpgradeContract（单一实现）
    */
    'extensions' => [
        'risk_interceptors' => [],
        'data_access_policy' => null,
        'identity_upgrade' => null,
    ],

];
