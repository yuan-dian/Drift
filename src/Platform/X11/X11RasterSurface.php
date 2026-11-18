<?php
declare(strict_types=1);

namespace Drift\Platform\X11;

use Drift\Geometry\Scale;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RenderSurface;

use function drift_x11_present;

/**
 * X11 软光栅表面：一帧一次 present，整块 RGBA 过桥，不逐命令过界。
 * @internal 平台实现；应用只认 Drift\Rendering\RenderSurface
 */
final class X11RasterSurface implements RenderSurface
{
    private int $width = 0;

    private int $height = 0;

    private float $dpr = 1.0;

    private ?RasterCanvas $canvas = null;

    private Color $background;

    public function __construct(
        private readonly int $windowHandle,
    ) {
        $this->background = Color::white();
    }

    public function resize(int $width, int $height, float $dpr): void
    {
        $this->width = max(0, $width);
        $this->height = max(0, $height);
        $this->dpr = $dpr > 0.0 ? $dpr : 1.0;
    }

    public function setBackground(Color $color): void
    {
        $this->background = $color;
    }

    public function beginFrame(float $timestampMs): Canvas
    {
        $this->canvas = new RasterCanvas(
            $this->width,
            $this->height,
            $this->background,
            Scale::of($this->dpr),
        );

        return $this->canvas;
    }

    public function endFrame(): void
    {
        $canvas = $this->canvas;
        $this->canvas = null;

        if ($canvas === null || $canvas->width() <= 0 || $canvas->height() <= 0) {
            return;
        }

        drift_x11_present(
            $this->windowHandle,
            $canvas->toRgba(),
            $canvas->width(),
            $canvas->height(),
        );
    }
}
