<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 一段同字体同样式的字形序列（§8.1 的塑形输出单位）。
 *
 * ★ 一段文本会被切成**多个 run**（拉丁 → CJK → emoji 的回退链，§8.2 坑 5），
 *   每个 run 一个 face —— 这就是"回退链按能力选 face"的落点。
 *
 * @api
 */
final readonly class GlyphRun
{
    /** @param list<Glyph> $glyphs */
    public function __construct(
        public array $glyphs,
        public FontFace $face,
        public TextStyle $style,
        public float $width,
        public int $textStart,
        public int $textLength,
    ) {
    }

    public function count(): int
    {
        return count($this->glyphs);
    }

    public function isEmpty(): bool
    {
        return $this->glyphs === [];
    }

    /** 字节偏移 → 该 run 内的字形下标（-1 = 不在此 run） */
    public function indexOfCluster(int $byteOffset): int
    {
        foreach ($this->glyphs as $index => $glyph) {
            if ($glyph->cluster === $byteOffset) {
                return $index;
            }
        }
        return -1;
    }

    public function advanceTo(int $glyphIndex): float
    {
        $total = 0.0;
        $limit = min($glyphIndex, count($this->glyphs));
        for ($i = 0; $i < $limit; $i++) {
            $total += $this->glyphs[$i]->advance;
        }
        return $total;
    }

    public function equals(GlyphRun $other): bool
    {
        return $this->width === $other->width
            && $this->textStart === $other->textStart
            && $this->textLength === $other->textLength
            && $this->style->equals($other->style)
            && count($this->glyphs) === count($other->glyphs);
    }
}
