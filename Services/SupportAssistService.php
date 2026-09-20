<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\ServiceDesk\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Contracts\CapabilityRegistryContract;
use MultiTenantSaas\Modules\Conversation\Models\Conversation;

/**
 * 坐席辅助：建议话术
 *
 * 坐席用的是企微原生工作台，我们**推不进那个界面** —— 所以话术建议的投递点只能是
 * 框架能给坐席看到的地方：工单描述与转人工通知。这也决定了建议要短、
 * 可直接复制粘贴，而不是一段分析。
 *
 * 走 AI **能力层**的 `text_generation`，不自己拼一套模型调用：
 * 能力层统一了模型口径、用量统计与失败语义，另起一套迟早与它对不上。
 *
 * 失败语义：fail-open。话术建议是锦上添花，拿不到就是坐席自己写 ——
 * 绝不能因为一次模型调用失败影响「已经转人工」这个事实。
 */
class SupportAssistService
{
    /** 建议条数上限（多了坐席不会看） */
    private const MAX_SUGGESTIONS = 3;

    /**
     * 能力注册表**可选**：AI 模块未启用时应为 null，而不是让整个服务构造失败。
     *
     * 这一点很关键：`SupportReplyService` 会构造本服务，若此处硬依赖注册表，
     * 「AI 模块没装」就会变成「连转人工都做不了」—— 而转人工恰恰是 AI 不可用时的
     * 兜底路径（AI 可选性铁律）。所以依赖可为空，拿不到就直接跳过建议话术。
     */
    public function __construct(
        private readonly ServiceDeskSettings $settings,
        private readonly ?CapabilityRegistryContract $capabilities = null,
    ) {}

    /**
     * 生成建议话术（同时写入会话 metadata）
     *
     * @return array<int, string> 空数组表示未生成（开关关闭 / 无素材 / 调用失败）
     */
    public function suggestReplies(Conversation $conversation): array
    {
        if ($this->capabilities === null) {
            return [];
        }

        if (! (bool) $this->settings->getForConversation($conversation, 'handoff.suggest_replies', true)) {
            return [];
        }

        $summary = trim((string) ($conversation->summary ?? ''));

        if ($summary === '') {
            // 没有摘要就没有素材：凭空生成会给出与用户实际诉求无关的话术
            return [];
        }

        try {
            $result = $this->capabilities->execute('text_generation', [
                'prompt' => $this->buildPrompt($summary),
                'max_tokens' => 400,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[ServiceDesk] 生成建议话术失败（不影响转人工）', [
                'conversation_id' => $conversation->conversation_id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $suggestions = $this->parse((string) ($result->output ?? ''));

        if ($suggestions === []) {
            return [];
        }

        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];

        $conversation->metadata = $metadata + ['suggested_replies' => $suggestions];
        $conversation->save();

        return $suggestions;
    }

    /**
     * 提示词：要求短、可直接发送、不要分析与客套
     */
    private function buildPrompt(string $summary): string
    {
        return '下面是客服会话的摘要。请为即将接手的人工客服写 ' . self::MAX_SUGGESTIONS
            . " 条可直接发送给用户的回复建议。\n"
            . "要求：每条一句话，中文口语，礼貌但不客套；不要解释、不要分析、不要编号以外的格式。\n"
            . "每行一条，以 1. 2. 3. 开头。\n\n"
            . "【会话摘要】\n{$summary}";
    }

    /**
     * 清洗一行
     *
     * 模型的实际输出常带包装：`1. xxx`、`- xxx`、`**1. xxx**`（整行加粗）、
     * `` `xxx` ``。顺序很重要 —— 先剥外层标记，编号前缀才露出来；
     * 否则 `**3. xxx**` 会被当成「一个以 * 开头的条目」，编号留在正文里发给坐席。
     */
    private function cleanLine(string $line): string
    {
        $line = trim($line);

        // 先剥包裹的强调/代码标记（可能只在首尾一侧，trim 两侧都处理）
        $line = trim($line, "*_` \t");

        // 再去掉 "1." / "1)" / "1、" / "-" / "*" / "•" 这类前缀
        $line = (string) preg_replace('/^\s*(?:\d+[\.\)、]|[-*•])\s*/u', '', $line);

        return trim(trim($line), "*_` \t");
    }

    /**
     * 解析模型输出：容忍编号、项目符号、编号前缀带括号等常见写法
     *
     * @return array<int, string>
     */
    private function parse(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        $suggestions = [];

        foreach ($lines as $line) {
            $line = $this->cleanLine($line);

            if ($line === '' || in_array($line, $suggestions, true)) {
                continue;
            }

            $suggestions[] = $line;

            if (count($suggestions) >= self::MAX_SUGGESTIONS) {
                break;
            }
        }

        return $suggestions;
    }
}
