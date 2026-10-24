<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\DisplayList;
use Drift\Rendering\Paint;
use Drift\Rendering\RecordingCanvas;
use Drift\Text\Paragraph;
use Drift\Text\TextMetricsProvider;
use Drift\Text\TextSpan;
use Drift\Text\TextStyle;

/**
 * 富文本盒（W8-5，2026-10-24）—— `RenderText` 的多 span 版本。
 *
 * @backend-branch-allowed: 与 `RenderText` 同一个理由 —— 用 `RecordingCanvas`
 * 把段落录进 `DisplayList` 再 replay 到真画布，是 DisplayList 的**既定消费形态**
 * （每帧重录、缓冲复用），不是"按后端分叉"：软光栅与 Skia 录的是同一份命令流。
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

    /** 复用缓冲（§7.2 裁决 2：不每帧 new） */
    private ?DisplayList $list = null;

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

    /**
     * 客户区坐标 → 链接触发标识（`''` = 不是链接）。
     *
     * ★ 命中走 `Paragraph::tagAt()`（内部用 `indexAt()` 的字节偏移映射），
     *   与光标 / 选中同一套几何，不在这里重算 pen。
     */
    public function tagAtClient(float $clientX, float $clientY): string
    {
        if ($this->paragraph === null) {
            return '';
        }

        [$ox, $oy] = RenderBox::absoluteOrigin($this);

        return $this->paragraph->tagAt($clientX - $ox, $clientY - $oy);
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

        $this->list ??= new DisplayList();
        $this->list->reset();

        $recorder = new RecordingCanvas($this->list);

        // ★ 下划线在**盒内**画（不进 Canvas 后端）：坐标是盒局部，随 DisplayList
        //   一起 replay ⇒ Raster / Skia 两条路自动一致，后端一行都不用改。
        foreach ($this->paragraph->lines as $line) {
            $pen = $line->offsetX;
            foreach ($line->runs as $run) {
                if ($run->underline) {
                    $underlineColor = $run->argb >= 0 ? Color::fromArgb($run->argb) : $this->color;
                    $recorder->drawRect(
                        new Rect($pen, $line->baseline + 2.0, $run->width, 1.0),
                        Paint::fill($underlineColor),
                    );
                }
                $pen += $run->width;
            }
        }

        $recorder->drawParagraph(
            $this->paragraph,
            Paint::fill($this->color),
            0.0,
            0.0,
        );
        $this->list->replay($canvas);
    }
}
