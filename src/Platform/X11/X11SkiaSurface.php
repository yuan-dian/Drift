<?php
declare(strict_types=1);

namespace Drift\Platform\X11;

use Drift\Geometry\Scale;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\RenderSurface;
use Drift\Rendering\SkiaCanvas;

use function drift_x11_present;

/**
 * X11 的 Skia 光栅表面：Skia 画像素，X11 桥一帧贴一次。
 *
 * ★ 与 `X11RasterSurface` 的差别只有"造哪种画布"：几何、DPI、背景、present 路径
 *   全部不变。这正是 `RenderSurface` 这个接口存在的意义 —— 换后端不动应用。
 *
 * ★ 像素只过一次界：`SkiaCanvas::toRgba()` 把 N32 翻成 RGBA，
 *   `drift_x11_present()` 再做 RGBA→XImage 的 BGRA 打包。没有 Win32 那种
 *   零拷贝直贴（Xlib 的 XPutImage 本来就要一份连续像素），不为了对称硬造一个。
 *
 * ★ 每帧新建、用完显式 `dispose()`：Skia 画布是 C++ 侧 `new` 出来的原生资源，
 *   不释放就是每帧漏一张 w×h×4。顺序必须是"先取像素、再 present、最后 dispose"。
 *
 * @internal 平台实现；应用只认 Drift\Rendering\RenderSurface
 */
final class X11SkiaSurface implements RenderSurface
{
    private int $width = 0;

    private int $height = 0;

    private float $dpr = 1.0;

    private ?SkiaCanvas $canvas = null;

    private Color $background;

    /** @param int $windowHandle 不透明句柄（只传给桥，这里不解引用） */
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
        return $this->canvas = new SkiaCanvas(
            $this->width,
            $this->height,
            $this->background,
            Scale::of($this->dpr),
        );
    }

    public function endFrame(): void
    {
        $canvas = $this->canvas;
        $this->canvas = null;

        if ($canvas === null) {
            return;
        }

        if ($canvas->isValid() && $canvas->width() > 0 && $canvas->height() > 0) {
            drift_x11_present(
                $this->windowHandle,
                $canvas->toRgba(),
                $canvas->width(),
                $canvas->height(),
            );
        }

        // 无效句柄也走 dispose：它内部对 0 幂等，统一收口不会漏。
        $canvas->dispose();
    }
}
