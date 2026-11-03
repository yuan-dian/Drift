<?php
declare(strict_types=1);

namespace Drift\Text;

use Drift\Geometry\Rect;
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

    /**
     * 富文本缓存键（W8-5）：各 span 的 (text, 排版样式) + 段落样式 + maxWidth + scale。
     *
     * ★ **不含颜色（argb）**：颜色不影响排版，进键会让"只换颜色"也整段重排 ——
     *   §8.1 的键只收**影响排版的输入**。
     *
     * @param list<TextSpan> $spans
     */
    public static function keyForSpans(array $spans, TextStyle $style, float $maxWidth, Scale $scale): string
    {
        $parts = [];
        foreach ($spans as $span) {
            $parts[] = $span->text;
            $parts[] = $span->style->cacheKey();
        }

        return hash('sha256', implode("\x1f", [
            implode("\x1e", $parts),
            $style->cacheKey(),
            (string) $maxWidth,
            (string) $scale->dpr,
        ]));
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

    /**
     * 某个字节偏移落在第几行。
     *
     * ★ 判据是「**最后一个行首 ≤ offset 的行**」，不是「offset 落在本行区间内」。
     *   后者对**空行**永远为假（`textLength` 为 0 ⇒ `offset < 行首` 恒不成立），
     *   于是空行上的插入点会翻到下一行 —— 多行输入框里表现为"空行上按右键，
     *   插入符跳到下一行行首"。换行符本身也因此归**上一行**（插入符画在该行行末），
     *   与所有编辑器的观感一致。
     */
    public function lineIndexAt(int $byteOffset): int
    {
        $found = 0;
        foreach ($this->lines as $index => $line) {
            if ($line->textStart > $byteOffset) {
                break;
            }
            $found = $index;
        }

        return $found;
    }

    /**
     * 字节偏移 ⇒ 插入点 x（**段落局部**，已含对齐后的行起点 `offsetX`）。
     *
     * ★ `indexAt()` 的反向，必须与它同住一处：多行输入框的插入符、选区端点、
     *   IME 候选窗位置全要这条换算。在外面重写一遍就是第二个答案。
     *
     * ★ 行内位置**只能靠累加字形 advance 得到**：单行输入框那种
     *   `advance(前缀)` 在这里不成立 —— 前缀跨行时量出来的是整段宽度。
     */
    public function caretX(int $offset): float
    {
        if ($this->lines === []) {
            return 0.0;
        }

        return $this->caretXInLine($this->lineIndexAt($offset), $offset);
    }

    /** 第 `$index` 行的行框（段落局部坐标） */
    public function lineRect(int $index): Rect
    {
        if (!isset($this->lines[$index])) {
            return new Rect(0.0, 0.0, 0.0, 0.0);
        }

        $line = $this->lines[$index];

        return new Rect($line->offsetX, $line->top, $line->width, $line->height);
    }

    /**
     * 选区覆盖的矩形，**每行一段**。
     *
     * ★ 多行选区不是单个矩形 —— 用一整个盒子框住会把行尾之外的空白也涂上，
     *   与所有主流实现（Win32 `EDIT`、Qt、浏览器）的观感都不同。
     * ★ 空行段宽度为 0：宽度由调用方补（它才知道插入符多粗），段落层不猜。
     *
     * @return list<Rect>
     */
    public function selectionRects(int $start, int $end): array
    {
        if ($end <= $start || $this->lines === []) {
            return [];
        }

        $first = $this->lineIndexAt($start);
        $last = $this->lineIndexAt($end);

        $rects = [];
        for ($index = $first; $index <= $last; $index++) {
            $line = $this->lines[$index];
            $lineEnd = $line->textStart + $line->textLength;

            // ★ 端点归属本行：末行取到 `$end`，其余行取到行尾。`caretX()` 不能
            //   直接用于行尾 —— 它按 `lineIndexAt()` 定位，行尾会翻到下一行行首。
            $from = max($start, $line->textStart);
            $to = min($end, $lineEnd);
            if ($to < $from) {
                continue;
            }

            $left = $this->caretXInLine($index, $from);
            $rects[] = new Rect($left, $line->top, $this->caretXInLine($index, $to) - $left, $line->height);
        }

        return $rects;
    }

    /** 行内 `$offset` 的 x —— `caretX()` 与 `selectionRects()` 的唯一换算 */
    private function caretXInLine(int $index, int $offset): float
    {
        $line = $this->lines[$index];
        $pen = $line->offsetX;

        foreach ($line->runs as $run) {
            foreach ($run->glyphs as $glyph) {
                // cluster 是字形在源文本里的起始字节；落在这个字形之前 ⇒ 就是它左边
                if ($glyph->cluster >= $offset) {
                    return $pen;
                }
                $pen += $glyph->advance;
            }
        }

        return $pen;
    }

    /**
     * 命中链接触发标识（W8-5）：`(x, y)` 是**段落局部**坐标。
     *
     * ★ 返回 `''` = 那个位置不是链接。用 `indexAt()` 把坐标换成字节偏移，
     *   再找覆盖它的 run —— 与光标用同一套映射，不另写一份几何。
     */
    public function tagAt(float $x, float $y): string
    {
        if ($this->lines === []) {
            return '';
        }

        $cluster = $this->indexAt($x, $y);
        foreach ($this->lines as $line) {
            foreach ($line->runs as $run) {
                if ($run->tag === '') {
                    continue;
                }
                if ($cluster >= $run->textStart && $cluster < $run->textStart + $run->textLength) {
                    return $run->tag;
                }
            }
        }

        return '';
    }
}
