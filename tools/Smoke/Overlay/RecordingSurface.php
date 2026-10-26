<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Overlay;

use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\DisplayList;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RecordingCanvas;
use Drift\Rendering\RenderSurface;

/**
 * @internal 冒烟夹具：把一帧录成 `DisplayList` 的渲染表面。
 *
 * ★ 存在的理由：`SvgSurface` 只能给字符串，而浮层的**叠色顺序**（两层 scrim
 *   谁盖谁）只能靠像素回答 —— 字符串里两个同色 rect 换个顺序也看不出来。
 *   录一份 DisplayList 就能 `replay()` 到 `RasterCanvas` 上读像素。
 */
final class RecordingSurface implements RenderSurface
{
    private int $width = 0;

    private int $height = 0;

    private DisplayList $list;

    /** 回放到光栅时的底色（`setBackground` 改它；默认白 = 沿用旧行为） */
    private Color $background;

    public function __construct()
    {
        $this->list = new DisplayList();
        $this->background = Color::white();
    }

    public function resize(int $width, int $height, float $dpr): void
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function setBackground(Color $color): void
    {
        $this->background = $color;
    }

    public function beginFrame(float $timestampMs): Canvas
    {
        $this->list = new DisplayList();

        // 默认能力 = `RasterCanvas` 的支持面（`RecordingCanvas` 构造函数已按
        // "离屏回放端"取默认，见它的注释）⇒ 不会录下 Optional op。
        return new RecordingCanvas($this->list);
    }

    public function endFrame(): void
    {
    }

    /** 上一帧录到的命令表 */
    public function list(): DisplayList
    {
        return $this->list;
    }

    /** 把上一帧画到光栅上（读像素用）；底色 = `setBackground` 设的那个 */
    public function raster(): RasterCanvas
    {
        $raster = new RasterCanvas(
            max(1, $this->width),
            max(1, $this->height),
            $this->background,
        );
        $this->list->replay($raster);

        return $raster;
    }
}
