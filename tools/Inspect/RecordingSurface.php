<?php
declare(strict_types=1);

namespace Drift\Dev\Inspect;

use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\RecordingCanvas;
use Drift\Rendering\RenderSurface;

/**
 * 把一帧**录成指令**而不是光栅化 —— 快照的「绘制段」从它取（`07` §1.5 的观察面之一）。
 *
 * ★ 为什么不用 `RasterCanvas`：快照要的是"画了什么"，不是"画成什么颜色"。
 *   像素留给 golden（`golden-raster.php`），两者分工不重叠：
 *   指令回答"谁画的、画在哪"，像素回答"最后长什么样"。
 * ★ `RecordingCanvas::create()` 内部持一份 `DisplayList`，`opAt()` / `count()`
 *   已在（W8 之前就有），所以这里只是"把帧接进来"，不新增观测能力。
 * ★ 不留 `$width` / `$height` / `$background` 字段：录制不关心画布尺寸与清屏色
 *   （清屏由壳做，不进 `DisplayList`）。快照的尺寸记在快照自身的 `size` 段。
 *   留下"存了但没人读"的字段会被 W3 死字段量具判红 —— 这里是真的不需要。
 */
final class RecordingSurface implements RenderSurface
{
    /** 最近一帧的录制画布（`null` = 还没跑过帧） */
    public ?RecordingCanvas $last = null;

    public function resize(int $width, int $height, float $dpr): void
    {
    }

    public function setBackground(Color $color): void
    {
    }

    public function beginFrame(float $timestampMs): Canvas
    {
        $this->last = RecordingCanvas::create();

        return $this->last;
    }

    public function endFrame(): void
    {
    }
}
