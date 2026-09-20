<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;
use MultiTenantSaas\Modules\Infrastructure\Services\TenantSettingService;
use Throwable;

/**
 * 客服模块的租户级设置
 *
 * 读取顺序：**租户设置（tenant_settings）→ 回落模块 config**。
 *
 * 为什么要这一层：模块 config 来自 env/文件，部署后不可改；而「连续几轮未解决就转人工」
 * 「是否建工单」这类阈值与开关，是每个院系（= 租户）要各自调的运营参数 ——
 * 不能让学校改配置文件重启才能调。租户级设置存 tenant_settings（框架既有机制，
 * group = service_desk），读不到时回落 config 保证老部署行为不变。
 *
 * 设置项白名单：只有**明确可按租户调**的键才允许写（`EDITABLE`），
 * 避免把「渠道凭证」这类敏感项顺手开放成可写。
 *
 * ⚠ 刻意**不走** TenantSettingService::get()：它先查 TenantConfigStore，
 * 而后者的键是 `group.key`、**不含租户 ID**（设计上假定「一个请求一个租户」，
 * 靠 Octane 的请求边界重置）。客服应答运行在**队列 worker** 里，
 * 进程会连续处理不同租户的任务，而 Octane 的 RequestReceived 永远不会触发 ——
 * 那种情况下 A 租户的设置会被 B 租户读到。故本类按租户自缓存（见 stored()）。
 */
class ServiceDeskSettings
{
    public const GROUP = 'service_desk';

    /**
     * 允许租户修改的设置项：键 => [类型, 对应模块 config 路径]
     *
     * 类型用于写入时收敛（bool/int），避免控制台传字符串把开关写成 "false" 这类真值。
     */
    public const EDITABLE = [
        'handoff.max_unresolved_turns' => ['int', 'service-desk.handoff.max_unresolved_turns'],
        'handoff.allow_visitor_request' => ['bool', 'service-desk.handoff.allow_visitor_request'],
        'handoff.summary_on_handoff' => ['bool', 'service-desk.handoff.summary_on_handoff'],
        'handoff.ticket_enabled' => ['bool', 'service-desk.handoff.ticket_enabled'],
        'handoff.suggest_replies' => ['bool', 'service-desk.handoff.suggest_replies'],
        'state_sync.enabled' => ['bool', 'service-desk.state_sync.enabled'],
        'reply.history_turns' => ['int', 'service-desk.reply.history_turns'],
        'satisfaction.enabled' => ['bool', 'service-desk.satisfaction.enabled'],
        'satisfaction.prompt_on_close' => ['bool', 'service-desk.satisfaction.prompt_on_close'],
        'satisfaction.options' => ['array', 'service-desk.satisfaction.options'],
    ];

    /**
     * 按租户缓存的原始设置值（进程内）
     *
     * 键是 tenantId，因此天然不会跨租户串值；写入后按租户失效。
     * 相比「每条消息查一次库」，这里每租户只读一次设置组。
     *
     * @var array<int, array<string, mixed>>
     */
    private array $resolved = [];

    public function __construct(
        private readonly TenantSettingService $settings,
    ) {}

    /**
     * 读取：租户设置优先，缺失回落模块 config
     */
    public function get(int $tenantId, string $key, mixed $default = null): mixed
    {
        if (! isset(self::EDITABLE[$key])) {
            return $default;
        }

        $stored = $this->stored($tenantId);

        if (array_key_exists($key, $stored)) {
            return $this->cast($key, $stored[$key]);
        }

        return config(self::EDITABLE[$key][1], $default);
    }

    /**
     * 便捷读（会话上下文里的调用点只需要会话）
     */
    public function getForConversation(Conversation $conversation, string $key, mixed $default = null): mixed
    {
        return $this->get((int) $conversation->tenant_id, $key, $default);
    }

    /**
     * 写入（仅白名单键）
     */
    public function set(int $tenantId, string $key, mixed $value): void
    {
        if (! isset(self::EDITABLE[$key])) {
            throw new \InvalidArgumentException("未知或不可修改的客服设置项：{$key}");
        }

        $this->settings->set($tenantId, self::GROUP, $key, $this->cast($key, $value));

        // 写入后按租户失效，避免控制台改完仍读到旧值
        unset($this->resolved[$tenantId]);
    }

    /**
     * 当前生效的完整设置视图（供配置台展示：值 + 来源）
     *
     * 带上 `source` 让配置台能说清「这条是院系自己设的，还是跟着部署默认值」——
     * 不区分来源的话，运维看到的值无法判断是谁定的。
     *
     * @return array<string, array{value: mixed, source: string, editable: bool}>
     */
    public function describe(int $tenantId): array
    {
        $stored = $this->stored($tenantId);

        $result = [];

        foreach (self::EDITABLE as $key => [$type, $configPath]) {
            $hasStored = array_key_exists($key, $stored);

            $result[$key] = [
                'value' => $hasStored ? $this->cast($key, $stored[$key]) : config($configPath),
                'source' => $hasStored ? 'tenant' : 'default',
                'editable' => true,
            ];
        }

        return $result;
    }

    /**
     * 读该租户的设置组（进程内按租户缓存；读不到当全未设置）
     *
     * @return array<string, mixed>
     */
    private function stored(int $tenantId): array
    {
        if (array_key_exists($tenantId, $this->resolved)) {
            return $this->resolved[$tenantId];
        }

        try {
            return $this->resolved[$tenantId] = $this->settings->getGroup($tenantId, self::GROUP);
        } catch (Throwable $e) {
            // 设置存储不可用不能拖垮客服链路：回落 config（fail-open 到部署默认值）
            Log::warning('[ServiceDesk] 读取租户设置失败，回落模块配置', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return $this->resolved[$tenantId] = [];
        }
    }

    /**
     * 类型收敛
     *
     * 控制台传来的值都是字符串：不收敛的话 `false` 会变成真值字符串，
     * 开关「关掉」反而等于打开 —— 这类错在安全相关开关上后果很重。
     */
    private function cast(string $key, mixed $value): mixed
    {
        return match (self::EDITABLE[$key][0]) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $value,
            default => $value,
        };
    }
}
