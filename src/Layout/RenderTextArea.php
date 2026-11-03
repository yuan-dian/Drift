<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\Axis;
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
 * ★ **滚动条**（2026-11-03 补）：本盒实现了 `ScrollBarHost`，与 `RenderScrollView` /
 *   `RenderTable` **同一个接口**（所以 `ScrollInputRouter` 的 thumb 拖动、
 *   `ScrollBarFade` 的淡出都不必认识"多行输入"）。
 *   ★ 条栏宽**恒定预留**（`ScrollBarGeometry::THICKNESS + MARGIN`，从文本可用宽度
 *   里扣）：条是"溢出才画"的，而折行宽度在布局期就要定 —— 按"这一帧溢出了吗"
 *   决定扣不扣是**循环依赖**，会在临界点抖一帧。浏览器 `<textarea>` / Win32
 *   `EDIT` / Qt `QPlainTextEdit` 都是常驻占位，同一裁决。
 *
 * @api
 */
final class RenderTextArea extends RenderBox implements TextInputBox, ScrollBarHost
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
        /**
         * **固定框高**（`TextArea::lines()` 折算成像素，含内边距）；`0` = 按内容长。
         *
         * ★ 它同时是上限与下限：给定 5 行就是 5 行高，内容更短也不缩 ——
         *   那是 `<textarea rows="5">` / `QPlainTextEdit` 的语义（框高是布局决定，
         *   不是内容决定）。没有它，多行框在任何表单里都只能被内容撑开，
         *   也就永远滚不起来（滚动条等于没有消费者）。
         */
        private readonly float $fixedHeight = 0.0,
        /** 已夹下界、**未夹上界**的滚动偏移（上界见 `appliedOffset()`） */
        private float $scrollOffset = 0.0,
        /** 滚动条色（主题 `Muted`，与 `ScrollView` 同一个 token） */
        private readonly Color $thumb = new Color(0, 0, 0, 0),
        /** 滚动条透明度（`ScrollBarFade` 每帧推；**不是** readonly） */
        private float $barOpacity = 1.0,
        /**
         * 这一帧要把哪个偏移处的插入符滚进视口；`null` = 不跟随。
         *
         * ★★ 为什么是**构造参数**而不是让 State 在 build 里改偏移（2026-11-03）：
         *   偏移的真相在 `ScrollLane`（State），但**段落与视口高是盒在 layout 里
         *   才有的**。State 在 build 期拿到的盒是上一帧的 —— 它的段落是按**上一帧
         *   文本**排的，拿新插入符去问行位置会差一行（实测 16.8px：光标正好贴在
         *   视口上沿之外）。让盒在自己 layout 完、段落与 `maxScroll` 都是本帧值的
         *   时候对准，就没有"哪一帧的几何"这个问题了。
         * ★ 传 `null` 是"用户在滚，别拉回来"—— 这个决定只能由 State 做（它知道
         *   插入符变没变），所以**决定权在 build、执行权在 layout**。
         */
        private readonly ?int $revealCaret = null,
        private readonly Scale $scale = new Scale(1.0),
    ) {
    }

    /**
     * 就地改滚动偏移，返回**夹取后**的值（`ScrollBarHost` 的接口方法）。
     *
     * ★ 为什么不在 Widget 层改：偏移一变就重建 Widget 树的话，滚动帧要跑一遍
     *   调和（`ScrollViewState` 2026-10 实测 11.68ms → 7.21ms 就是这条）。
     *   这里只改一个字段 + 标重绘，树一动不动。
     * ★ 认不得的轴返回 `0.0`（接口纪律，同 `RenderScrollView`）：调用方按
     *   `scrollAxes()` 循环，不该先 `if` 再问。
     */
    public function setScrollOffset(Axis $axis, float $value): float
    {
        if ($axis !== Axis::Vertical) {
            return 0.0;
        }

        $this->scrollOffset = $value;

        if (!$this->hasSize()) {
            return $value;
        }

        $this->markPaintChanged();

        return $this->applied();
    }

    /** @internal `ScrollBarFade` 每帧把值推过来（不重建、不重排；**但必须标重绘**） */
    public function setBarOpacity(float $value): void
    {
        if ($this->barOpacity === $value) {
            return;
        }

        $this->barOpacity = $value;
        $this->markPaintChanged();
    }

    /** @internal 见 `ScrollBarHost` —— 多行输入只有竖向 */
    public function scrollAxes(): array
    {
        return [Axis::Vertical];
    }

    /**
     * thumb 矩形（**盒内**坐标）；`null` = 内容不溢出 / 还没布局。
     *
     * ★ 几何只算一处（`ScrollBarGeometry`）：绘制与拖动命中都从这里出发。
     */
    public function thumbRect(Axis $axis): ?Rect
    {
        $size = $this->size;
        if ($size === null || $axis !== Axis::Vertical) {
            return null;
        }

        return ScrollBarGeometry::thumbRect($size, $this->maxScroll, $this->applied(), $axis);
    }

    /** @internal 事件命中用：thumb 在渲染树根坐标里的矩形（见 `ScrollBarHost`） */
    public function thumbRectInRoot(Axis $axis): ?Rect
    {
        $bar = $this->thumbRect($axis);
        if ($bar === null) {
            return null;
        }

        [$originX, $originY] = RenderBox::absoluteOrigin($this);

        return new Rect($originX + $bar->left, $originY + $bar->top, $bar->width, $bar->height);
    }

    /** thumb 在轨道里可走的距离（拖动映射的分母） */
    public function thumbTravel(Axis $axis): float
    {
        $size = $this->size;
        if ($size === null || $axis !== Axis::Vertical) {
            return 0.0;
        }

        return ScrollBarGeometry::travel(ScrollBarGeometry::extent($size, $axis), $this->maxScroll);
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

    /** 该轴实际生效的偏移（已按 `maxScroll` 夹取） */
    public function appliedOffset(Axis $axis): float
    {
        return $axis === Axis::Vertical ? $this->applied() : 0.0;
    }

    /** 本轴的实际偏移（内部用：绘制 / 命中不必为一个轴去走接口） */
    private function applied(): float
    {
        return min(max(0.0, $this->scrollOffset), $this->maxScroll);
    }

    /** 内容总高（含内边距，至少 `minHeight`）—— State 用它做插入符跟随 */
    public function contentHeight(): float
    {
        $paragraph = $this->paragraph?->height ?? $this->lineHeight();

        return $this->insets->vertical() + max($paragraph, $this->minHeight);
    }

    /** 该轴的可滚动上限（布局后才有意义；`0` = 不滚） */
    public function maxScroll(Axis $axis): float
    {
        return $axis === Axis::Vertical ? $this->maxScroll : 0.0;
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

    /** 内容区左边缘（= 左内边距）—— "文本右缘有没有越过条栏"要拿它换算 */
    public function contentLeft(): float
    {
        return $this->insets->left;
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
        // ★ 条栏宽**常驻预留**（见类注释）：折行宽度在布局期就要定，不能等
        //   "这一帧溢出了"再决定扣不扣 —— 那是循环依赖，临界点上会抖一帧。
        $maxWidth = $inner->hasBoundedWidth()
            ? max(0.0, $inner->maxWidth - ScrollBarGeometry::THICKNESS - ScrollBarGeometry::MARGIN)
            : INF;

        $this->paragraph = $this->metrics->layout($this->shownText(), $this->style, $maxWidth, $this->scale);

        // ★ 多行是**块级**：有界宽就撑满（与单行"按内容宽参与 Row 分配"相反）。
        //   无界时才退回内容宽 —— 否则一个空的多行框会在 `Row` 里塌成 0 宽。
        $width = $inner->hasBoundedWidth() ? $inner->maxWidth : $this->paragraph->width;

        // ★ 自然高是**段落高**（多行的内容有多长就是多长），不是行高。
        //   `minHeight` 只是"空文本时的下限"，两者取大 —— 写反了的现象是
        //   "多行框永远只有 minLines 高，多出来的部分被裁掉且滚不动"。
        $natural = $this->insets->vertical() + max($this->paragraph->height, $this->minHeight);

        // ★ 固定框高（`lines()`）优先：它是布局决定，不随内容变（见构造参数注释）
        $target = $this->fixedHeight > 0.0 ? $this->fixedHeight : $natural;
        $height = $constraints->constrain(new Size($width, $target))->height;

        // 内容装不下 ⇒ 可滚；视口高扣掉内边距
        $viewport = max(0.0, $height - $this->insets->vertical());
        $this->maxScroll = max(0.0, $this->paragraph->height - $viewport);

        // ★★ 插入符跟随**就在这一帧完成**（2026-11-03）：此时段落与 `maxScroll`
        //   都是本帧的值，`applied()` 也已按新上限夹取 ⇒ 算出来的偏移本帧 paint
        //   就用得上。State 那边只需在 build 时回答"要不要跟随、跟到哪个偏移"。
        //   ★ 用局部 `$viewport` 而不是 `viewportHeight()`：后者读 `$this->size`，
        //     而 size 要等本次 `performLayout()` 返回后由 `RenderBox::layout()` 写入
        //     —— 在这里它是**上一帧**的值（首帧还是 `null`）。
        if ($this->revealCaret !== null) {
            $caret = $this->caretRectAt($this->revealCaret);
            $top = $this->insets->top;
            $bottom = $top + $viewport;

            if ($caret->top < $top) {
                $this->scrollOffset = max(0.0, $this->applied() + $caret->top - $top);
            } elseif ($caret->top + $caret->height > $bottom) {
                $this->scrollOffset = $this->applied() + $caret->top + $caret->height - $bottom;
            }
        }

        return $constraints->constrain(new Size($width, $height));
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        return $this->insets->horizontal() + $this->metrics->advance($this->shownText(), $this->style, $this->scale);
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        if ($this->fixedHeight > 0.0) {
            return $this->fixedHeight;
        }

        return $this->insets->vertical() + max($this->lineHeight(), $this->minHeight);
    }

    public function visitChildren(callable $visitor): void
    {
    }

    /**
     * 插入符矩形（**盒内坐标**，已含内边距与滚动偏移）。
     *
     * ★ 高度取**整行高**（不是字号）：空行、行首行尾都要有可见的插入符。
     * ★ 未编辑（`caretOffset === null`）⇒ 落在**文本末**：这是"这里有个编辑位置"
     *   的占位（IME 候选窗要一个几何），不是"插入符在末尾"。要问"当前插入符在
     *   哪儿"请用 `caretRectAt()` —— 两个语义混用会出真 bug，见那个方法的注。
     */
    public function caretRect(): Rect
    {
        return $this->caretRectAt($this->caretOffset ?? strlen($this->text));
    }

    /**
     * **指定偏移**处的插入符矩形（盒内坐标，已含内边距与滚动偏移）。
     *
     * ★★ 为什么必须与 `caretRect()` 分开（2026-11-03，真机症状：点进多行框
     *   视口跳到底部、要等下一次 rebuild 才跳回）：
     *   盒是**上一帧布局的产物**，它的 `caretOffset` 是本帧 build 之前的值。
     *   点进输入框的那一帧，盒还是"未编辑"的那个（`caretOffset === null`），
     *   于是 `caretRect()` 的兜底给出**文本末尾** ⇒ `revealCaret()` 把视口滚到
     *   底，而真实的插入符在用户点的地方（第 3 行）。下一帧盒换了才纠正。
     *   ⇒ 「画/报候选窗」要的是"有没有编辑位置"，「滚动跟随」要的是"插入符在哪"，
     *     两条语义，两个入口。
     * ★ 传 `$offset` 而不是读字段，也让调用方**不必**等盒更新 —— 它自己就有
     *   当前插入符（State 侧）。
     */
    public function caretRectAt(int $offset): Rect
    {
        $x = $this->paragraph?->caretX($offset) ?? 0.0;
        $line = $this->paragraph?->lineIndexAt($offset) ?? 0;
        $lineRect = $this->paragraph?->lineRect($line) ?? new Rect(0.0, 0.0, 0.0, $this->lineHeight());

        return new Rect(
            $this->insets->left + $x,
            $this->insets->top + $lineRect->top - $this->applied(),
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
                $this->insets->top + $rect->top - $this->applied(),
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
            $y - $this->insets->top + $this->applied(),
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

    /** @internal 断言用：当前滚动条透明度 */
    public function barOpacity(): float
    {
        return $this->barOpacity;
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
        $canvas->translate(0.0, -$this->applied());

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

        // ★ 条画在 `restore()` **之后**：它是视口坐标系的东西，跟着内容平移就会
        //   在 offset=max 时整条飞出盒外被裁干净（`RenderScrollView` 2026-10-17
        //   踩过同一条）。
        $this->paintScrollbar($canvas);
    }

    /** 滚动条（几何与绘制都在 `ScrollBarGeometry`，三个滚动盒同一份） */
    private function paintScrollbar(Canvas $canvas): void
    {
        $bar = $this->thumbRect(Axis::Vertical);
        if ($bar === null) {
            return;
        }

        ScrollBarGeometry::paint($canvas, $bar, $this->thumb, $this->barOpacity);
    }
}
