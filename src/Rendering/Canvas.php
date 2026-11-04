<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Geometry\Matrix2D;
use Drift\Geometry\Rect;
use Drift\Text\Paragraph;

/**
 * 绘制目标。三个实现：`RecordingCanvas`（生产入口）/ `SkiaCanvas`（回放器）/ `RasterCanvas`（软光栅）。
 *
 * 坐标：父级绘制子盒前已 translate 到盒原点，`paint` 以 (0,0) 为盒左上角。
 *
 * 能力分三层：Core（所有后端必须）/ Extended（Raster 允许降级近似）/
 * Optional（仅 Skia 级）。`capabilities()` 返回位掩码，调用方**查一次**，不要每次绘制都查。
 *
 * @api extension
 */
interface Canvas
{
    /**
     * 压入偏移状态。与 `restore()` 配对（配平由 W8 断言）。
     *
     * 变换栈与裁剪栈是**两条独立的栈**：`restore()` 不还原裁剪，裁剪只归
     * `pushClip()` / `popClip()` 管。混成一栈会让同一份命令流在两个后端出不同像素。
     */
    public function save(): void;

    public function restore(): void;

    public function translate(float $dx, float $dy): void;

    /**
     * 追加仿射变换，语义同 Skia `concat`：`CTM' = CTM · matrix`，后调用的更内层。
     * 注意这与 `Matrix2D::then()` 的链式读法相反。
     *
     * 纯平移的矩阵走 `translate()`（各实现短路累加 PHP 侧偏移），只有真变换才切换
     * 坐标模式 —— 这是不破坏既有像素基线的前提。
     *
     * 必须与 `save()` / `restore()` 配对；W8 只查栈深，查不出"忘了撤销"。
     */
    public function transform(Matrix2D $matrix): void;

    /**
     * 压入裁剪矩形（与当前裁剪求交）。不碰偏移。
     */
    public function pushClip(Rect $rect): void;

    public function popClip(): void;

    /** `$radius = 0` ⇒ 直角 */
    public function drawRect(Rect $rect, Paint $paint, float $radius = 0.0): void;

    /**
     * 画一个已排好的段落，原点为段落左上角。
     *
     * 命令里存 `Paragraph` 引用而非位图 ⇒ 字形在回放时才产生，录制代价与字形数无关。
     * 几何（`baseline` / 字形 `offsetX`）由排版给出，画布只施加、不重算，且必须在
     * 逻辑空间叠加后**一次**量化。
     */
    public function drawParagraph(Paragraph $paragraph, Paint $paint, float $x = 0.0, float $y = 0.0): void;

    /**
     * 能力位掩码（`CanvasCapabilities::CAP_*`）。
     *
     * `RecordingCanvas` 返回 `ALL_MASK`：它只录制，能不能画由回放时的画布裁决。
     */
    public function capabilities(): int;

    /**
     * 内接椭圆，Core 层。描边宽度取 `$paint->strokeWidth`，不乘 dpr。
     */
    public function drawEllipse(Rect $rect, Paint $paint): void;

    /**
     * 线段，Core 层。`Paint` 为 Fill 风格时抛 `E-OP-UNSUPPORTED` —— 线段没有内外圈。
     */
    public function drawLine(float $x1, float $y1, float $x2, float $y2, Paint $paint): void;

    /**
     * 矢量路径，Optional 层。几何在逻辑空间，吃画布的 `translate()`。
     */
    public function drawPath(Path $path, Paint $paint): void;

    /**
     * 图像：`$src` 子矩形画到 `$dst`，`$src` 为 null ⇒ 整幅缩放。
     * 缩放算法由后端决定（`RasterCanvas` 只有最近邻）。
     */
    public function drawImage(ImageHandle $image, ?Rect $src, Rect $dst): void;

    /**
     * 阴影，Optional 层。它是原语：字段层转调这里，不要另造一层半透明矩形。
     */
    public function drawShadow(Shadow $shadow): void;

    /**
     * 压入合成层（`opacity` 0..1），与 `popLayer()` 配对。当前 offset / clip 照常生效。
     *
     * 层深计入 `assertBalanced()`：不配对的 `pushLayer` 会让后续绘制整体变透而无人知晓。
     */
    public function pushLayer(float $opacity): void;

    public function popLayer(): void;

    /**
     * 压入路径裁剪（与当前裁剪求交），Extended 层。复用 `pushClip` 的栈，弹出仍用 `popClip()`。
     */
    public function pushClipPath(Path $path): void;

    /**
     * 把**已经画上去的背景**在 `$rect` 内模糊（毛玻璃）。
     *
     * ① 只模糊背景：之后画的内容是清晰的，文字必须在调用**之后**画，否则一起糊掉
     *    （与 `pushLayer()` 的"整体变透"是两件事）。
     * ② 范围是 `$rect`，不是当前裁剪 —— 矩形外一个像素都不动。
     * ③ 一次性操作，无 pop、不进 `assertBalanced()` 深度计数。
     *
     * `$sigma <= 0` 或矩形退化 ⇒ 空操作。Raster 后端近似为"不模糊"，`CAP_BACKDROP` 仍置位。
     */
    public function blurBackdrop(float $sigma, Rect $rect): void;
}
