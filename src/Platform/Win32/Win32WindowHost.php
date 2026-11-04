<?php
declare(strict_types=1);

namespace Drift\Platform\Win32;

use Drift\Errors;
use Drift\Geometry\Point;
use Drift\Geometry\Rect;
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
use function drift_win_is_alive;
use function drift_win_is_maximized;
use function drift_win_live_window;
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
 * Win32 窗口端口。`WindowHost` 接口的平台实现，对应用只暴露 `WindowHost`。
 * 句柄跨接口后是不透明 int，这里只当数字传给桥、不在 PHP 侧解引用。
 * ★ `createWindow()` 失败**必须报 Fatal**：返回 0 却继续跑，后面每一次
 *   `drift_win_*` 都会静默变成空操作，用户看到一块什么都不显示的窗口。
 * @internal 平台实现；应用只认 `Drift\Platform\WindowHost`
 */
final class Win32WindowHost implements WindowHost
{
    private ?int $handle = null;

    /**
     * 是否已请求无边框（`setFrameless()` 记一次）。
     * 供 `applyMaterial()` 校验"逐像素 alpha 要无边框"—— 壳看不见 PHP 侧调用历史，
     * 这条校验需要跨两次调用的知识，故记在实现对象上。
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

    /** 最近一次建出来的句柄；未建 ⇒ `null` */
    public function handle(): ?int
    {
        return $this->handle;
    }

    /**
     * 任意一个**还活着**的句柄；一个都没有 ⇒ 最近建过的那个（可能已死）。
     * 不能用 `handle()` 取它：那是"最后建的窗"，主窗关、次窗在时就是死句柄，
     * 挂 `SetTimer` 会失败、`waitEvents()` 退化成无限阻塞，余下窗口动画停摆（不报错）。
     */
    public function liveHandle(): ?int
    {
        $live = drift_win_live_window();

        return $live > 0 ? $live : $this->handle;
    }

    public function show(int $windowHandle): void
    {
        drift_win_show($windowHandle);
    }

    /**
     * **请求**关闭：桥发 `WM_CLOSE`（与用户点标题栏 X 同一条路）。
     * 一次 `PostMessageW` 就返回，不是同步销毁；声明了关闭守卫的窗口先变成一条
     * `CloseRequestedEvent`，由应用决定后再 `destroy()`。
     */
    public function close(int $windowHandle): void
    {
        drift_win_close($windowHandle);
    }

    /**
     * **执行**关闭：桥直接 `DestroyWindow`，不看守卫（框架在应用同意之后调）。
     * 与 `close()` 分工在"请求"与"执行"两个语义：本方法只在应用同意关闭后调用。
     */
    public function destroy(int $windowHandle): void
    {
        drift_win_destroy($windowHandle);
    }

    public function isAlive(int $windowHandle): bool
    {
        return drift_win_is_alive($windowHandle);
    }

    /**
     * 声明该窗口的关闭要不要先问应用（壳侧按 HWND 记一个集合）。
     * 壳不认识 `Application`，只知道"这个 HWND 声明了要问"，于是把 `WM_CLOSE`
     * 转成一条 `CloseRequestedEvent`，由 PHP 的帧边界决定。
     */
    public function setCloseGuard(int $windowHandle, bool $armed): void
    {
        drift_win_set_close_guard($windowHandle, $armed);
    }

    public function setTitle(int $windowHandle, string $title): void
    {
        drift_win_set_title($windowHandle, $title);
    }

    public function setMinSize(int $windowHandle, int $width, int $height): void
    {
        // ★ 用**传进来的**句柄：靠 `$this->handle`（"最后建的窗"）会把第二个窗口的
        //   最小尺寸设到第一个上。
        $dpr = Dpr::of($windowHandle);

        drift_win_set_min_size(
            $windowHandle,
            (int) round($width * $dpr),
            (int) round($height * $dpr),
        );
    }

    /**
     * 一次应用窗口外观材质。见 `WindowHost::applyMaterial()`。
     * ★ 在这里硬校验"逐像素 alpha 要无边框"：漏设的表现是壳静默回落 GDI，
     *   "透明没生效"且没有任何报错。配置矛盾属编程错误，当场报、不留静默态。
     * 传给桥的只有模式与数值：`pixelAlpha` 由材质底色 alpha 推出，`Color` 本身不过桥。
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
     * 桥侧结果进程内已缓存（答案不变），这里不二次缓存。
     */
    public function materialCapabilities(int $windowHandle): WindowMaterialCapabilities
    {
        return WindowMaterialCapabilities::fromBits(drift_win_material_caps());
    }

    /**
     * 无边框模式。`$dragHeight` 是**逻辑**像素，这里按 `Dpr::of()` 折成物理
     * （全仓唯一换算入口，直接交壳会让 125% 缩放下拖动带窄 20%）。
     * 壳侧走 `WM_NCCALCSIZE`（客户区=整窗），调用后客户区会变大（多出标题栏高度），
     * 组合根必须在 `setFrameless()` **之后**再读 `clientSize()`，否则首帧按旧尺寸排版、
     * 被 `WM_SIZE` 纠正时闪一下。
     */
    public function setFrameless(int $windowHandle, int $dragHeight): void
    {
        $this->frameless = true;

        $dpr = Dpr::of($windowHandle);

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
     * 桥给的是**物理**像素，这里按 `Dpr::of()` 折成逻辑 —— 逻辑↔物理换算点全仓唯一，
     * 不能自己写 `/ 1.25` 这种字面量：分数缩放下两者会算出不同的值。
     */
    public function clientSize(int $windowHandle): Size
    {
        $raw = drift_win_client_size($windowHandle);

        $dpr = Dpr::of($windowHandle);

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
     * 客户区左上角的屏幕坐标（**逻辑**像素）。
     * 桥给的是**物理**像素（`ClientToScreen` 结果恒为物理，屏幕坐标无 DPI 感知），
     * 这里按当前窗口 DPI 折成逻辑，与 `clientSize()` 同一条 `Dpr::of()` 路径。
     * **最小化 ⇒ `null`**：桥用空数组表达（窗口图标化或取矩形失败），是"别记"的信号，
     * 不是错误路径。
     */
    public function position(int $windowHandle): ?Point
    {
        $raw = drift_win_window_position($windowHandle);
        $x = $raw['x'] ?? null;
        $y = $raw['y'] ?? null;
        if ($x === null || $y === null) {
            return null;   // 最小化 / 查询失败
        }

        $dpr = Dpr::of($windowHandle);

        return Point::of((float) $x / $dpr, (float) $y / $dpr);
    }

    /**
     * 一次原子还原窗口几何。
     * 换算全在壳里做：PHP 给逻辑像素，壳负责"客户区原点 + 客户区尺寸 → 外框矩形 +
     * 显示器夹取 + 最大化状态"整套，PHP 侧不该看见 `AdjustWindowRectExForDpi`。
     * 不做成功/失败两态：与 `setTitle()` / `show()` 同族，调用方接下来读 `clientSize()`
     * 拿实际几何，错不错当下就知道。
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
     * 设置指针形状。
     * 传的是 `$shape->value`：枚举的 `value` 即桥的 ABI 编号，约定写在
     * `CursorShape` 的 docblock 里，两处必须同步改。
     * 桥缓存这个形状并在 `WM_SETCURSOR` 时重贴：不缓存的话 Windows 每次鼠标移动
     * 都会把光标重置回窗口类光标，形状只显示到下一次移动（且不报错）。
     */
    public function setCursor(int $windowHandle, CursorShape $shape): void
    {
        drift_win_set_cursor($windowHandle, $shape->value);
    }

    /**
     * 推送语义树扁平节点：原样交给桥（只传标量）。
     * 桥侧 provider 从这份数组应答 UIA 客户端；空数组 ⇒ 清空（无障碍关闭）。
     */
    public function pushSemantics(int $windowHandle, array $flatNodes): void
    {
        drift_win_uia_push($windowHandle, $flatNodes);
    }
}
