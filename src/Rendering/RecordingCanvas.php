<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Geometry\Matrix2D;
use Drift\Geometry\Rect;
use Drift\Text\Paragraph;

/**
 * 录制画布：把绘制命令写进 `DisplayList`，交给调用方的画布回放。
 *
 * 真机主树由 `Frame::paintComposed()` 直接画到 SkiaCanvas；开了重绘边界的盒
 * 才经 `LayerCache` 录放。离屏消费者（测试 / 预览）回放到 `RasterCanvas`。
 *
 * @api
 */
final class RecordingCanvas implements Canvas
{
    private readonly DisplayList $list;

    /** 对外声称的能力位；默认取回放端能力，见构造器 */
    private readonly int $capabilities;

    public function __construct(DisplayList $list, int $capabilities = -1)
    {
        $this->list = $list;
        // 默认 = 离屏回放端（Raster）的能力。全录会让 Optional op（drawShadow）
        // 在回放时 unsupportedOp 响亮失败。
        // ★ 不能把外部类静态调用写成参数默认值（AOT 下不安全），用 -1 哨兵在方法体内解析。
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

    /**
     * 把一整张子命令流作为**引用**嵌进本流（P2）。
     *
     * 只由 `DisplayList::replay()` 在父边界录制期间调用：子流录完即不可变，
     * 引用在父缓存存活期内稳定。回放端能力由子流录制时自己的 caps 判据保证，
     * 这里无需再查。
     */
    public function drawList(DisplayList $child): void
    {
        $this->list->record(DisplayList::OP_DRAW_LIST, $child);
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

    /** 纯平移降级成 OP_TRANSLATE：回放端有累加偏移快路，OP_TRANSFORM 会切坐标模式。 */
    public function transform(Matrix2D $matrix): void
    {
        if ($matrix->isTranslationOnly()) {
            $this->list->record(DisplayList::OP_TRANSLATE, $matrix->tx, $matrix->ty);

            return;
        }

        $this->list->record(DisplayList::OP_TRANSFORM, $matrix);
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

    /** 声称能力 = 构造时注入的回放端能力；不是全量。 */
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

    /** 帧末自证交给录制层的深度配平断言。 */
    public function assertFrameBalanced(): void
    {
        $this->list->assertBalanced();
    }
}
