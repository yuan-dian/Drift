<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;
use Drift\Text\Paragraph;
use Drift\Text\TextMetricsProvider;
use Drift\Text\TextStyle;

/**
 * 文本盒（§13.1 / §8）。
 *
 * ★ **问度量，不问平台**：`TextMetricsProvider` 在造盒时注入（§14.2），所以同一份
 *   Widget 树在测试里喂固定度量 ⇒ 布局可复现、golden 可比对。
 *
 * ★ 断行依赖可用宽度 ⇒ `performLayout()` 按约束的 maxWidth 度量一次并留下 paragraph；
 *   `contentIntrinsicHeight()` 是父盒问固有高度的另一条路径，按传入宽度再度量一次。
 *   度量器自带以 maxWidth 为键的缓存，两条路径不会重复算。
 *
 * @api
 */
final class RenderText extends RenderBox
{
    private ?Paragraph $paragraph = null;

    /**
     * `color` 是构造注入的 readonly ⇒ 一支 Paint 跟着盒活，跨帧复用。
     *
     * ★ 早先这里缓存的是一个 `DisplayList`：每帧 reset → 录 1 条 `drawParagraph`
     *   → replay 回真画布 —— 纯绕路（`analysis/17` §5.1 点名过）。录制没有缓存语义，
     *   只是把"直接画"变成"记下来再画一遍"。
     */
    private ?Paint $paint = null;

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

    protected function contentIntrinsicWidth(float $height): float
    {
        return $this->metrics->advance($this->content, $this->style, $this->scale);
    }

    protected function contentIntrinsicHeight(float $width): float
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

    protected function paintContent(Canvas $canvas): void
    {
        if ($this->paragraph === null || $this->paragraph->lineCount() === 0) {
            return;
        }

        $canvas->drawParagraph($this->paragraph, $this->paint ??= Paint::fill($this->color), 0.0, 0.0);
    }
}
