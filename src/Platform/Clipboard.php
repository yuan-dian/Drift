<?php
declare(strict_types=1);

namespace Drift\Platform;

/**
 * 剪贴板（壳服务，§5.1 纪律 2）。
 *
 * ★ **独立端口而不是主接口的方法**：跨平台语义差异大（格式 / 所有权 / 延迟渲染），
 *   塞进 `Platform` 会让每个实现都长出一批抛异常的桩。独立端口让"不支持"在
 *   **类型层面**表达清楚（`Platform::clipboard()` 返回 `null`）。
 *
 * ★ 形状取自两个已验证的实现（GUI 仓 `clipboard_{set,get,has}_text`、
 *   markdown-editor `win_editor_action('copy'/'cut'/'paste')`）—— 只收纯文本
 *   （`CF_UNICODETEXT`）。富文本 / 图片**不进本端口**：那要格式协商，是另一层的事。
 *
 * ⏳ **M1 不实现**：`Win32Platform::clipboard()` 返回 `null`。第一个消费者是
 *   `TextInputClient` 的 `Ctrl+C/V/X`（§9.1 让位规则 ②，M3）。
 *
 * @api platform
 */
interface Clipboard
{
    /** 读纯文本；剪贴板为空或内容不是文本 ⇒ 空串（不是错误） */
    public function readText(): string;

    public function writeText(string $text): void;

    /** `readText()` 是否有内容 —— 让"粘贴"能先判可用性再改状态 */
    public function hasText(): bool;
}
