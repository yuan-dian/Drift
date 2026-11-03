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
use Drift\Rendering\Paint;
use Drift\Text\Paragraph;
use Drift\Text\TextMetricsProvider;
use Drift\Text\TextStyle;

/**
 * 多行输入框的盒（§15.2 的单行版是 `RenderTextField`）。
 *
 * ★ **为什么不复用 `RenderTextField`**：那一份的几何前提是"单行"——
 *   插入符 x 用 `advance(前缀)` 直接量得（所以快、且与排版必然一致），
 *   选区是一个矩形，高度恒等于一行。多行要的是"行内累加 + 行号 + 垂直偏移 +
 *   每行一段选区"，四条全不同。塞进一个类会让单行路径也背上分支，
 *   而单行是**绝大多数**输入框 —— 没有收益的退化不做。
 *
 * ★ **垂直滚动**：偏移是 readonly 构造参数（盒每帧重造，与 `RenderScrollView`
 *   同形）；夹取在 `appliedOffset()` 一处，上界 `maxScroll` 由本次布局算
 *   （内容多长只有布局后才知道）。State 只夹下界 0。
 *
 * ★ **不画滚动条**：多行输入框的滚动条在本仓尚无设计（`ScrollBarHost` 是
 *   `ScrollView` 的东西）。插入符跟随（`revealCaret` 在 State 侧）已经保证
 *   "打字时看得见"，滚动条是锦上添花 —— 不假装有。
 *
 * @api
 */
final class RenderTextArea extends RenderBox implements TextInputBox
{
    private ?Paragraph $paragraph = null;

    /** 本次布局算出的最大可滚距离（`0` = 内容装得下） */
    private float $maxScroll = 0.0;

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
        private readonly Color $selectionColor,
        private readonly int $selectionStart,
        private readonly int $selectionEnd,
        private readonly TextMetricsProvider $metrics,
        /** 至少这么高（`minLines` 折算成像素）—— 空文本的框不该塌成一行以下 */
        private readonly float $minHeight = 0.0,
        /** 已夹下界、**未夹上界**的滚动偏移（上界见 `appliedOffset()`） */
        private float $scrollOffset = 0.0,
        private readonly Scale $scale = new Scale(1.0),
    ) {
    }

    /**
     * 就地改滚动偏移，返回**夹取后**的值（与 `RenderScrollView::setScrollOffset()`
     * 同形）。
     *
     * ★ 为什么不在 Widget 层改：偏移一变就重建 Widget 树的话，滚动帧要跑一遍
     *   调和（`ScrollViewState` 2026-10 实测 11.68ms → 7.21ms 就是这条）。
     *   这里只改一个字段 + 标重排，树一动不动。
     */
    public function setScrollOffset(float $value): float
    {
        $this->scrollOffset = $value;

        if (!$this->hasSize()) {
            return $value;
        }

        $this->markPaintChanged();

        return $this->appliedOffset();
    }

    /** 文本框恒裁：内容比视口高是常态（否则会画到相邻控件上） */
    #[\Override]
    public function overflow(): Overflow
    {
        return Overflow::Clip;
    }

    #[\Override]
    public function clipsOverflow(): bool
    {
        return true;
    }

    /** 实际生效的垂直偏移（已按 `maxScroll` 夹取） */
    public function appliedOffset(): float
    {
        return min(max(0.0, $this->scrollOffset), $this->maxScroll);
    }

    /** 内容总高（含内边距，至少 `minHeight`）—— State 用它做插入符跟随 */
    public function contentHeight(): float
    {
        $paragraph = $this->paragraph?->height ?? $this->lineHeight();

        return $this->insets->vertical() + max($paragraph, $this->minHeight);
    }

    /** 需要多滚才能看到底（`0` = 装得下） */
    public function maxScroll(): float
    {
        return $this->maxScroll;
    }

    /**
     * 可视区高度（**扣掉内边距**；未布局 ⇒ `0`）。
     *
     * ★ 必须公开：`size` 是 protected，State 侧算"插入符越界了吗"要它。
     *   返回扣过内边距的净高度，因为 `caretRect()` 的 y 里那段内边距已经算进去了。
     */
    public function viewportHeight(): float
    {
        if (!$this->hasSize()) {
            return 0.0;
        }

        return max(0.0, ($this->size?->height ?? 0.0) - $this->insets->vertical());
    }

    /** 内容区上边缘（= 上内边距）—— 插入符 / 选区矩形的 y 都从这里往下算 */
    public function contentTop(): float
    {
        return $this->insets->top;
    }

    /** 单行行高：由样式推出，与文本内容无关 */
    private function lineHeight(): float
    {
        return $this->style->size * $this->style->lineHeight;
    }

    private function shownText(): string
    {
        return $this->text === '' ? $this->placeholder : $this->text;
    }

    /** 段落（布局后才有）；State 要用它做行级移动 */
    public function textParagraph(): ?Paragraph
    {
        return $this->paragraph;
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $inner = $constraints->deflate($this->insets);
        $maxWidth = $inner->hasBoundedWidth() ? $inner->maxWidth : INF;

        $this->paragraph = $this->metrics->layout($this->shownText(), $this->style, $maxWidth, $this->scale);

        // ★ 多行是**块级**：有界宽就撑满（与单行"按内容宽参与 Row 分配"相反）。
        //   无界时才退回内容宽 —— 否则一个空的多行框会在 `Row` 里塌成 0 宽。
        $width = $inner->hasBoundedWidth() ? $inner->maxWidth : $this->paragraph->width;

        // ★ 自然高是**段落高**（多行的内容有多长就是多长），不是行高。
        //   `minHeight` 只是"空文本时的下限"，两者取大 —— 写反了的现象是
        //   "多行框永远只有 minLines 高，多出来的部分被裁掉且滚不动"。
        $natural = $this->insets->vertical() + max($this->paragraph->height, $this->minHeight);
        $height = $constraints->constrain(new Size($width, $natural))->height;

        // 内容装不下 ⇒ 可滚；视口高扣掉内边距
        $viewport = max(0.0, $height - $this->insets->vertical());
        $this->maxScroll = max(0.0, $this->paragraph->height - $viewport);

        return $constraints->constrain(new Size($width, $height));
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        return $this->insets->horizontal() + $this->metrics->advance($this->shownText(), $this->style, $this->scale);
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        return $this->insets->vertical() + max($this->lineHeight(), $this->minHeight);
    }

    public function visitChildren(callable $visitor): void
    {
    }

    /**
     * 插入符矩形（**盒内坐标**，已含内边距与滚动偏移）。
     *
     * ★ 高度取**整行高**（不是字号）：空行、行首行尾都要有可见的插入符。
     */
    public function caretRect(): Rect
    {
        $offset = $this->caretOffset ?? strlen($this->text);
        $x = $this->paragraph?->caretX($offset) ?? 0.0;
        $line = $this->paragraph?->lineIndexAt($offset) ?? 0;
        $lineRect = $this->paragraph?->lineRect($line) ?? new Rect(0.0, 0.0, 0.0, $this->lineHeight());

        return new Rect(
            $this->insets->left + $x,
            $this->insets->top + $lineRect->top - $this->appliedOffset(),
            $this->caretWidth,
            $lineRect->height > 0.0 ? $lineRect->height : $this->lineHeight(),
        );
    }

    /**
     * 选区矩形（**每行一段**，盒内坐标）；无选区 ⇒ 空数组。
     *
     * ★ 空行段宽度为 0（段落层不知道插入符多粗）⇒ 这里补到 `caretWidth`，
     *   否则"选中一整段含空行"时会少一条细高亮，看起来像漏了。
     *
     * @return list<Rect>
     */
    public function selectionRects(): array
    {
        if ($this->selectionEnd <= $this->selectionStart || $this->paragraph === null) {
            return [];
        }

        $rects = [];
        foreach ($this->paragraph->selectionRects($this->selectionStart, $this->selectionEnd) as $rect) {
            $rects[] = new Rect(
                $this->insets->left + $rect->left,
                $this->insets->top + $rect->top - $this->appliedOffset(),
                max($rect->width, $this->caretWidth),
                $rect->height > 0.0 ? $rect->height : $this->lineHeight(),
            );
        }

        return $rects;
    }

    /**
     * 盒内 `(x, y)` ⇒ 最近的插入点（字节偏移，恒字符边界）。
     *
     * ★ `y` 要**加上滚动偏移**才回到内容坐标 —— 少了这一步的现象是"滚动之后
     *   点哪儿都落错行"。
     */
    public function offsetAt(float $x, float $y): int
    {
        if ($this->paragraph === null) {
            return 0;
        }

        return $this->paragraph->indexAt(
            max(0.0, $x - $this->insets->left),
            $y - $this->insets->top + $this->appliedOffset(),
        );
    }

    /** `TextInputBox`：与 `offsetAt()` 同一份换算（多行**不能**忽略 `y`） */
    #[\Override]
    public function offsetAtPoint(float $x, float $y): int
    {
        return $this->offsetAt($x, $y);
    }

    /** @internal 冒烟 / 调试：解析后的盒样式 */
    public function boxStyle(): BoxStyle
    {
        return $this->box;
    }

    /** chrome 画在裁剪**之外**：本盒恒裁，描边的外半边不能被自己切掉 */
    #[\Override]
    protected function paintOwnChrome(Canvas $canvas): void
    {
        $size = $this->size ?? new Size(0.0, 0.0);
        $rect = new Rect(0.0, 0.0, $size->width, $size->height);

        $backgroundPaint = $this->box->backgroundPaint($size->width, $size->height);
        if ($backgroundPaint !== null) {
            $canvas->drawRect($rect, $backgroundPaint, $this->box->radius);
        }
        if ($this->box->borderColor !== null && $this->box->borderWidth > 0.0) {
            $canvas->drawRect($rect, Paint::stroke($this->box->borderColor, $this->box->borderWidth), $this->box->radius);
        }
    }

    protected function paintContent(Canvas $canvas): void
    {
        // 只挪滚动偏移：`caretRect()` / `selectionRects()` 已经是盒内坐标，
        // 这样三处（几何 / 命中 / 绘制）用同一套坐标，不必来回换。
        $canvas->save();
        $canvas->translate(0.0, -$this->appliedOffset());

        // ★ **选区在文本之前**：高亮是背景，不是前景。反过来的现象是"选中了
        //   一块、看不出选了什么"（选区的混色不透明，会盖住字形）。
        foreach ($this->selectionRects() as $selection) {
            $canvas->drawRect($selection, Paint::fill($this->selectionColor));
        }

        if ($this->paragraph !== null && $this->paragraph->lineCount() > 0) {
            $color = $this->text === '' ? $this->placeholderColor : $this->textColor;
            $canvas->drawParagraph(
                $this->paragraph,
                Paint::fill($color),
                $this->insets->left,
                $this->insets->top,
            );
        }

        if ($this->caretOffset !== null) {
            $canvas->drawRect($this->caretRect(), Paint::fill($this->caretColor));
        }

        $canvas->restore();
    }
}
