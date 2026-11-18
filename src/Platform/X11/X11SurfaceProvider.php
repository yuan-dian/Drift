<?php
declare(strict_types=1);

namespace Drift\Platform\X11;

use Drift\Platform\SurfaceProvider;
use Drift\Rendering\RenderSurface;

/**
 * X11 表面提供者：这一版只有软光栅（Skia Linux .a 未接），同窗口只造一个表面。
 *
 * ★ 创建时按当前客户区给一次尺寸：`attach()` 的同步首帧跑在 resize 事件之前，
 *   不先定尺寸就是 0×0 画布、`present()` 整段跳过 —— 真实应用首帧空白。
 *   尺寸来源与 `X11Screen::viewport()` 同为 `X11WindowHost::clientSize()`（单点）。
 *
 * @internal 平台实现；应用只认 Drift\Platform\SurfaceProvider
 */
final class X11SurfaceProvider implements SurfaceProvider
{
    private ?RenderSurface $surface = null;

    public function __construct(
        private readonly int $windowHandle,
        private readonly ?X11WindowHost $windows = null,
    ) {
    }

    public function surface(): RenderSurface
    {
        if ($this->surface !== null) {
            return $this->surface;
        }

        $surface = new X11RasterSurface($this->windowHandle);
        $size = ($this->windows ?? new X11WindowHost())->clientSize($this->windowHandle);
        $surface->resize((int) $size->width, (int) $size->height, 1.0);

        return $this->surface = $surface;
    }
}
