<?php
declare(strict_types=1);

namespace Drift\Platform\Win32;

use Drift\Errors;
use Drift\Geometry\Point;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Input\CursorShape;
use Drift\Platform\WindowHost;
use Drift\Platform\WindowMaterial;
use Drift\Platform\WindowMaterialCapabilities;

use function drift_win_apply_material;
use function drift_win_begin_drag;
use function drift_win_client_size;
use function drift_win_close;
use function drift_win_create;
use function drift_win_destroy;
use function drift_win_dpi;
use function drift_win_is_maximized;
use function drift_win_material_caps;
use function drift_win_maximize_toggle;
use function drift_win_minimize;
use function drift_win_place_window;
use function drift_win_set_close_guard;
use function drift_win_set_cursor;
use function drift_win_set_frameless;
use function drift_win_set_min_size;
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

    /**
     * 是否已请求无边框（`setFrameless()` 记一次）。
     *
     * ★ 供 `applyMaterial()` 校验"逐像素 alpha 要无边框"这条耦合 —— 壳看不见
     *   PHP 侧的调用历史，而这条校验恰恰需要跨两次调用的知识，所以记在实现
     *   对象上（单窗假设与 `$handle` 同源；多窗口化时改 map，见 GAP §8 待做）。
     */
    private bool $frameless = false;

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

    /**
     * **请求**关闭：桥发 `WM_CLOSE`（与用户点标题栏 X 同一条路）。
     *
     * ★ 一次 `PostMessageW` 就返回 —— **不是同步销毁**。没声明守卫的窗口在同一轮
     *   消息处理里被销毁（体验上与改动前无差别）；声明了守卫的窗口会先变成一条
     *   `CloseRequestedEvent`。
     */
    public function close(int $windowHandle): void
    {
        drift_win_close($windowHandle);
    }

    /**
     * **执行**关闭：桥直接 `DestroyWindow`，不看守卫（框架在应用同意之后调）。
     *
     * ★ 与 `close()` 的两分不是洁癖：它们对应"请求"与"执行"两个语义，
     *   合成一个方法就等于"能不能被拦"取决于谁在调（2026-10-30 之前的状态）。
     */
    public function destroy(int $windowHandle): void
    {
        drift_win_destroy($windowHandle);
    }

    /**
     * 声明该窗口的关闭要不要先问应用（壳侧按 HWND 记一个集合）。
     *
     * ★ 壳**不认识** `Application`：它只知道"这个 HWND 声明了要问"，
     *   于是把 `WM_CLOSE` 转成一条事件。决定权在 PHP 的帧边界
     *   （见 `CloseRequestedEvent` 的类注释：为什么不能在 WndProc 里同步问）。
     */
    public function setCloseGuard(int $windowHandle, bool $armed): void
    {
        drift_win_set_close_guard($windowHandle, $armed);
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
     * 一次应用窗口外观材质（P1 收敛裁决）。见 `WindowHost::applyMaterial()`。
     *
     * ★★ **在这里硬校验"逐像素 alpha 要无边框"**：这条耦合收敛前只活在组合根
     *   的注释里（"带 alpha 时必须同时 DRIFT_WINDOW_FRAMELESS=1"），漏设的表现
     *   是壳静默回落 GDI —— "透明没生效"且无任何报错。配置矛盾属于编程错误，
     *   与"环境变量写错是 Fatal"同口径：当场报，不留静默态。
     * ★ 传给桥的只有**模式与数值**：`pixelAlpha` 由材质的底色 alpha 推出，
     *   `Color` 本身不过桥（§5.1 只传原语；颜色是像素的事，归表面）。
     */
    public function applyMaterial(int $windowHandle, WindowMaterial $material): void
    {
        $pixelAlpha = $material->wantsPixelAlpha();
        if ($pixelAlpha && !$this->frameless) {
            Errors::report(
                'WindowMaterial: 带 alpha 的底色（逐像素透明）必须先 setFrameless() '
                . '—— 无边框是 UpdateLayeredWindow 位图对齐窗口矩形的前提，'
                . '漏设只会"透明没生效"（壳静默回落 GDI）',
            );
        }

        drift_win_apply_material(
            $windowHandle,
            $pixelAlpha,
            $material->opacityValue(),
            $material->backdropKind() === null ? 0 : $material->backdropKind()->value,
        );
    }

    /**
     * 平台能力查询（位掩码从壳一次探来）。见 `WindowMaterialCapabilities`。
     *
     * ★ 桥侧结果进程内缓存（答案不变），这里不做二次缓存 —— 多一层缓存就多一个
     *   "测试替身要不要跟着假"的问题。
     */
    public function materialCapabilities(int $windowHandle): WindowMaterialCapabilities
    {
        return WindowMaterialCapabilities::fromBits(drift_win_material_caps());
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
        $this->frameless = true;

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
