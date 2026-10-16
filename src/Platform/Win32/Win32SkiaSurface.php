<?php
declare(strict_types=1);

namespace Drift\Platform\Win32;

use Drift\Geometry\Scale;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\RenderSurface;
use Drift\Rendering\SkiaCanvas;

use function drift_win_present;

/**
 * Win32 的 Skia 表面：**Skia 光栅 + 贴屏**（§7.3 的 M1 后端之二）。
 *
 * ★ 与 `Win32RasterSurface` **只差一行**（造哪种画布），其余逐字相同：
 *   每帧一张新画布、`endFrame()` 里取回整块 RGBA、交给**同一个** `drift_win_present()`。
 *   这正是 §7.2 裁决 1 "`Canvas` 第一天就是接口"要换来的东西 ——
 *   换后端不动应用、不动 `Application`、不动贴屏路径。
 *
 * ★ **贴屏只有一处**：`drift_win_present()` 负责 RGBA→BGRA 与 `WM_PAINT` 重贴缓存。
 *   Skia 侧**不自己贴**，否则"窗口被遮住再露出来"时缓存里还是上一帧的旧像素（白屏）。
 *
 * ★ **每帧新建、用完显式 `dispose()`**：Skia 画布是 C++ 侧 `new` 出来的原生资源，
 *   不释放就是每帧漏一张 w×h×4。`dispose()` 必须在 `toRgba()` **之后**调用。
 *
 * @internal 平台实现；应用只认 `Drift\Rendering\RenderSurface`
 */
final class Win32SkiaSurface implements RenderSurface
{
    private int $width = 0;

    private int $height = 0;

    private float $dpr = 1.0;

    private ?SkiaCanvas $canvas = null;

    /** @param int $windowHandle 不透明句柄（只传给桥，这里不解引用） */
    public function __construct(
        private readonly int $windowHandle,
    ) {
    }

    public function resize(int $width, int $height, float $dpr): void
    {
        $this->width = max(0, $width);
        $this->height = max(0, $height);
        $this->dpr = $dpr > 0.0 ? $dpr : 1.0;
    }

    public function beginFrame(float $timestampMs): Canvas
    {
        // 背景白：Skia 的 bitmap 初值不确定，不清底色的话窗口会是黑的/花的，
        // 而布局与绘制完全正确 —— 现象像"渲染没跑"（`Win32RasterSurface` 同款）。
        $this->canvas = new SkiaCanvas(
            $this->width,
            $this->height,
            Color::white(),
            Scale::of($this->dpr),
        );

        return $this->canvas;
    }

    public function endFrame(): void
    {
        $canvas = $this->canvas;
        $this->canvas = null;

        if ($canvas === null) {
            return;
        }

        $rgba = $canvas->toRgba();
        $width = $canvas->width();
        $height = $canvas->height();

        // ★ 先取像素再释放 —— 顺序反了就是读一块已 delete 的内存
        $canvas->dispose();

        if ($width <= 0 || $height <= 0 || $rgba === '') {
            return;   // 0 尺寸窗口（最小化）：没有可贴的东西，也不是错误
        }

        drift_win_present($this->windowHandle, $rgba, $width, $height);
    }
}
