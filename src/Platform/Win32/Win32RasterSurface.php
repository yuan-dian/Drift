<?php
declare(strict_types=1);

namespace Drift\Platform\Win32;

use Drift\Geometry\Scale;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RenderSurface;

use function drift_win_present;

/**
 * Win32 的 M1 表面：**PHP 软光栅 + 贴屏**（§7.3 的 M1 分期）。
 *
 * ★ 它是 `RenderSurface` 的第四个实现（另三个：`SvgSurface` 无头预览、
 *   `SkiaCanvas` 回放器、`RasterCanvas` 本身），而**这一层抽象存在的意义就是
 *   它可以被换掉**：Skia 后端接同一个 `SurfaceProvider`，应用侧一行不改。
 *
 * ★ 每帧 `new` 一个 `RasterCanvas`：`RasterCanvas` 构造就是"分配 w×h×4 字节 +
 *   清背景"，而 §7.2 的"缓冲复用"契约针对的是 **`DisplayList`**（命令缓冲），
 *   不是像素缓冲。像素缓冲复用会引入"上一帧的脏像素留在新帧里"这类难查的问题，
 *   而 M1 的性能天花板本来就不在这一层（§7.3 的诚实结论）。
 *
 * ★ **present 是唯一跨边界的一次**：一帧一次 `drift_win_present()`，传的是
 *   `RasterCanvas::toRgba()` 的整块字节。不是"每条绘制命令过一次桥"
 *   —— 那正是 GUI 仓 `gfx_*` 的形态，代价是每帧几百次跨边界调用。
 *
 * @internal 平台实现；应用只认 `Drift\Rendering\RenderSurface`
 */
final class Win32RasterSurface implements RenderSurface
{
    private int $width = 0;

    private int $height = 0;

    private float $dpr = 1.0;

    private ?RasterCanvas $canvas = null;

    /** 每帧清屏色；`a < 255` 的**模式声明**归 `WindowMaterial`（逐像素 on/off 不过这里） */
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

    /**
     * ★ **纯像素职责，零过桥**（2026-10-26 收敛）：底色是"画出来是什么像素"，
     *   每帧清屏用它；"合成器按什么模式上屏"（逐像素 on/off）是另一层的事，
     *   归 `WindowMaterial` + `WindowHost::applyMaterial()` 一次声明。以前这里
     *   顺手调 `drift_win_set_pixel_alpha()` —— 三个外观旋钮散在两个对象上、
     *   耦合靠调用顺序维持，正是那次收敛要消灭的形态。
     */
    public function setBackground(Color $color): void
    {
        $this->background = $color;
    }

    public function beginFrame(float $timestampMs): Canvas
    {
        // 底色：`RasterCanvas` 的像素缓冲初始是**全零**（= 全透明黑），
        // 不清底色的话窗口会是黑的，而布局/绘制完全正确 —— 现象像"渲染没跑"。
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
            return;   // 0 尺寸窗口（最小化）：没有可贴的东西，也不是错误
        }

        drift_win_present(
            $this->windowHandle,
            $canvas->toRgba(),
            $canvas->width(),
            $canvas->height(),
        );
    }
}
