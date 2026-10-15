<?php
declare(strict_types=1);

namespace Drift\Platform\Win32;

use Drift\Errors;
use Drift\Geometry\Size;
use Drift\Geometry\Scale;
use Drift\Platform\WindowHost;

use function drift_win_client_size;
use function drift_win_close;
use function drift_win_create;
use function drift_win_dpi;
use function drift_win_is_maximized;
use function drift_win_set_title;
use function drift_win_show;

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
}
