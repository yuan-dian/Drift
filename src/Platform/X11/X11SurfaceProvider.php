<?php
declare(strict_types=1);

namespace Drift\Platform\X11;

use Drift\Platform\SurfaceProvider;
use Drift\Rendering\RenderSurface;

/**
 * X11 表面提供者：这一版只有软光栅（Skia Linux .a 未接），同窗口只造一个表面。
 * @internal 平台实现；应用只认 Drift\Platform\SurfaceProvider
 */
final class X11SurfaceProvider implements SurfaceProvider
{
    private ?RenderSurface $surface = null;

    public function __construct(
        private readonly int $windowHandle,
    ) {
    }

    public function surface(): RenderSurface
    {
        return $this->surface ??= new X11RasterSurface($this->windowHandle);
    }
}
