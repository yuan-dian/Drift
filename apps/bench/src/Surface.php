<?php
declare(strict_types=1);

namespace Drift\Bench;

use Drift\Rendering\Canvas;
use Drift\Rendering\DisplayList;
use Drift\Rendering\RecordingCanvas;
use Drift\Rendering\RenderSurface;

/**
 * 无头渲染表面：M3 用它跑完整四段，不开窗口、不碰平台（§7.2 裁决 4）。
 *
 * ★★ **为什么是 `RecordingCanvas` 而不是 `RasterCanvas`**（第一版用错，实测踩到）：
 *   `RasterCanvas::beginFrame()` 会 `clear()` 一次全屏 —— 1280×800 = 102 万像素的
 *   **逐像素 PHP 循环**。实测 showcase（70 个节点）的 paint 段因此是 **1078ms/帧**，
 *   而 layout 只有 0.87ms ⇒ 占比算出来 0.1%，结论完全反了。
 *
 *   路线 `25` §3 要的是 **paint + record**（"遍历树、产出绘制命令"），不是**光栅化**。
 *   光栅化在生产里由 Skia 的 C++ 完成，PHP 侧根本不参与 ⇒ 拿 RasterCanvas 量它，
 *   量的是"PHP 软光栅"这个**只存在于测试路径**的东西。
 *
 *   `RecordingCanvas` 只把命令写进 `DisplayList`（纯追加，无像素）—— 那才是每帧
 *   PHP 真正要做的事。真机 Skia 的 present 成本属于另一个基准（需要窗口）。
 *
 * ★ 每帧新建 `DisplayList`：生产也是每帧一份（`beginFrame` 交出当帧的画布），
 *   复用会把上一帧的 op 累进来 —— 那是**语义错误**，不是省分配。
 */
final class Surface implements RenderSurface
{
    public int $frames = 0;

    public int $ends = 0;

    public ?RecordingCanvas $canvas = null;

    /** 上一帧的记录条数（给"命令数"这类诊断留的，不参与判据） */
    public int $lastOps = 0;

    public function resize(int $width, int $height, float $dpr): void
    {
        // RecordingCanvas 不看尺寸（没有像素），这里保留签名只为满足 RenderSurface。
        // 记录一下是为了报告里能写明"按多大视口布局"。
        $this->width = $width;
        $this->height = $height;
    }

    public int $width = 0;

    public int $height = 0;

    public function beginFrame(float $timestampMs): Canvas
    {
        $this->frames++;
        $list = new DisplayList();
        $this->canvas = new RecordingCanvas($list);

        return $this->canvas;
    }

    public function endFrame(): void
    {
        $this->ends++;
        $this->lastOps = $this->canvas?->displayList()->count() ?? 0;
    }
}
