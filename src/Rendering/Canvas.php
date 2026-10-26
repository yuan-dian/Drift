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
 * **能力分级**（D2，2026-10-20 采纳）：7-op 时代的原语（`drawRect` /
 * `drawParagraph` / `save` / `pushClip` …）无条件支持；19 op 回植新增的面
 * 分三层 —— Core（`drawEllipse` / `drawLine`，所有后端必须）/ Extended
 * （渐变 / 虚线 / `pushClipPath` / `blurBackdrop`，Raster 允许降级近似）/
 * Optional（`drawPath` / `drawImage` / `drawShadow` / `pushLayer`，仅 Skia 级）。
 * `capabilities()` 是各后端对外的能力位掩码，`CustomPaint` 在拿到画布时
 * **查一次**，不在每次绘制时查。位语义见 `CanvasCapabilities`。
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

    /**
     * 能力位掩码（`CanvasCapabilities::CAP_*`，构建期查一次）。
     *
     * `RecordingCanvas` 返回 `ALL_MASK`：它只录制不 rasterize，能不能画
     * 由**回放时的画布**裁决（失败发生在回放侧，消息里带的是真后端名）。
     */
    public function capabilities(): int;

    /**
     * 椭圆（外接矩形 `$rect` 内切），Core 层。
     *
     * ★ 与 `drawRect` 同口径：`radius` 的事归矩形；`Paint::Stroke` 时
     *   描边宽度**不乘 dpr**（两个后端 conformance 的统一口径）。
     * ★ `Paint::Fill` + `Paint::Stroke` 都成立；`$paint->color->a === 0`
     *   时可整块跳过（与 `rasterize()` 的早退同一档）。
     */
    public function drawEllipse(Rect $rect, Paint $paint): void;

    /**
     * 线段（两端点，逻辑坐标），Core 层。
     *
     * ★ 宽度一律取 `$paint->strokeWidth`（逻辑单位，不乘 dpr）；`$paint`
     *   是 **Fill** 风格时报 `E-OP-UNSUPPORTED` —— 线段没有"内外圈"概念，
     *   Fill 语义不存在，静默按 1px 画正是最难查的那类错。
     */
    public function drawLine(float $x1, float $y1, float $x2, float $y2, Paint $paint): void;

    /**
     * 矢量路径填充/描边，Optional 层（P4）。
     *
     * ★ 路径的几何在**逻辑空间**，变换吃画布的 `translate()`（与
     *   `drawParagraph` 同一条纪律：不叠加当前偏移 = 位置全错且看不出来）。
     */
    public function drawPath(Path $path, Paint $paint): void;

    /**
     * 图像（`$src` 子矩形整幅画到 `$dst`，任一为 null ⇒ 整幅缩放），
     * Optional 层（P4）。
     *
     * ★ **只支持整数倍/任意缩放都在后端层消化**：`$dst` 是逻辑矩形，
     *   后端自己决定最近邻/双线性（`RasterCanvas` 先只做最近邻，文档注明）。
     */
    public function drawImage(ImageHandle $image, ?Rect $src, Rect $dst): void;

    /**
     * 阴影（投影形状的几何 + 偏移 + 模糊 + 浓度），Optional 层（P4）。
     *
     * ★ 它是**原语**：`BoxStyle.shadow` 字段（将来）转调这里，不要在
     *   字段层另造一套三层半透明矩形的老路（`RenderCard` 的现状）。
     */
    public function drawShadow(Shadow $shadow): void;

    /**
     * 压入合成层（`opacity` 整体不透明度，0..1），与 `popLayer()` 配对。
     *
     * ★ 层是**绘制栈**的一部分（`save`/`restore` 之外新增的第三条语义）：
     *   当前 offset/clip 照常生效，层只影响"层内累积的结果怎么合到下层"。
     * ★ W8 的配平断言（`DisplayList::assertBalanced`）**也覆盖层深**
     *   （W1/L3-3 起：`layerDepth` 同口径计深）。层的**语义**由 `CustomPaint`
     *   的契约保证（painter 自己配对，画布只执行）；**配平**是另一件事，
     *   归 W8。之前"不覆盖"是为了省一支计数，代价是不配对的 `pushLayer`
     *   让后续绘制整体变透而无人知晓。
     */
    public function pushLayer(float $opacity): void;

    public function popLayer(): void;

    /**
     * 压入路径裁剪（与当前裁剪求交），Extended 层，弹仍然用 `popClip()`。
     *
     * ★ 复用 `pushClip` 的裁剪栈（**不**开第二条栈）：两种裁剪的差别只在
     *   "裁什么形状"，弹栈语义完全一致（W8 的 `clipDepth` 同口径计深）。
     */
    public function pushClipPath(Path $path): void;

    /**
     * 把**已经画上去的背景**模糊掉，范围限制在 `$rect`（Extended 层）。
     *
     * 语义三条，缺一条就不是"毛玻璃"而是别的东西：
     * ① **只模糊背景** —— 本调用之后画的任何东西都是清晰的。毛玻璃面板的
     *    文字必须在模糊**之后**画，否则字也被糊掉（这是它与 `pushLayer()`
     *    最容易被混淆的地方：那个管"整体变透"，这个管"背后变糊"）。
     * ② **范围是 `$rect`**，不是当前裁剪 —— 裁剪可以更大（父容器裁的），
     *    但模糊只发生在这块矩形里，矩形外一个像素都不动。
     * ③ **一次性操作，不是栈** —— 没有 `pop`，没有配对，不进 `assertBalanced()`
     *    的深度计数。理由见下。
     *
     * ★★ **为什么不做成 `pushBackdropBlur()` / `popBackdropBlur()` 一对**：
     *   那样"层里到底该有什么"就有歧义 —— 子内容画在层里会被一起模糊
     *   （Skia 的 `saveLayer` 图像滤镜作用在**整个层**上），画在层外又需要
     *   另一套配对规则。做成一次性操作，"层里只有背景"由调用时机保证，
     *   子内容天然清晰，也不新增栈深度语义。
     *
     * ★ **坐标系同 `pushClip`**：`$rect` 按当前偏移解释（盒局部坐标）。
     * ★ `$sigma <= 0` 或矩形退化 ⇒ 什么都不做（不是错误：模糊半径 0 就是
     *   不模糊，与 `Shadow.blur = 0` 走硬边捷径同一口径）。
     * ★ **Raster 后端近似为"不模糊"**（`CAP_BACKDROP` 仍置位）：像素结果
     *   只少了模糊，结构与透明度照常 —— 与 `CAP_LAYER` 的 alpha 乘数栈同一档
     *   "Extended 允许降级近似"。金色基线不覆盖模糊质量。
     */
    public function blurBackdrop(float $sigma, Rect $rect): void;
}
