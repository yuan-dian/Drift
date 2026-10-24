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
        /**
         * 显式文字色（ARGB）；`-1` = 未设 ⇒ 绘制方用传入的 `Paint`。
         *
         * ★ 颜色**不进 `TextStyle`**：它不影响排版，进 `TextStyle::cacheKey()`
         *   会让"只换颜色"也整段重排（§8.1 的缓存键只含排版输入）。
         * ★ 用 `int` 而不是 `?Color`：可空对象在 AOT 下退化。
         */
        public int $argb = -1,
        /** 链接触发标识（空串 = 非链接），由 `TextSpan` 透传 */
        public string $tag = '',
        public bool $underline = false,
        /** 背景色（ARGB）；`-1` = 无 */
        public int $background = -1,
    ) {
    }

    /** 同几何、换颜色（`-1` = 回到"用 Paint"） */
    public function withColor(int $argb): self
    {
        return $this->withSpan($argb, $this->tag, $this->underline, $this->background);
    }

    /** 同几何、换 span 语义（颜色 / 链接标识 / 下划线 / 背景）—— 富文本整条一次设 */
    public function withSpan(int $argb, string $tag, bool $underline, int $background = -1): self
    {
        return new self(
            $this->glyphs,
            $this->face,
            $this->style,
            $this->width,
            $this->textStart,
            $this->textLength,
            $argb,
            $tag,
            $underline,
            $background,
        );
    }

    /** 跨 span 拼接：字形 cluster 与 `textStart` 一起抬到全文偏移 */
    public function shifted(int $offset): self
    {
        if ($offset === 0) {
            return $this;
        }

        $glyphs = [];
        foreach ($this->glyphs as $glyph) {
            $glyphs[] = $glyph->withCluster($glyph->cluster + $offset);
        }

        return new self(
            $glyphs,
            $this->face,
            $this->style,
            $this->width,
            $this->textStart + $offset,
            $this->textLength,
            $this->argb,
            $this->tag,
            $this->underline,
            $this->background,
        );
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
