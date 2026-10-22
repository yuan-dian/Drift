<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\DisplayList;
use Drift\Rendering\Paint;
use Drift\Rendering\RecordingCanvas;
use Drift\Text\Paragraph;
use Drift\Text\TextMetricsProvider;
use Drift\Text\TextStyle;

/**
 * 文本盒（§13.1 / §8）。
 *
 * @backend-branch-allowed: 文本盒用 `RecordingCanvas` 把段落录进 `DisplayList`
 * 再 replay 到真画布 —— 这是 DisplayList 的**既定消费形态**（每帧重录、
 * 缓冲复用），不是"按后端分叉"：软光栅与 Skia 两条路上录的是同一份命令流，
 * 走的同一条 replay。若将来要有第二个录制缓冲实现，改的是这里的构造点
 * （一处），不是行为分支。
 *
 * ★ **问度量，不问平台**：`TextMetricsProvider` 在造盒时注入（§14.2），所以同一份
 *   Widget 树在测试里喂固定度量 ⇒ 布局可复现、golden 可比对。
 *
 * ★ `performLayout` **会调两次**（一次自然尺寸、一次被夹紧后的最终尺寸）——
 *   这是有意的：文本断行依赖可用宽度，宽度变了断行就变，所以最终度量必须用最终
 *   宽度重算。`TextMetricsProvider` 自己有缓存（键含 maxWidth），第二次几乎免费。
 *
 * @api
 */
final class RenderText extends RenderBox
{
    private ?Paragraph $paragraph = null;

    /** 复用缓冲（§7.2 裁决 2）：不每帧 new */
    private ?DisplayList $list = null;

    public function __construct(
        private readonly string $content,
        private readonly TextStyle $style,
        private readonly Color $color,
        private readonly TextMetricsProvider $metrics,
        private readonly Scale $scale = new Scale(1.0),
    ) {
    }

    /** @internal 冒烟 / 调试：解析后的字号（角色 → 主题表 → 这里） */
    public function styleSize(): float
    {
        return $this->style->size;
    }

    /** @internal 冒烟 / 调试：maxLines 真的限住了几行 */
    public function lineCount(): int
    {
        return $this->paragraph?->lineCount() ?? 0;
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $maxWidth = $constraints->hasBoundedWidth() ? $constraints->maxWidth : INF;

        $this->paragraph = $this->metrics->layout($this->content, $this->style, $maxWidth, $this->scale);

        return new Size($this->paragraph->width, $this->paragraph->height);
    }

    public function intrinsicWidth(float $height): float
    {
        return $this->metrics->advance($this->content, $this->style, $this->scale);
    }

    public function intrinsicHeight(float $width): float
    {
        $paragraph = $this->metrics->layout(
            $this->content,
            $this->style,
            is_infinite($width) ? INF : $width,
            $this->scale,
        );

        return $paragraph->height;
    }

    public function visitChildren(callable $visitor): void
    {
    }

    public function paint(Canvas $canvas): void
    {
        if ($this->paragraph === null || $this->paragraph->lineCount() === 0) {
            return;
        }

        // ★ 缓冲复用（§7.2 裁决 2：每帧零分配）—— 每次 new 一个 DisplayList
        //   等于每帧给每个 Text 分配一条命令缓冲，录制侧的"零分配"就白做了。
        $this->list ??= new DisplayList();
        $this->list->reset();

        (new RecordingCanvas($this->list))->drawParagraph(
            $this->paragraph,
            Paint::fill($this->color),
            0.0,
            0.0,
        );
        $this->list->replay($canvas);
    }
}
