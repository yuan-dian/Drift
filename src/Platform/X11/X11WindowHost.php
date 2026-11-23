<?php
declare(strict_types=1);

namespace Drift\Platform\X11;

use Drift\Geometry\Point;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Input\CursorShape;
use Drift\Platform\WindowHost;
use Drift\Platform\WindowMaterial;
use Drift\Platform\WindowMaterialCapabilities;

use function drift_x11_begin_drag;
use function drift_x11_client_size;
use function drift_x11_create;
use function drift_x11_destroy;
use function drift_x11_is_alive;
use function drift_x11_maximize_toggle;
use function drift_x11_minimize;
use function drift_x11_place_window;
use function drift_x11_set_cursor;
use function drift_x11_set_frameless;
use function drift_x11_set_min_size;
use function drift_x11_set_title;
use function drift_x11_show;
use function drift_x11_window_position;

/**
 * X11 窗口端口。建窗/存活/尺寸/标题/几何/最小化/最大化/光标/无边框拖拽走桥；
 * 材质、AT-SPI 语义仍是显式 no-op，不是"平台支持但没生效"。
 * 逻辑↔物理换算统一走 {@see X11Dpr}，桥只收物理像素。
 * @internal 平台实现；应用只认 Drift\Platform\WindowHost
 */
final class X11WindowHost implements WindowHost
{
    private ?int $handle = null;

    /** @var array<int, true> 销毁后的句柄不再过桥：死句柄上的 X 请求会触发 BadDrawable */
    private array $destroyed = [];

    public function handle(): ?int
    {
        return $this->handle;
    }

    public function createWindow(string $title, int $width, int $height): int
    {
        $handle = drift_x11_create($title, $width, $height);
        if ($handle > 0) {
            $this->handle = $handle;
        }

        return $handle;
    }

    public function show(int $windowHandle): void
    {
        drift_x11_show($windowHandle);
    }

    public function close(int $windowHandle): void
    {
        // 应用主动关闭：直接销毁。用户点关（WM_DELETE_WINDOW）走另外的
        // close-requested 上报路径，两条不混。
        $this->destroy($windowHandle);
    }

    public function destroy(int $windowHandle): void
    {
        $this->destroyed[$windowHandle] = true;
        drift_x11_destroy($windowHandle);
        if ($this->handle === $windowHandle) {
            $this->handle = null;
        }
    }

    public function isAlive(int $windowHandle): bool
    {
        if (isset($this->destroyed[$windowHandle])) {
            return false;
        }

        return drift_x11_is_alive($windowHandle);
    }

    public function setCloseGuard(int $windowHandle, bool $armed): void
    {
        // X11 的 WM_DELETE_WINDOW 天生就是"请求"（WM 不会强杀应用），桥恒上报
        // CloseRequestedEvent，由应用决定何时 destroy —— 等价于 Win32 guard 恒 armed。
        // 故这里的开关在 X11 无操作价值：不做成"未 armed 就销毁"，那会与本平台
        // 的关闭链相反，且改坏真机门对 close-requested 的断言。
    }

    public function setTitle(int $windowHandle, string $title): void
    {
        drift_x11_set_title($windowHandle, $title);
    }

    public function setMinSize(int $windowHandle, int $width, int $height): void
    {
        // 入参是逻辑像素，X 大小提示要物理像素；统一经 X11Dpr 折算。
        $dpr = X11Dpr::of($windowHandle);

        drift_x11_set_min_size(
            $windowHandle,
            (int) round($width * $dpr),
            (int) round($height * $dpr),
        );
    }

    public function applyMaterial(int $windowHandle, WindowMaterial $material): void
    {
    }

    public function materialCapabilities(int $windowHandle): WindowMaterialCapabilities
    {
        return WindowMaterialCapabilities::fromBits(0);
    }

    public function setFrameless(int $windowHandle, int $dragHeight): void
    {
        // 逻辑拖动带高折成物理：桥提供给 EWMH 的命中区按屏幕像素算。
        drift_x11_set_frameless(
            $windowHandle,
            (int) round($dragHeight * X11Dpr::of($windowHandle)),
        );
    }

    public function beginDrag(int $windowHandle): void
    {
        drift_x11_begin_drag($windowHandle);
    }

    public function minimize(int $windowHandle): void
    {
        drift_x11_minimize($windowHandle);
    }

    public function maximizeToggle(int $windowHandle): void
    {
        drift_x11_maximize_toggle($windowHandle);
    }

    public function clientSize(int $windowHandle): Size
    {
        $raw = drift_x11_client_size($windowHandle);
        $dpr = X11Dpr::of($windowHandle);

        return Size::of(
            (float) ((int) ($raw['w'] ?? 0)) / $dpr,
            (float) ((int) ($raw['h'] ?? 0)) / $dpr,
        );
    }

    public function position(int $windowHandle): ?Point
    {
        // X11 顶层窗口的坐标即相对 root（无 Windows 式外框换算），但屏幕坐标是物理像素。
        $raw = drift_x11_window_position($windowHandle);
        $x = $raw['x'] ?? null;
        $y = $raw['y'] ?? null;
        if ($x === null || $y === null) {
            return null;   // 查询失败（死句柄 / 无 display）
        }

        $dpr = X11Dpr::of($windowHandle);

        return Point::of((float) $x / $dpr, (float) $y / $dpr);
    }

    public function place(int $windowHandle, Rect $rect, bool $maximized): void
    {
        // 与 setMinSize 同口径：入参逻辑像素，X 几何要物理像素。
        $dpr = X11Dpr::of($windowHandle);

        drift_x11_place_window(
            $windowHandle,
            $rect->left * $dpr,
            $rect->top * $dpr,
            $rect->width * $dpr,
            $rect->height * $dpr,
            $maximized,
        );
    }

    public function isMaximized(int $windowHandle): bool
    {
        // EWMH 完全最大化 = 横竖两维都置位（与 Win32 IsZoomed 对齐）。
        $state = drift_x11_window_state($windowHandle);

        return (bool) ($state['max_v'] ?? false) && (bool) ($state['max_h'] ?? false);
    }

    public function setCursor(int $windowHandle, CursorShape $shape): void
    {
        // 枚举 value 即桥 ABI 编号；名字→XC_* 的映射只在 C++ 一处。
        drift_x11_set_cursor($windowHandle, $shape->value);
    }

    public function pushSemantics(int $windowHandle, array $flatNodes): void
    {
        // AT-SPI 未接；不投节点等价于"无障碍树为空"，不是树损坏。
    }
}
