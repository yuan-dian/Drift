<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\DisplayList;
use Drift\Rendering\Paint;
use Drift\Rendering\RecordingCanvas;
use Drift\Text\Paragraph;
use Drift\Text\TextMetricsProvider;
use Drift\Text\TextStyle;

/**
 * 单行输入框的盒（§15.2 / §A.2.9）。
 *
 * ★ **只吃解析好的值**（盒样式 / 文本色 / 内边距 / 插入符全由构造参数给）——
 *   它在 `Drift\Layout`，而 §4.3 不许 Layout 依赖 Theme。"主题 token → 值"的解析
 *   发生在 widget 层（`TextFieldBox::createRenderObject()` 经 `BuildContext`），
 *   所以合并仍然只发生一次（§15.7 纪律 3），只是落点在能碰主题的那一层。
 *
 * ★ **单行**：`style` 必须已经 `maxLines(1)`（由 widget 层保证）。所以插入符 x 用
 *   `advance(前缀)` 直接量得，与排版结果一致 —— 不必反查段落里的行。多行输入
 *   属 M3（要 `TextInputClient` 的滚动与组合区间一起做）。
 *
 * ★ **插入符是"编辑中"的渲染态**（§9.1：焦点是渲染态，不放业务值）：`caretOffset`
 *   为 `null` = 不在编辑 ⇒ 不画插入符。谁把它设成非 null 是 `TextFieldState` 的事
 *   （M3 由 `FocusNode` 驱动，见 `TextFieldState::beginEditing()`）。
 *
 * ★ `caretRect()` 给的是**盒内坐标**（含内边距）。IME 要的是**窗口坐标**，那需要
 *   沿父链累积平移 —— 属平台层（§8.4 的 `TextInputClient::caretRect()`）。
 *   这里只提供几何，不假装知道自己在屏幕上的位置。
 *
 * @api
 */
final class RenderTextField extends RenderBox
{
    private ?Paragraph $paragraph = null;

    /** 复用缓冲（§7.2 裁决 2）：不每帧 new */
    private ?DisplayList $list = null;

    public function __construct(
        private readonly string $text,
        private readonly TextStyle $style,
        private readonly Color $textColor,
        private readonly string $placeholder,
        private readonly Color $placeholderColor,
        private readonly BoxStyle $box,
        private readonly EdgeInsets $insets,
        private readonly ?int $caretOffset,
        private readonly Color $caretColor,
        private readonly float $caretWidth,
        private readonly TextMetricsProvider $metrics,
        private readonly Scale $scale = new Scale(1.0),
    ) {
    }

    /**
     * C5 溢出策略：**显式声明 `Clip`**。
     *
     * ★ 基类默认也是 `Clip`，这里覆写是为了**把决定写下来**：单行字段在文本长过
     *   可用宽时"必须选择一种舍弃方式"，而当前选的是裁剪（横向滚动属 M3）。
     *   会超的容器必须显式声明（§13.1 C5）—— 默认值不许替它决定。
     */
    public function overflow(): Overflow
    {
        return Overflow::Clip;
    }

    /** 解析后的盒样式（`@internal` 冒烟 / 调试：断言"明暗换肤改表不改控件"） */
    public function boxStyle(): BoxStyle
    {
        return $this->box;
    }

    /** 单行行高：由样式推出，与文本内容无关 ⇒ 空字段也有高度（不会塌成 0） */
    private function lineHeight(): float
    {
        return $this->style->size * $this->style->lineHeight;
    }

    /** 实际显示的文本（空值 ⇒ 显示占位符） */
    private function shownText(): string
    {
        return $this->text === '' ? $this->placeholder : $this->text;
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $inner = $constraints->deflate($this->insets);
        $maxWidth = $inner->hasBoundedWidth() ? $inner->maxWidth : INF;

        $this->paragraph = $this->metrics->layout($this->shownText(), $this->style, $maxWidth, $this->scale);

        // ★ 宽取"内容宽"而不是"可用宽"：字段在 Row 里要按内容宽参与分配，
        //   想要撑满的调用方用 Expanded 包（与 Button 的形态一致）。
        $contentWidth = $this->paragraph->width;
        if ($contentWidth > $maxWidth) {
            $contentWidth = $maxWidth;   // 超了 ⇒ 由 overflow() 的 Clip 裁掉
        }

        return $constraints->constrain($this->insets->inflate(new Size(
            $contentWidth,
            $this->lineHeight(),
        )));
    }

    public function intrinsicWidth(float $height): float
    {
        return $this->insets->horizontal()
            + $this->metrics->advance($this->shownText(), $this->style, $this->scale);
    }

    public function intrinsicHeight(float $width): float
    {
        return $this->insets->vertical() + $this->lineHeight();
    }

    public function visitChildren(callable $visitor): void
    {
    }

    /**
     * 插入符矩形（**盒内坐标**，已含内边距）。
     *
     * ★ `caretOffset` 为 `null`（不在编辑）时给"文本末尾"的位置 —— 那是下一个插入
     *   点会落的地方，不是随便一个值。
     */
    public function caretRect(): Rect
    {
        $offset = $this->caretOffset ?? strlen($this->text);
        if ($offset < 0) {
            $offset = 0;
        }
        if ($offset > strlen($this->text)) {
            $offset = strlen($this->text);
        }

        return new Rect(
            $this->insets->left + $this->metrics->advance(substr($this->text, 0, $offset), $this->style, $this->scale),
            $this->insets->top,
            $this->caretWidth,
            $this->lineHeight(),
        );
    }

    /** @internal 冒烟 / 调试：解析后的字号（主题 → 这里） */
    public function styleSize(): float
    {
        return $this->style->size;
    }

    /** @internal 冒烟 / 调试：当前显示的是占位符还是真实文本 */
    public function isShowingPlaceholder(): bool
    {
        return $this->text === '' && $this->placeholder !== '';
    }

    public function paint(Canvas $canvas): void
    {
        $size = $this->size ?? new Size(0.0, 0.0);
        $rect = new Rect(0.0, 0.0, $size->width, $size->height);

        if ($this->box->background !== null) {
            $canvas->drawRect($rect, Paint::fill($this->box->background), $this->box->radius);
        }
        if ($this->box->borderColor !== null && $this->box->borderWidth > 0.0) {
            $canvas->drawRect($rect, Paint::stroke($this->box->borderColor, $this->box->borderWidth), $this->box->radius);
        }

        // ★ overflow() 声明的 Clip 在这里兑现：文本长过盒宽时不许画到盒外去。
        $canvas->pushClip($rect);
        $canvas->save();
        $canvas->translate($this->insets->left, $this->insets->top);

        if ($this->paragraph !== null && $this->paragraph->lineCount() > 0) {
            $color = $this->text === '' ? $this->placeholderColor : $this->textColor;

            $this->list ??= new DisplayList();
            $this->list->reset();
            (new RecordingCanvas($this->list))->drawParagraph($this->paragraph, Paint::fill($color), 0.0, 0.0);
            $this->list->replay($canvas);
        }

        if ($this->caretOffset !== null) {
            $caret = $this->caretRect();
            $canvas->drawRect(
                new Rect(
                    $caret->left - $this->insets->left,
                    $caret->top - $this->insets->top,
                    $caret->width,
                    $caret->height,
                ),
                Paint::fill($this->caretColor),
            );
        }

        $canvas->restore();
        $canvas->popClip();
    }
}
