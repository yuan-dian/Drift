<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Geometry\Rect;

/**
 * 绘制目标（接口，§7.2 裁决 1）。
 *
 * GUI 仓的教训是"绘制层硬依赖：`Canvas` 是 `final class`、直连 `\gfx_*`
 * ⇒ 换绘制后端要先抽象"。Drift 第一天就是接口，三个实现：
 * `RecordingCanvas`（生产路径入口）/ `SkiaCanvas`（回放器）/ `RasterCanvas`（软光栅）。
 *
 * **坐标约定**（§7.2 裁决 3）：父级在绘制子盒前已 translate 到盒原点，
 * 所以 `paint` 内部以 (0,0) 为盒左上角作画。
 *
 * @api extension
 */
interface Canvas
{
    /** 压入状态（变换 / 画笔状态）。与 `restore()` 必须配对 —— W8 会断言栈深归零。 */
    public function save(): void;

    public function restore(): void;

    public function translate(float $dx, float $dy): void;

    /** 压入裁剪矩形（与当前裁剪求交）。与 `popClip()` 必须配对。 */
    public function pushClip(Rect $rect): void;

    public function popClip(): void;

    /** `radius = 0` ⇒ 直角 */
    public function drawRect(Rect $rect, Paint $paint, float $radius = 0.0): void;
}
