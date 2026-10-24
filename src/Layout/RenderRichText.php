<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Input\CursorShape;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;
use Drift\Text\Paragraph;
use Drift\Text\TextMetricsProvider;
use Drift\Text\TextSpan;
use Drift\Text\TextStyle;

/**
 * 富文本盒（W8-5，2026-10-24）—— `RenderText` 的多 span 版本。
 *
 * ★ 与 `RenderText` 的差别只有两点：
 *   ① 排版入口是 `layoutSpans()`（每个 span 各自整形，cluster 抬到全文偏移）；
 *   ② 绘制仍是一次 `drawParagraph()` —— **颜色与字号由 run 自带**
 *      （`GlyphRun::$argb` / `$run->style`），不是在这里逐段画。
 *   于是"断行跨 span、光标/选中用全文字节偏移"这两件白拿。
 *
 * ★ 行高按**最大 span 字号**算（`TextLayout::layoutSpans()` 把段落 style 的
 *   size 抬上去）—— 大字号 span 不会被段落行高压扁。
 *
 * @api
 */
final class RenderRichText extends RenderBox
{
    private ?Paragraph $paragraph = null;

    /** 复用缓冲：`color` 是构造注入的 readonly ⇒ 一支 Paint 跟着盒活 */
    private ?Paint $paint = null;

    /** @param list<TextSpan> $spans */
    public function __construct(
        private readonly array $spans,
        private readonly TextStyle $style,
        private readonly Color $color,
        private readonly TextMetricsProvider $metrics,
        private readonly Scale $scale = new Scale(1.0),
    ) {
    }

    /** @internal 冒烟 / 调试 */
    public function styleSize(): float
    {
        return $this->style->size;
    }

    /** @internal 冒烟 / 调试 */
    public function lineCount(): int
    {
        return $this->paragraph?->lineCount() ?? 0;
    }

    /** @internal 冒烟 / 调试：解析后的段落（看 run 的颜色/样式） */
    public function paragraph(): ?Paragraph
    {
        return $this->paragraph;
    }

    /** @internal 冒烟 / 调试：span 列表 */
    public function spans(): array
    {
        return $this->spans;
    }

    /** 第一个带 tag 的 run 的标识（`''` = 没有链接）—— Enter 的落点 */
    public function firstTag(): string
    {
        if ($this->paragraph === null) {
            return '';
        }

        foreach ($this->paragraph->lines as $line) {
            foreach ($line->runs as $run) {
                if ($run->tag !== '') {
                    return $run->tag;
                }
            }
        }

        return '';
    }

    /**
     * 本盒**局部**坐标 → 链接触发标识（`''` = 不是链接）。
     *
     * ★ 命中走 `Paragraph::tagAt()`（内部用 `indexAt()` 的字节偏移映射），
     *   与光标 / 选中同一套几何，不在这里重算 pen。
     */
    public function tagAt(float $x, float $y): string
    {
        return $this->paragraph?->tagAt($x, $y) ?? '';
    }

    /** 客户区坐标 → 链接触发标识（`''` = 不是链接） */
    public function tagAtClient(float $clientX, float $clientY): string
    {
        [$ox, $oy] = RenderBox::absoluteOrigin($this);

        return $this->tagAt($clientX - $ox, $clientY - $oy);
    }

    /**
     * 链接上给手型，其余不表态（回落到 `cursor()` 的链）。
     *
     * ★ 这是"同一个控件里按位置变光标"的**唯一**实现；`Widget::cursor()` 只能
     *   整段声明（整段手型会把说明文字也变成手型）。
     */
    #[\Override]
    public function cursorAt(float $x, float $y): ?CursorShape
    {
        return $this->tagAt($x, $y) !== '' ? CursorShape::PointingHand : null;
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $maxWidth = $constraints->hasBoundedWidth() ? $constraints->maxWidth : INF;

        $this->paragraph = $this->metrics->layoutSpans($this->spans, $this->style, $maxWidth, $this->scale);

        return new Size($this->paragraph->width, $this->paragraph->height);
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        $total = 0.0;
        foreach ($this->spans as $span) {
            $total += $this->metrics->advance($span->text, $span->style, $this->scale);
        }

        return $total;
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        $paragraph = $this->metrics->layoutSpans(
            $this->spans,
            $this->style,
            is_infinite($width) ? INF : $width,
            $this->scale,
        );

        return $paragraph->height;
    }

    public function visitChildren(callable $visitor): void
    {
    }

    protected function paintContent(Canvas $canvas): void
    {
        if ($this->paragraph === null || $this->paragraph->lineCount() === 0) {
            return;
        }

        // 装饰与段落**同序直画**（背景垫在字下）—— 不再过 `DisplayList`：
        // 录一条再回放只是把"直接画"做了两遍（`analysis/17` §5.1）。
        $this->paintRunDecorations($canvas);
        $canvas->drawParagraph($this->paragraph, $this->paint ??= Paint::fill($this->color), 0.0, 0.0);
    }

    /**
     * 按 run 画装饰（背景 / 下划线）—— **盒内**，不进 Canvas 后端。
     *
     * ★ 坐标是盒局部，直接画到当前画布 ⇒ Raster / Skia 两条路自动一致，
     *   后端一行都不用改。
     * ★ 必须排在字形**之前**：背景要垫在字下面。
     * ★ 背景高度 = 该 run 的**字体内容区**（`baseline − ascent .. baseline + descent`），
     *   不是整行高 —— 大字号 span 的背景才贴着字，不会把行距也涂上。字号取 run 自己的
     *   （同一行里 32pt 与 14pt 混排时各行其道）。
     */
    private function paintRunDecorations(Canvas $canvas): void
    {
        if ($this->paragraph === null) {
            return;
        }

        foreach ($this->paragraph->lines as $line) {
            $pen = $line->offsetX;

            foreach ($line->runs as $run) {
                if ($run->background >= 0) {
                    $metrics = $run->face->metrics();
                    $size = $run->style->size;
                    $ascent = $metrics->ascentPx($size);
                    $canvas->drawRect(
                        new Rect($pen, $line->baseline - $ascent, $run->width, $ascent + $metrics->descentPx($size)),
                        Paint::fill(Color::fromArgb($run->background)),
                    );
                }

                if ($run->underline) {
                    $underlineColor = $run->argb >= 0 ? Color::fromArgb($run->argb) : $this->color;
                    $canvas->drawRect(
                        new Rect($pen, $line->baseline + 2.0, $run->width, 1.0),
                        Paint::fill($underlineColor),
                    );
                }

                $pen += $run->width;
            }
        }
    }
}
