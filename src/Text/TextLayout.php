<?php
declare(strict_types=1);

namespace Drift\Text;

use Drift\Errors;
use Drift\Geometry\Scale;

/**
 * 断行 / 对齐 / 行高 / ellipsize（§8.1 第四层）。
 *
 * ★ **同一份断行算法在两侧运行**（§14.2）：真测量后端与确定性参考实现共用这个类，
 *   差异只可能来自"度量"那一层 —— 于是 conformance 抓住的是真差异，而不是
 *   "模拟器与真实排版各自漂移"（GUI 仓的教训）。
 *
 * 贪心断行：能放下就放，放不下就回退到最后一个可断点。可断点由
 * `Utf8::canBreakBetween()` 给（拉丁按空白、CJK 按字边界 + 禁则）。
 *
 * @api
 */
final class TextLayout
{
    public const ELLIPSIS = 0x2026;
    public const LINE_FEED = 0x0A;

    public function __construct(private readonly TextShaper $shaper)
    {
    }

    public function layout(
        string $text,
        TextStyle $style,
        float $maxWidth,
        Scale $scale,
        FontCollection $collection,
    ): Paragraph {
        $align = $style->align;
        $maxLines = $style->maxLines;
        $ellipsize = $style->ellipsize;

        // 行高与基线取**回退链首**（最匹配的那个 face）—— 空文本也要有行高，否则塌成 0
        $chain = $collection->resolve($style->family, $style->weight, $style->slant);
        if ($chain === []) {
            Errors::report('文本栈：家族「' . $style->family->name . '」没有可用的字体面');
        }
        $primary = $chain[0];

        $metrics = $primary->metrics();
        $lineHeight = $metrics->lineHeight($style->size, $style->lineHeight);
        $baseline = $metrics->baselineOffset($style->size, $style->lineHeight);

        $flat = [];
        foreach ($this->shaper->shape($text, $style, $collection, $scale->dpr) as $run) {
            foreach ($run->glyphs as $glyph) {
                $flat[] = ['g' => $glyph, 'r' => $run];
            }
        }

        $ranges = $this->breakLines($flat, $maxWidth, $style);
        $ellipsisLine = -1;
        if ($maxLines > 0 && count($ranges) > $maxLines) {
            $ranges = array_slice($ranges, 0, $maxLines);
            if ($ellipsize) {
                $ellipsisLine = $maxLines - 1;
            }
        }

        $textLength = strlen($text);
        $ellipsisFace = $collection->faceFor(self::ELLIPSIS, $style->family, $style->weight, $style->slant);
        $ellipsisWidth = $ellipsisFace->glyphAdvance(self::ELLIPSIS) * $style->size + $style->letterSpacing;
        $lineCount = count($ranges);
        $justify = $align === TextAlign::Justify && !is_infinite($maxWidth);

        $lines = [];
        $contentWidth = 0.0;
        foreach ($ranges as $index => [$start, $end]) {
            $width = $this->measure($flat, $start, $end, $style);

            $ellipsis = null;
            if ($index === $ellipsisLine) {
                // 省略号必须放得下：放不下就从行尾回退字形
                $budget = is_infinite($maxWidth) ? INF : max(0.0, $maxWidth - $ellipsisWidth);
                while ($end > $start && $width > $budget) {
                    $end--;
                    $width -= $flat[$end]['g']->advance + $style->letterSpacing;
                }
                $cluster = $end < count($flat) ? $flat[$end]['g']->cluster : $textLength;
                $ellipsis = $this->ellipsisRun($ellipsisFace, $style, $cluster);
                $width += $ellipsis->width;
            }

            // 末行不拉伸（排版惯例：最后一行左对齐）
            $stretch = $justify && $index < $lineCount - 1 ? max(0.0, $maxWidth - $width) : 0.0;
            $runs = $this->sliceRuns($flat, $start, $end, $style, $stretch);
            if ($ellipsis !== null) {
                $runs[] = $ellipsis;
            }

            $textStart = $start < count($flat) ? $flat[$start]['g']->cluster : $textLength;
            $textEnd = $end < count($flat) ? $flat[$end]['g']->cluster : $textLength;
            $top = $index * $lineHeight;

            $lines[] = new TextLine(
                $runs,
                $top,
                $top + $baseline,
                $lineHeight,
                $width,
                $this->alignOffset($align, $width, $maxWidth),
                $textStart,
                $textEnd - $textStart
            );
            $contentWidth = max($contentWidth, $width);
        }

        return new Paragraph(
            $text,
            $lines,
            is_infinite($maxWidth) ? $contentWidth : min($maxWidth, $contentWidth),
            count($lines) * $lineHeight,
            $style,
            $maxWidth
        );
    }

    /**
     * 贪心断行。
     *
     * ★ **只在可断点断行**：拉丁词内部没有断点，所以放不下的整词**溢出**而不是被
     *   劈开（§13.1 C5：溢出策略是契约）。CJK 的字边界处处可断，所以中文永远能折。
     *   ⇒ "溢出了"与"断错位置"是两种症状，各有确定来源。
     *
     * ★ 溢出判定必须**早于**本位置的断点登记：否则本位置刚登记的断点会把溢出的
     *   那个字形一起吞进上一行（实测 bug）。
     *
     * @param list<array{g: Glyph, r: GlyphRun}> $flat
     * @return list<array{0: int, 1: int}> 每行的 [起, 止) 在 $flat 上的下标
     */
    private function breakLines(array $flat, float $maxWidth, TextStyle $style): array
    {
        $count = count($flat);
        if ($count === 0) {
            return [[0, 0]];
        }

        $lines = [];
        $lineStart = 0;
        $lastBreak = -1;
        $pen = 0.0;
        $index = 0;

        while ($index < $count) {
            $codepoint = $flat[$index]['g']->codepoint;

            if ($codepoint === self::LINE_FEED) {
                $lines[] = [$lineStart, $index];
                $index++;
                $lineStart = $index;
                $pen = 0.0;
                $lastBreak = -1;
                continue;
            }

            $pen += $flat[$index]['g']->advance + $style->letterSpacing;

            if (!is_infinite($maxWidth) && $pen > $maxWidth && $lastBreak > $lineStart) {
                $lines[] = [$lineStart, $lastBreak];
                $lineStart = $lastBreak;
                $index = $lastBreak;
                $pen = 0.0;
                $lastBreak = -1;
                continue;
            }

            if ($index + 1 < $count
                && Utf8::canBreakBetween($codepoint, $flat[$index + 1]['g']->codepoint)) {
                $lastBreak = $index + 1;
            }

            $index++;
        }

        $lines[] = [$lineStart, $count];

        return $this->trimWhitespace($lines, $flat);
    }

    /**
     * 行首 / 行尾空白不参与排版：断点落在空白之后，所以空白会留成上一行的尾部。
     *
     * @param list<array{0: int, 1: int}> $lines
     * @param list<array{g: Glyph, r: GlyphRun}> $flat
     * @return list<array{0: int, 1: int}>
     */
    private function trimWhitespace(array $lines, array $flat): array
    {
        $trimmed = [];
        foreach ($lines as $position => [$start, $end]) {
            while ($end > $start && Utf8::isWhitespace($flat[$end - 1]['g']->codepoint)) {
                $end--;
            }
            if ($position > 0) {
                while ($start < $end && Utf8::isWhitespace($flat[$start]['g']->codepoint)) {
                    $start++;
                }
            }
            $trimmed[] = [$start, $end];
        }
        return $trimmed;
    }

    /** @param list<array{g: Glyph, r: GlyphRun}> $flat */
    private function measure(array $flat, int $start, int $end, TextStyle $style): float
    {
        $width = 0.0;
        for ($i = $start; $i < $end; $i++) {
            $width += $flat[$i]['g']->advance + $style->letterSpacing;
        }
        return $width;
    }

    /**
     * 把 [start, end) 的字形按**所属 run** 切回若干 `GlyphRun`。
     *
     * `$stretch` > 0 时把富余宽度摊到空白上（没有空白就摊到字形之间）—— 这就是
     * `TextAlign::Justify` 的落点：拉伸改变的是**步进**，不是别的什么。
     *
     * @param list<array{g: Glyph, r: GlyphRun}> $flat
     * @return list<GlyphRun>
     */
    private function sliceRuns(array $flat, int $start, int $end, TextStyle $style, float $stretch): array
    {
        $gapCount = 0;
        for ($i = $start; $i < $end; $i++) {
            if (Utf8::isWhitespace($flat[$i]['g']->codepoint)) {
                $gapCount++;
            }
        }
        $glyphCount = $end - $start;
        $share = 0.0;
        if ($stretch > 0.0 && $glyphCount > 1) {
            $share = $gapCount > 0 ? $stretch / $gapCount : $stretch / ($glyphCount - 1);
        }

        $runs = [];
        $glyphs = [];
        $active = null;
        $width = 0.0;

        for ($i = $start; $i < $end; $i++) {
            $glyph = $flat[$i]['g'];
            $owner = $flat[$i]['r'];
            if ($active !== $owner) {
                if ($active !== null) {
                    $runs[] = $this->makeRun($glyphs, $active->face, $style, $width);
                }
                $active = $owner;
                $glyphs = [];
                $width = 0.0;
            }

            $advance = $glyph->advance + $style->letterSpacing;
            if ($share > 0.0) {
                if ($gapCount > 0) {
                    if (Utf8::isWhitespace($glyph->codepoint)) {
                        $advance += $share;
                    }
                } elseif ($i < $end - 1) {
                    $advance += $share;
                }
            }
            $glyphs[] = $glyph->withAdvance($advance);
            $width += $advance;
        }
        if ($active !== null) {
            $runs[] = $this->makeRun($glyphs, $active->face, $style, $width);
        }

        return $runs;
    }

    /** @param list<Glyph> $glyphs */
    private function makeRun(array $glyphs, FontFace $face, TextStyle $style, float $width): GlyphRun
    {
        $first = $glyphs[0];
        $last = $glyphs[count($glyphs) - 1];
        $start = $first->cluster;

        return new GlyphRun(
            $glyphs,
            $face,
            $style,
            $width,
            $start,
            $last->cluster + strlen(Utf8::encode($last->codepoint)) - $start
        );
    }

    private function ellipsisRun(FontFace $face, TextStyle $style, int $cluster): GlyphRun
    {
        $advance = $face->glyphAdvance(self::ELLIPSIS) * $style->size;
        $glyph = new Glyph(self::ELLIPSIS, $advance, 0.0, 0.0, $cluster);

        return new GlyphRun(
            [$glyph],
            $face,
            $style,
            $advance + $style->letterSpacing,
            $cluster,
            strlen(Utf8::encode(self::ELLIPSIS))
        );
    }

    private function alignOffset(TextAlign $align, float $lineWidth, float $maxWidth): float
    {
        if (is_infinite($maxWidth)) {
            return 0.0;
        }
        return match ($align) {
            // Justify 的富余已经在 sliceRuns 里摊成步进，这里不再偏移
            TextAlign::Start, TextAlign::Justify => 0.0,
            TextAlign::Center => max(0.0, ($maxWidth - $lineWidth) / 2.0),
            TextAlign::End => max(0.0, $maxWidth - $lineWidth),
        };
    }
}
