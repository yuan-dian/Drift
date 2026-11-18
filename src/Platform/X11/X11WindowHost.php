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

use function drift_x11_client_size;
use function drift_x11_create;
use function drift_x11_destroy;
use function drift_x11_is_alive;
use function drift_x11_set_title;
use function drift_x11_show;

/**
 * X11 窗口端口。建窗/存活/尺寸/标题走桥；其余运行期旋钮这一版是显式 no-op
 * （材质、拖拽、最小化、光标、AT-SPI 语义），不是"平台支持但没生效"。
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
        // 没有 X11 侧的 close guard（WM_DELETE_WINDOW 只做事件上报），
        // 与 Win32 的 WM_CLOSE 不同：这里直接销毁。
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
    }

    public function setTitle(int $windowHandle, string $title): void
    {
        drift_x11_set_title($windowHandle, $title);
    }

    public function setMinSize(int $windowHandle, int $width, int $height): void
    {
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
    }

    public function beginDrag(int $windowHandle): void
    {
    }

    public function minimize(int $windowHandle): void
    {
    }

    public function maximizeToggle(int $windowHandle): void
    {
    }

    public function clientSize(int $windowHandle): Size
    {
        $raw = drift_x11_client_size($windowHandle);
        $scale = 1.0;

        return Size::of(
            (float) ((int) ($raw['w'] ?? 0)) / $scale,
            (float) ((int) ($raw['h'] ?? 0)) / $scale,
        );
    }

    public function position(int $windowHandle): ?Point
    {
        return null;
    }

    public function place(int $windowHandle, Rect $rect, bool $maximized): void
    {
    }

    public function isMaximized(int $windowHandle): bool
    {
        return false;
    }

    public function setCursor(int $windowHandle, CursorShape $shape): void
    {
    }

    public function pushSemantics(int $windowHandle, array $flatNodes): void
    {
        // AT-SPI 未接；不投节点等价于"无障碍树为空"，不是树损坏。
    }
}
