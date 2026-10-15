<?php
declare(strict_types=1);

namespace Drift\Platform;

use Drift\Geometry\Size;

/**
 * 窗口：生命周期 + 运行期控制（§5.1 纪律 3："生"与"活"分开）。
 *
 * ★ **句柄是"不透明 int"**（§5.1 纪律 1）：端口方法签名里只出现原语与 Drift 值对象，
 *   OS 句柄（HWND / NSWindow…）**跨接口后不得解引用** —— 解引用只允许发生在
 *   `Drift\Platform\<Os>\` 内部（`Win32Platform::surface()` 里
 *   `reinterpret_cast<HWND>($windowHandle)`）。
 *
 * ★ **指针形状与指针捕获也归这里**（不是新端口）：它们是**窗口级**状态
 *   （`SetCursor` / `SetCapture` 作用于 HWND），与"标题 / 位置 / 尺寸"同族。
 *   ⏳ 这两个方法**今天不声明**：它们的唯一消费者是 §9.1 的命中链 + `CursorShape`
 *   （`src/Input/`，归 M3）⇒ 按 A15"没有消费者的 API 不预挂"。形状已定：
 *   `setCursor(CursorShape $shape)` / `setCapture(bool $capture): bool`。
 *
 * @api platform
 */
interface WindowHost
{
    /**
     * 建主窗口，返回**不透明句柄**（供 `Platform::surface()` 与同族方法使用）。
     *
     * @param int $width  客户区宽度（**逻辑**像素）
     * @param int $height 客户区高度（**逻辑**像素）
     */
    public function createWindow(string $title, int $width, int $height): int;

    /** 按平台的"显示"语义让窗口可见（Win32：`ShowWindow(SW_SHOW)`） */
    public function show(int $windowHandle): void;

    /**
     * 请求关闭（Win32：`WM_CLOSE`）。
     *
     * ★ 这是**应用唯一的正常退出路径**：关闭 ⇒ `WM_QUIT` ⇒ `Platform::pump()`
     *   返回 `false` ⇒ 组合根的 `while` 跳出。`Platform` 上**不另设 `quit()`**
     *   —— 那会变成第二条等价的退出路径（同一件事两个入口 = 必有一个是死的）。
     */
    public function close(int $windowHandle): void;

    public function setTitle(int $windowHandle, string $title): void;

    /** 客户区尺寸（**逻辑**像素；物理像素的换算归 `Screen::scale()`） */
    public function clientSize(int $windowHandle): Size;

    /** 供 `WindowStateStore`（M5，§5.4）读回"上次是不是最大化" */
    public function isMaximized(int $windowHandle): bool;
}
