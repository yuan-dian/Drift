<?php
declare(strict_types=1);

namespace Drift\Platform\Win32;

use Drift\Errors;
use Drift\Geometry\Point;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Input\CursorShape;
use Drift\Platform\Backdrop;
use Drift\Platform\WindowHost;

use function drift_win_begin_drag;
use function drift_win_client_size;
use function drift_win_close;
use function drift_win_create;
use function drift_win_dpi;
use function drift_win_is_maximized;
use function drift_win_maximize_toggle;
use function drift_win_minimize;
use function drift_win_place_window;
use function drift_win_set_backdrop;
use function drift_win_set_frameless;
use function drift_win_set_min_size;
use function drift_win_set_opacity;
use function drift_win_set_cursor;
use function drift_win_set_title;
use function drift_win_show;
use function drift_win_uia_push;
use function drift_win_window_position;

/**
 * Win32 窗口端口（§5.1）。
 *
 * ★ 它是 `Drift\Platform\Win32` 里**唯一持有 HWND 的地方**，而且持的是 `int`
 *   —— §5.1 纪律 1：句柄跨接口后是**不透明 int**，解引用只允许发生在
 *   `Drift\Platform\<Os>\` 内部，且这里也只把它当数字传给桥。
 *
 * ★ `createWindow()` 失败**必须报 Fatal**（G2）：返回 0 却继续跑，后面每一次
 *   `drift_win_*` 都会静默变成空操作，用户看到的是一块什么都不显示的窗口 ——
 *   正是 §1.2 要消灭的形态。
 *
 * @internal 平台实现；应用只认 `Drift\Platform\WindowHost`
 */
final class Win32WindowHost implements WindowHost
{
    private ?int $handle = null;

    public function createWindow(string $title, int $width, int $height): int
    {
        $handle = drift_win_create($title, $width, $height);

        if ($handle <= 0) {
            Errors::report('Win32: 建窗失败（CreateWindowExW 返回 0）—— 窗口类注册或系统资源有问题');
        }

        $this->handle = $handle;

        return $handle;
    }

    /** 最近一次建出来的句柄；未建 ⇒ `null`（`Screen` / `TimerHost` 靠它找窗口） */
    public function handle(): ?int
    {
        return $this->handle;
    }

    public function show(int $windowHandle): void
    {
        drift_win_show($windowHandle);
    }

    public function close(int $windowHandle): void
    {
        drift_win_close($windowHandle);
    }

    public function setTitle(int $windowHandle, string $title): void
    {
        drift_win_set_title($windowHandle, $title);
    }

    public function setMinSize(int $width, int $height): void
    {
        $handle = $this->handle;
        if ($handle === null) {
            return;   // 还没建窗：调用方顺序错（组合根在 createWindow 之后调）
        }

        $dpr = Scale::fromDpi((float) drift_win_dpi($handle))->dpr;
        if ($dpr <= 0.0) {
            $dpr = 1.0;
        }

        drift_win_set_min_size($handle, (int) round($width * $dpr), (int) round($height * $dpr));
    }

    /**
     * 整窗不透明度（0..1；1 = 不透明）。P1 §质感。
     *
     * ★ 这是**平台能力**，不是正确性：不支持的平台可 no-op（外观增强）。
     *   Win32 走 `WS_EX_LAYERED` + `SetLayeredWindowAttributes`（**全局** alpha，
     *   首次 <1 才懒挂 LAYERED）。
     * ★ 逐像素 alpha 是另一条路（`setBackground()` 带 alpha ⇒ `UpdateLayeredWindow`），
     *   两者互斥；那条路上 alpha 由 `BLENDFUNCTION` 带上，语义一致。
     */
    public function setOpacity(int $windowHandle, float $opacity): void
    {
        drift_win_set_opacity($windowHandle, $opacity);
    }

    /**
     * 窗口背后的材质（`null` = 关）。P1 §质感 / Stage 3。
     *
     * ★ 走**未公开**的 `user32!SetWindowCompositionAttribute`（accent 策略）——
     *   Win10 上 `DWMWA_SYSTEMBACKDROP_TYPE` 不存在（要 Win11 22621+），
     *   这是唯一一条路。壳侧 `GetProcAddress` 动态取，取不到就静默 no-op。
     * ★★ **只在"应用没画到"的地方可见**：DWM 把模糊画在窗口**背后**，应用像素在
     *   上面 ⇒ 不透明窗口设了等于没设（没有观察通道，不是失败）。
     * ★ `null` → 桥的 `0`（关）；枚举的 `value` 就是桥的 ABI 编号。
     */
    public function setBackdrop(int $windowHandle, ?Backdrop $backdrop): void
    {
        drift_win_set_backdrop($windowHandle, $backdrop === null ? 0 : $backdrop->value);
    }

    /**
     * 无边框模式（P1-Stage 1b）。`$dragHeight` 是**逻辑**像素，这里折成物理。
     *
     * ★ 换算口径与 `setMinSize()` 完全一致（`Scale::fromDpi()`，§6.1 的量化点
     *   只有一个）—— 直接把手感尺寸交给壳会让 125% 缩放下拖动带窄 20%。
     * ★ 壳侧走 `WM_NCCALCSIZE`（样式不变、客户区=整窗），所以**调用后客户区会
     *   变大**（多出原标题栏高度）。组合根必须在 `setFrameless()` **之后**再读
     *   `clientSize()`，否则第一帧按旧尺寸排版（然后被 `WM_SIZE` 纠正，闪一下）。
     */
    public function setFrameless(int $windowHandle, int $dragHeight): void
    {
        $dpr = Scale::fromDpi((float) drift_win_dpi($windowHandle))->dpr;
        if ($dpr <= 0.0) {
            $dpr = 1.0;
        }

        drift_win_set_frameless($windowHandle, (int) round($dragHeight * $dpr));
    }

    public function beginDrag(int $windowHandle): void
    {
        drift_win_begin_drag($windowHandle);
    }

    public function minimize(int $windowHandle): void
    {
        drift_win_minimize($windowHandle);
    }

    public function maximizeToggle(int $windowHandle): void
    {
        drift_win_maximize_toggle($windowHandle);
    }

    /**
     * 客户区尺寸（**逻辑**像素）。
     *
     * ★ 桥给的是**物理**像素，这里除以 DPI 缩放 —— 逻辑↔物理的换算点只有一个
     *   （§6.1 的 `Scale`），所以换算必须走 `Scale::fromDpi()` 而不是自己写
     *   `/ 1.25` 这种字面量：分数缩放下两者会算出不同的值。
     */
    public function clientSize(int $windowHandle): Size
    {
        $raw = drift_win_client_size($windowHandle);

        $dpr = Scale::fromDpi((float) drift_win_dpi($windowHandle))->dpr;
        if ($dpr <= 0.0) {
            $dpr = 1.0;   // 防御：桥理论上不会给 0，但除零会让整个布局变 NaN
        }

        return Size::of(
            (float) ((int) ($raw['w'] ?? 0)) / $dpr,
            (float) ((int) ($raw['h'] ?? 0)) / $dpr,
        );
    }

    public function isMaximized(int $windowHandle): bool
    {
        return drift_win_is_maximized($windowHandle);
    }

    /**
     * 客户区左上角的屏幕坐标（**逻辑**像素，§5.4 持久化的读向侧）。
     *
     * ★ 桥给的是**物理**像素（`ClientToScreen` 的结果恒为物理 —— 屏幕坐标没有
     *   DPI 感知一说），这里按当前窗口 DPI 折算成逻辑：与 `clientSize()`
     *   同一条换算路径（`Scale::fromDpi()`，§6.1 的量化点只有一个）。
     * ★ **最小化 ⇒ `null`**：桥用空数组表达（`IsIconic` 或 `GetClientRect`
     *   失败）。这不是错误路径，是"别记"的信号（见 `WindowStateStore` 类注释）。
     */
    public function position(int $windowHandle): ?Point
    {
        $raw = drift_win_window_position($windowHandle);
        $x = $raw['x'] ?? null;
        $y = $raw['y'] ?? null;
        if ($x === null || $y === null) {
            return null;   // 最小化 / 查询失败
        }

        $dpr = Scale::fromDpi((float) drift_win_dpi($windowHandle))->dpr;
        if ($dpr <= 0.0) {
            $dpr = 1.0;   // 防御：桥理论上不会给 0，但除零会让坐标变 INF
        }

        return Point::of((float) $x / $dpr, (float) $y / $dpr);
    }

    /**
     * 一次原子还原窗口几何（§5.4）。
     *
     * ★ **换算全在壳里做**：PHP 给逻辑像素，壳负责"客户区原点 + 客户区尺寸 →
     *   外框矩形 + 显示器夹取 + 最大化状态"这一整套（§6.1 量化只在平台侧）。
     *   PHP 侧连 `AdjustWindowRectExForDpi` 的名字都不该看见。
     * ★ **不做成功 / 失败两态**：与 `setTitle()` / `show()` 同族 —— 端口方法
     *   的失败都表现为"什么都没发生"，而这里调用方（组合根）接下来会读
     *   `clientSize()` 拿实际几何，错不错当下就知道。
     */
    public function place(int $windowHandle, Rect $rect, bool $maximized): void
    {
        drift_win_place_window(
            $windowHandle,
            $rect->left,
            $rect->top,
            $rect->width,
            $rect->height,
            $maximized,
        );
    }

    /**
     * 设置指针形状（§9.1）。
     *
     * ★ 传的是 `$shape->value` —— **枚举的 `value` 就是桥的 ABI 编号**
     *   （`drift_win_set_cursor(int $hwnd, int $shape)`）。这条约定写在
     *   `CursorShape` 的 docblock 里，**两处必须同步改**。
     * ★ 桥**缓存**这个形状并在 `WM_SETCURSOR` 时重贴 —— 不缓存的话 Windows
     *   每次鼠标移动都会把光标重置回窗口类光标，形状只显示到下一次移动
     *   （且**不报错**）。原因见 `cpp-src/drift_win32.cc` 的同名函数。
     */
    public function setCursor(int $windowHandle, CursorShape $shape): void
    {
        drift_win_set_cursor($windowHandle, $shape->value);
    }

    /**
     * 推送语义树扁平节点（§9 / M5）：原样交给桥（只传标量，§5.1 纪律 1）。
     *
     * ★ 桥侧 provider 从这份数组应答 UIA 客户端；空数组 ⇒ 清空（无障碍关闭）。
     */
    public function pushSemantics(array $flatNodes): void
    {
        drift_win_uia_push($flatNodes);
    }
}
