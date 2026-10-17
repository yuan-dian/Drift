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
    /**
     * 压入**变换**状态（偏移）。与 `restore()` 必须配对 —— W8 会断言栈深归零。
     *
     * ★ 变换栈与裁剪栈是**两条独立的栈**（W8 分别计深 `saveDepth` / `clipDepth`）：
     *   `save()` / `restore()` 只管偏移，`pushClip()` / `popClip()` 只管裁剪，
     *   `restore()` **不还原裁剪** —— 裁剪完全由那一对管理。三个实现必须同解：
     *   2026-10-17 前软光栅把裁剪并进变换栈（`popClip` 弹通用状态帧），同一份
     *   命令流两后端出不同像素（TextField 之后的兄弟丢容器偏移，见 smoke ⑨+1 E3/E4）。
     */
    public function save(): void;

    public function restore(): void;

    public function translate(float $dx, float $dy): void;

    /**
     * 压入裁剪矩形（与当前裁剪求交）。与 `popClip()` 必须配对。
     *
     * ★ 与 `save()` / `restore()` **互不干涉**：这一对不碰偏移，那一对不碰裁剪。
     */
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
     * ★ **字形级的 `offsetX`/`offsetY` 同属“排版给的几何”，同样必须施加**：
     *   口径与 `RasterCanvas::blendBitmap()` 一致 —— 逻辑空间叠加后**一次**量化
     *   （`round((pen + offsetX) × dpr)`），不是“量化后再加偏移”。
     *   ★ 2026-10-17 修复记录：SkiaCanvas 第一版只发 `round(pen × dpr)`，
     *     offset 被丢（今天生产者 offset 恒 0 ⇒ 潜伏；M4 真塑形时会活）。
     */
    public function drawParagraph(Paragraph $paragraph, Paint $paint, float $x = 0.0, float $y = 0.0): void;
}
