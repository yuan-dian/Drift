<?php
declare(strict_types=1);

namespace Drift\Platform\Win32;

use Drift\Geometry\Scale;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\FrameStage;
use Drift\Rendering\RenderSurface;
use Drift\Rendering\SkiaCanvas;
use Drift\Rendering\SurfaceProfile;

use function drift_win_present_skia;

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
 * ★★ **三段计时受 `SurfaceProfile::$on` 控制**（默认关，关时每段只多一次静态读）。
 *   存在的理由：`26` §3.2 指出 M3 的分母不含 present，而真机一帧的大头很可能就在
 *   `toRgba` + `present` 这条"像素搬出搬进"的链上 —— 要判断该不该拆布局，先量它。
 *
 * @internal 平台实现；应用只认 `Drift\Rendering\RenderSurface`
 */
final class Win32SkiaSurface implements RenderSurface
{
    private int $width = 0;

    private int $height = 0;

    private float $dpr = 1.0;

    private ?SkiaCanvas $canvas = null;

    /** 每帧清屏色；`a < 255` ⇒ Skia 位图非不透明 + 窗口走逐像素 alpha 上屏 */
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
     * ★ 底色带 alpha 时 `SkiaCanvas` 用它清屏，且桥按底色 alpha 决定
     *   `allocN32Pixels(…, isOpaque)` —— 不翻 `isOpaque` 的话 Skia 会把
     *   alpha 通道写成恒 255（`kSrc` 覆盖写），逐像素透明无从谈起。
     * ★ **模式声明不在这里**（2026-10-26 收敛）：告诉壳"按逐像素上屏"归
     *   `WindowMaterial` + `WindowHost::applyMaterial()` —— 三个外观旋钮
     *   一次声明，壳按快照调和，不再靠调用顺序。
     */
    public function setBackground(Color $color): void
    {
        $this->background = $color;
    }

    public function beginFrame(float $timestampMs): Canvas
    {
        $prof = SurfaceProfile::$on;
        $t0 = $prof ? \hrtime(true) : 0;

        // 底色：Skia 的 bitmap 初值不确定，不清底色的话窗口会是黑的/花的，
        // 而布局与绘制完全正确 —— 现象像"渲染没跑"（`Win32RasterSurface` 同款）。
        $this->canvas = new SkiaCanvas(
            $this->width,
            $this->height,
            $this->background,
            Scale::of($this->dpr),
        );

        if ($prof) {
            SurfaceProfile::add(FrameStage::BEGIN_FRAME, \hrtime(true) - $t0);
        }

        return $this->canvas;
    }

    public function endFrame(): void
    {
        $canvas = $this->canvas;
        $this->canvas = null;

        if ($canvas === null) {
            return;
        }

        $prof = SurfaceProfile::$on;
        $t0 = $prof ? \hrtime(true) : 0;

        // ★★ 直贴（默认路径）：壳直接读 Skia 表面，**像素不进 PHP**。
        //   旧路径 `toRgba()` + `drift_win_present()` 每帧做两次**方向相反**的
        //   swizzle（BGRA→RGBA 再 RGBA→BGRA）+ 一次 4MB 跨界，
        //   实测合计占端到端 50%+；直贴把它缩成一次 memcpy。
        if ($canvas->isValid()) {
            drift_win_present_skia($this->windowHandle, $canvas->handle());

            // ★ 顺序：先贴后销毁。`drift_win_present_skia()` 内部取的是 Skia 表面的
            //   指针并立刻拷进 `g_frame`（同一帧用完）⇒ 生命周期符合约定。
            $canvas->dispose();

            if ($prof) {
                SurfaceProfile::add(FrameStage::PRESENT, \hrtime(true) - $t0);
            }

            return;
        }

        // 回落：句柄无效（创建失败 / 已销毁）⇒ 没有可贴的东西。
        // 与"画布无效时所有绘制静默跳过"同档 —— 这一帧没画，不是"应用该崩"。
        $canvas->dispose();
    }
}
