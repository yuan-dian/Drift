<?php
declare(strict_types=1);

namespace Drift\Platform\X11;

use Drift\Platform\Clipboard;

use function drift_x11_clipboard_has_text;
use function drift_x11_clipboard_read_text;
use function drift_x11_clipboard_write_text;

/**
 * X11 剪贴板端口（§5.1 壳服务）：只做 UTF-8 纯文本，与 Win32 同契约。
 *
 * ★ **异步 selection 协议全在桥外**：xclip 子进程持有 CLIPBOARD owner 并响应
 *   别的程序的 SelectionRequest（headless 无 clipboard manager 时它就是那个 manager）；
 *   我们的 X connection 上只有 requestor 一半，故端口可以保持同步三方法，
 *   不波及 TextEditingBridge 的 Ctrl+C/V/X 链路。细节见 cpp-src/x11/clipboard.h。
 *
 * ★ **UTF-8 直通**：X11/xclip 原生吃 UTF-8，没有 Win32 的 UTF-16 转换负担；
 *   桥两端都显式指定 `UTF8_STRING` target —— 不指定会被当成 Latin-1，中文变问号。
 *
 * ★ **失败语义**：空 / 无 owner / 读取超时 ⇒ `readText()` 空串、`hasText()` false；
 *   `writeText()` 失败静默。与 Win32Clipboard 完全同口径，调用方无需平台分支。
 *
 * @internal 平台实现；应用只认 `Drift\Platform\Clipboard`
 */
final class X11Clipboard implements Clipboard
{
    public function readText(): string
    {
        return drift_x11_clipboard_read_text();
    }

    public function writeText(string $text): void
    {
        drift_x11_clipboard_write_text($text);
    }

    public function hasText(): bool
    {
        return drift_x11_clipboard_has_text();
    }
}
