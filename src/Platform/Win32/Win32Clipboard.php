<?php
declare(strict_types=1);

namespace Drift\Platform\Win32;

use Drift\Platform\Clipboard;

use function drift_win_clipboard_has_text;
use function drift_win_clipboard_read_text;
use function drift_win_clipboard_write_text;

/**
 * Win32 剪贴板端口（§5.1 壳服务）：只做纯文本（`CF_UNICODETEXT`）。
 *
 * ★ **第一个消费者是 `Application` 的 `Ctrl+C/V/X`**（§9.1 路由链第 ③ 环：
 *   焦点里有个在编辑的 `TextFieldState` 时归它，加速键不让位给它抢）。
 *   富文本 / 图片**不进本端口** —— 那要格式协商，是另一层的事。
 *
 * ★ **`OpenClipboard` 的重试住在 C 侧**（`openClipboardWithRetry`）：别的进程
 *   正握着剪贴板时它返回 `ERROR_ACCESS_DENIED`，那是 Windows 的既定行为、不是
 *   错误路径。PHP 侧重试会把"平台时序"漏进上层（§5.1 纪律 1）—— 壳自己的
 *   事壳自己收拾。
 *
 * ★ **UTF-8 ⇄ UTF-16 的换算也在 C 侧**（`toWide` / `toUtf8`）：PHP 字符串到
 *   这里已经是 UTF-8（§8），跨边界只传字符串，不引缓冲区。
 *
 * @internal 平台实现；应用只认 `Drift\Platform\Clipboard`
 */
final class Win32Clipboard implements Clipboard
{
    public function readText(): string
    {
        return drift_win_clipboard_read_text();
    }

    public function writeText(string $text): void
    {
        drift_win_clipboard_write_text($text);
    }

    public function hasText(): bool
    {
        return drift_win_clipboard_has_text();
    }
}
