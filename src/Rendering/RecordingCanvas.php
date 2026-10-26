<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Geometry\Rect;
use Drift\Text\Paragraph;

/**
 * 录制画布：**生产路径的入口**（§7.2 裁决 1）。
 *
 * 它不画任何东西，只把绘制命令写进 `DisplayList`。真正的执行由 surface 决定
 * （M1 回放到 Skia CPU 光栅；测试回放到 `RasterCanvas`）。
 *
 * @api
 */
final class RecordingCanvas implements Canvas
{
    /**
     * @param int $capabilities 录制层**对外声称**的能力位。
     *
     * ★ 默认 `ALL_MASK`（"全录，回放裁决"）；但**离屏回放**的消费者（测试夹具、
     *   预览）必须传**回放后端**的能力 —— 否则会录下 Optional op（如 `drawShadow`），
     *   回放时 `unsupportedOp` 响亮失败。2026-10-25 给阴影接第一个消费者时踩到：
     *   `RecordingSurface` 全能力录 → `RasterCanvas` 回放 → `drawShadow` 崩。
     * ★ 传 `RasterCanvas::supportedCapabilities()` 而不是再拼一份常量：tpc 对
     *   "常量链"会生成坏代码（见 `CanvasCapabilities::ALL_MASK` 注释），一份定义更稳。
     */
    private readonly DisplayList $list;

    private readonly int $capabilities;

    public function __construct(DisplayList $list, int $capabilities = -1)
    {
        $this->list = $list;
        // ★ 默认 = **离屏回放端（Raster）的能力**，不是 `ALL_MASK`。
        //   理由：`RecordingCanvas` 的真实消费者只有"录下来再离线回放"（测试夹具、
        //   预览），回放端就是 `RasterCanvas`；真机 `Frame` 是 `paintComposed($canvas)`
        //   **直接画**到 `SkiaCanvas`，根本不经过这里。默认 `ALL_MASK` 会让它录下
        //   Optional op（`drawShadow`），回放时 `unsupportedOp` 响亮失败 ——
        //   2026-10-25 给阴影接第一个消费者时，smoke 的 Card / 浮层段全崩。
        // ★ 默认值**不能**写成参数默认值 `RasterCanvas::supportedCapabilities()`：
        //   外部类静态调用做默认参数在 AOT 下不安全。用 -1 哨兵，在**方法体内**解析。
        $this->capabilities = $capabilities < 0 ? RasterCanvas::supportedCapabilities() : $capabilities;
    }

    public static function create(): self
    {
        return new self(new DisplayList());
    }

    public function displayList(): DisplayList
    {
        return $this->list;
    }

    public function save(): void
    {
        $this->list->record(DisplayList::OP_SAVE);
    }

    public function restore(): void
    {
        $this->list->record(DisplayList::OP_RESTORE);
    }

    public function translate(float $dx, float $dy): void
    {
        $this->list->record(DisplayList::OP_TRANSLATE, $dx, $dy);
    }

    public function pushClip(Rect $rect): void
    {
        $this->list->record(DisplayList::OP_PUSH_CLIP, $rect);
    }

    public function popClip(): void
    {
        $this->list->record(DisplayList::OP_POP_CLIP);
    }

    public function drawRect(Rect $rect, Paint $paint, float $radius = 0.0): void
    {
        $this->list->record(DisplayList::OP_DRAW_RECT, $rect, $paint, $radius);
    }

    public function drawParagraph(Paragraph $paragraph, Paint $paint, float $x = 0.0, float $y = 0.0): void
    {
        $this->list->record(DisplayList::OP_DRAW_PARAGRAPH, $paragraph, $paint, $x, $y);
    }

    /**
     * 录制侧的**声称能力**（构造时注入，默认全能力）。
     *
     * ★ 默认"全录，回放裁决"（失败消息带真后端名，见 `Canvas` 契约）；离屏回放
     *   的消费者改成传回放后端的能力，让 Optional op 在**录制时**就降级。
     */
    public function capabilities(): int
    {
        return $this->capabilities;
    }

    public function drawEllipse(Rect $rect, Paint $paint): void
    {
        $this->list->record(DisplayList::OP_DRAW_ELLIPSE, $rect, $paint);
    }

    public function drawLine(float $x1, float $y1, float $x2, float $y2, Paint $paint): void
    {
        $this->list->record(DisplayList::OP_DRAW_LINE, $x1, $y1, $x2, $y2, $paint);
    }

    public function drawPath(Path $path, Paint $paint): void
    {
        $this->list->record(DisplayList::OP_DRAW_PATH, $path, $paint);
    }

    public function drawImage(ImageHandle $image, ?Rect $src, Rect $dst): void
    {
        $this->list->record(DisplayList::OP_DRAW_IMAGE, $image, $src, $dst);
    }

    public function drawShadow(Shadow $shadow): void
    {
        $this->list->record(DisplayList::OP_DRAW_SHADOW, $shadow);
    }

    public function pushLayer(float $opacity): void
    {
        $this->list->record(DisplayList::OP_LAYER_PUSH, $opacity);
    }

    public function popLayer(): void
    {
        $this->list->record(DisplayList::OP_LAYER_POP);
    }

    public function pushClipPath(Path $path): void
    {
        $this->list->record(DisplayList::OP_PUSH_CLIP_PATH, $path);
    }

    /** 模糊背景（Extended）—— 录制侧无条件录：Raster 回放时空实现，Skia 回放时真糊 */
    public function blurBackdrop(float $sigma, Rect $rect): void
    {
        $this->list->record(DisplayList::OP_BLUR_BACKDROP, $sigma, $rect);
    }
}
