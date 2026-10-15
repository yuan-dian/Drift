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
    public function __construct(private readonly DisplayList $list)
    {
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
}
