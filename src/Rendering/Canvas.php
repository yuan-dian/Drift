<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Geometry\Rect;
use Drift\Text\Paragraph;

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

    /**
     * 画一个**已排好的段落**，原点在段落左上角（逻辑单位）。
     *
     * ★ 命令里存的是 `Paragraph` 的**引用**，不是光栅后的位图 —— 字形位图在
     *   **回放时**才产生。于是：录制的代价与字形数无关（不破坏 §7.2 的缓冲复用
     *   契约），Skia 后端也能用真字体重画同一份命令（golden 才有可比性）。
     *
     * ★ 段的几何（`baseline` / `offsetX`）是**排版**给的，画布只负责施加，不重算。
     */
    public function drawParagraph(Paragraph $paragraph, Paint $paint, float $x = 0.0, float $y = 0.0): void;
}
