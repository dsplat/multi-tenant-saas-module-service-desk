<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Services;

use MultiTenantSaas\Modules\Conversation\Models\Conversation;

/**
 * 满意度采集
 *
 * 设计取舍（为什么是文案匹配而不是菜单问卷）：
 * 企微客服支持 `msgmenu` 菜单消息，点选体验更好，但**点选之后回调给我们的是什么，
 * 官方文档没有写明**（发送侧参数齐全，接收侧未说明）。按「不猜 API 行为」的原则，
 * 这里用**已核实**的路径：会话结束时用结束语下发文本邀评（`send_msg_on_event`
 * 官方明确文本可用），用户回复命中配置词表即记录。
 *
 * 两处必须说清的边界：
 * 1. 「会话结束后用户能否回复」**未在真机验证** —— 所以邀评行为由
 *    `satisfaction.prompt_on_close` 控制且**默认关闭**，等真机冒烟确认后再开。
 *    关了它整套采集也不会触发（没有邀评就没有回复），这是刻意的：
 *    宁可没有数据，也不要产出一批「看起来在采集、实际永远为空」的假指标。
 * 2. 匹配的是**用户自由文本**，不是菜单 ID，因此词表要按场景配（学校可能用
 *    「满意/还行/不满意」）。这也是它按租户可配的原因。
 */
class SatisfactionService
{
    private const META_SATISFACTION = 'satisfaction';

    private const META_PENDING = 'satisfaction_pending';

    public function __construct(
        private readonly ServiceDeskSettings $settings,
    ) {}

    /**
     * 是否启用满意度采集
     */
    public function enabled(Conversation $conversation): bool
    {
        return (bool) $this->settings->getForConversation($conversation, 'satisfaction.enabled', false);
    }

    /**
     * 是否在会话结束时主动邀评
     *
     * 依赖「会话结束后用户仍能回复」这一未验证行为，故与 enabled 分开：
     * 采集能力可以在真机验证后单独打开。
     */
    public function promptOnClose(Conversation $conversation): bool
    {
        return (bool) $this->settings->getForConversation($conversation, 'satisfaction.prompt_on_close', false);
    }

    /**
     * 邀评文案（含可选词表，便于用户按提示回复）
     */
    public function promptText(Conversation $conversation): string
    {
        $options = $this->options($conversation);

        $base = '本次服务结束，如果您愿意，可以回复：' . implode(' / ', $options) . '。感谢您的反馈。';

        return (string) $this->settings->getForConversation($conversation, 'satisfaction.prompt_text', $base);
    }

    /**
     * 标记「已邀评」——只用于幂等，不记录结果
     */
    public function markAsked(Conversation $conversation): void
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];

        if (isset($metadata[self::META_PENDING])) {
            return;
        }

        $conversation->metadata = $metadata + [self::META_PENDING => now()->toIso8601String()];
        $conversation->save();
    }

    /**
     * 若这条用户消息是满意度反馈则记录
     *
     * @return bool 是否记录成功（调用方据此决定「要不要继续走 AI 应答」）
     */
    public function recordIfFeedback(Conversation $conversation, string $text): bool
    {
        if (! $this->enabled($conversation)) {
            return false;
        }

        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];

        // 幂等：一次会话只记第一次评价 —— 用户改主意可以再服务一次，但不该覆盖已采集的数据
        if (isset($metadata[self::META_SATISFACTION])) {
            return false;
        }

        // **只在邀评窗口内匹配**：包含式匹配（"我很满意，谢谢"）在没有邀评时会把
        // 真实提问吞掉 —— 用户说「不满意，能再解释一下吗」会被记成评价并致谢，
        // 问题永远得不到处理。没有邀评就没有评价，宁可漏采也不要吞提问。
        if (! isset($metadata[self::META_PENDING])) {
            return false;
        }

        $value = $this->match($conversation, $text);

        if ($value === null) {
            return false;
        }

        // 覆盖写：外层已做过「已有评价则不覆盖」的幂等判断，到这里就是该写的时候
        $metadata[self::META_SATISFACTION] = [
            'value' => $value,
            // 记录来源：是「回复了邀评」还是「自己主动说的」，事后统计口径不同
            'asked' => isset($metadata[self::META_PENDING]),
            'at' => now()->toIso8601String(),
        ];

        $conversation->metadata = $metadata;
        $conversation->save();

        return true;
    }

    /**
     * 记录后的致谢文案
     */
    public function thanksText(): string
    {
        return (string) config('service-desk.satisfaction.thanks_text', '感谢您的反馈，祝您一切顺利。');
    }

    /**
     * 采集到的满意度（无则 null）
     *
     * @return array{value: string, asked: bool, at: string}|null
     */
    public function recorded(Conversation $conversation): ?array
    {
        $value = (is_array($conversation->metadata) ? $conversation->metadata : [])[self::META_SATISFACTION] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * 判断用户文本命中了哪个选项
     *
     * 用**包含**而非全等：用户常写成「我很满意，谢谢」或「不满意！」。
     * 选项按配置顺序匹配（长词在前更稳，如「非常满意」应先于「满意」命中）——
     * 故 options() 会按长度倒序返回。
     */
    private function match(Conversation $conversation, string $text): ?string
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        foreach ($this->options($conversation) as $option) {
            if (mb_stripos($text, $option) !== false) {
                return $option;
            }
        }

        return null;
    }

    /**
     * 可选值列表（长的在前，避免「满意」抢先命中「非常满意」）
     *
     * @return array<int, string>
     */
    private function options(Conversation $conversation): array
    {
        $configured = $this->settings->getForConversation($conversation, 'satisfaction.options', null);

        $options = is_array($configured) && $configured !== []
            ? array_values(array_filter(array_map('strval', $configured)))
            : (array) config('service-desk.satisfaction.options', ['满意', '一般', '不满意']);

        usort($options, static fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return $options;
    }
}
