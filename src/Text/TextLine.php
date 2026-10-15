<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 排版后的一行（`Paragraph` 的组成单位）。
 *
 * 几何量都相对**段落原点**：`top` 是行顶，`baseline` 是基线，`offsetX` 是
 * 对齐之后行内容的起始 x（居中对齐时它不为 0）。
 *
 * @api
 */
final readonly class TextLine
{
    /** @param list<GlyphRun> $runs */
    public function __construct(
        public array $runs,
        public float $top,
        public float $baseline,
        public float $height,
        public float $width,
        public float $offsetX,
        public int $textStart,
        public int $textLength,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->runs === [] || $this->width <= 0.0;
    }

    public function bottom(): float
    {
        return $this->top + $this->height;
    }

    public function runCount(): int
    {
        return count($this->runs);
    }
}
