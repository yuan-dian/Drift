<?php
declare(strict_types=1);

namespace Drift\Text;

use Drift\Geometry\Scale;

/**
 * 不可变排版结果（§8.1）。
 *
 * ★ 它是**值对象，不是句柄**。Skia 后端若需要缓存 `skia::textlayout::Paragraph`
 *   对象，那是 `SkiaCanvas` / `SkiaTextMetricsProvider` 的**私有实现细节**，
 *   不上公共接口（§7.1）。缓存归 `TextMetricsProvider` 实现内部，
 *   键 = `(text, style, maxWidth, scale)`。
 *
 * @api
 */
final readonly class Paragraph
{
    /** @param list<TextLine> $lines */
    public function __construct(
        public string $text,
        public array $lines,
        public float $width,
        public float $height,
        public TextStyle $style,
        public float $maxWidth,
    ) {
    }

    /**
     * 缓存键（§8.1）：`(text, style, maxWidth, scale)`。
     *
     * ★ 静态版给 `TextMetricsProvider` 用 —— 它必须在**排版之前**算键（那时还没有
     *   `Paragraph`），所以键的算法只有这一处。
     */
    public static function keyFor(string $text, TextStyle $style, float $maxWidth, Scale $scale): string
    {
        return hash('sha256', implode("\x1f", [
            $text,
            $style->cacheKey(),
            (string) $maxWidth,
            (string) $scale->dpr,
        ]));
    }

    public function cacheKey(Scale $scale): string
    {
        return self::keyFor($this->text, $this->style, $this->maxWidth, $scale);
    }

    public function lineCount(): int
    {
        return count($this->lines);
    }

    public function isEmpty(): bool
    {
        return $this->lines === [];
    }

    /**
     * 光标位置的**唯一入口**（§8.2 坑 7）。
     *
     * 返回**源文本的字节偏移**。不在外面各写一套"x → 下标"的算法 ——
     * grapheme cluster 与 bidi 的复杂度只在这里面对一次。
     */
    public function indexAt(float $x, float $y): int
    {
        if ($this->lines === []) {
            return 0;
        }

        $line = $this->lines[0];
        foreach ($this->lines as $candidate) {
            if ($y >= $candidate->top && $y < $candidate->bottom()) {
                $line = $candidate;
                break;
            }
            if ($y >= $candidate->bottom()) {
                $line = $candidate; // 落在行间空隙 ⇒ 取上一行
            }
        }

        $pen = $line->offsetX;
        foreach ($line->runs as $run) {
            foreach ($run->glyphs as $glyph) {
                $midpoint = $pen + $glyph->advance / 2.0;
                if ($x < $midpoint) {
                    return $glyph->cluster;
                }
                $pen += $glyph->advance;
            }
        }

        return $line->textStart + $line->textLength;
    }

    /** 某个字节偏移落在第几行 */
    public function lineIndexAt(int $byteOffset): int
    {
        foreach ($this->lines as $index => $line) {
            if ($byteOffset < $line->textStart + $line->textLength) {
                return $index;
            }
        }
        return max(0, count($this->lines) - 1);
    }
}
