<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Rendering\BoxChrome;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;
use Drift\Text\Paragraph;
use Drift\Text\TextMetricsProvider;
use Drift\Text\Utf8;
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
 * ★ **选区**是「字节区间 → 两个 x」（`advanceAt()` 同一个换算给插入符与选区共用）：
 *   `selectionStart` / `selectionEnd` 相等即无选区，`paint()` 直接跳过。反查
 *   `offsetAt(float $x)` 把盒内 x 翻译回字节偏移（吸附到最近的字符边界）——
 *   指针拖动框选靠它把"拖到哪儿"变成"选到哪个字符"。
 *
 * @api
 */
final class RenderTextField extends RenderBox implements TextInputBox
{
    /** 遮蔽字符：固定宽度，每个原文码位一个（U+2022，UTF-8 三字节） */
    private const MASK_CHAR = "\xE2\x80\xA2";

    private ?Paragraph $paragraph = null;

    /**
     * 早先是 `DisplayList` 缓冲（每帧 reset → 录 1 条 → replay，纯绕路）。
     * 现在直接画；颜色在 text/placeholder 间切换，故不为 Paint 建缓存。
     */

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
        private readonly Scale $scale = new Scale(1.0),
        private readonly bool $obscure = false,
    ) {
    }

    /**
     * C5 溢出策略：**显式声明 `Clip`**。
     *
     * ★ 基类默认也是 `Clip`，这里覆写是为了**把决定写下来**：单行字段在文本长过
     *   可用宽时"必须选择一种舍弃方式"，而当前选的是裁剪（横向滚动属 M3）。
     *   会超的容器必须显式声明（§13.1 C5）—— 默认值不许替它决定。
     */
    #[\Override]
    public function overflow(): Overflow
    {
        return Overflow::Clip;
    }

    /** 文本框恒裁：文本比盒宽是常态（长文本 + 光标），不裁就会画到相邻控件上 */
    #[\Override]
    public function clipsOverflow(): bool
    {
        return true;
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
        $display = $this->displayText();

        return $display === '' ? $this->placeholder : $display;
    }

    /**
     * 绘制源。遮蔽时换成等码位数的圆点串；`$this->text` 始终是原文，
     * 公开的字节偏移契约（构造参数、offsetAt、caretRect）也始终是原文偏移。
     */
    private function displayText(): string
    {
        if (!$this->obscure || $this->text === '') {
            return $this->text;
        }

        return str_repeat(self::MASK_CHAR, count($this->maskBounds()) - 1);
    }

    /** 原文每个码位的结束字节边界：`[0, b1, …, strlen]` */
    private function maskBounds(): array
    {
        $out = [0];
        foreach (Utf8::decode($this->text) as $glyph) {
            $out[] = $glyph[1] + $glyph[2];
        }

        return $out;
    }

    /** 原文偏移 → 绘制串偏移 */
    private function toDisplayOffset(int $offset): int
    {
        if (!$this->obscure) {
            return $offset;
        }

        $offset = max(0, min($offset, strlen($this->text)));
        $n = 0;
        foreach ($this->maskBounds() as $bound) {
            if ($bound >= $offset) {
                break;
            }
            $n++;
        }

        return $n * strlen(self::MASK_CHAR);
    }

    /** 绘制串偏移 → 原文偏移（吸附到码位边界） */
    private function fromDisplayOffset(int $offset): int
    {
        $bounds = $this->maskBounds();
        $n = intdiv(max(0, $offset), strlen(self::MASK_CHAR));

        return $bounds[min($n, count($bounds) - 1)];
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

    protected function contentIntrinsicWidth(float $height): float
    {
        return $this->insets->horizontal()
            + $this->metrics->advance($this->shownText(), $this->style, $this->scale);
    }

    protected function contentIntrinsicHeight(float $width): float
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

        return new Rect(
            $this->insets->left + $this->advanceAt($offset),
            $this->insets->top,
            $this->caretWidth,
            $this->lineHeight(),
        );
    }

    /**
     * 选区矩形（**盒内坐标**，已含内边距）；无选区 ⇒ `null`。
     *
     * ★ 与 `caretRect()` 共用 `advanceAt()`：插入符右边缘与选区右边缘必须是同一个
     *   换算，否则"选到插入符处"会差出亚像素级的缝。
     */
    public function selectionRect(): ?Rect
    {
        if ($this->selectionEnd <= $this->selectionStart) {
            return null;
        }

        return new Rect(
            $this->insets->left + $this->advanceAt($this->selectionStart),
            $this->insets->top,
            $this->advanceAt($this->selectionEnd) - $this->advanceAt($this->selectionStart),
            $this->lineHeight(),
        );
    }

    /**
     * 盒内 x ⇒ 最近的插入点（字节偏移，恒字符边界）。
     *
     * ★ 判据是"哪个字符的**中点**更近"，与 `moveCaret()` 的吸附同向（不会把
     *   多字节字符切成半个）。指针拖动框选、以及"点一下落插入符"都用它。
     * ★ 换算只在 `offsetAtX()` 里写一次：表格的"双击落插入符"没有盒可问
     *   （编辑器要下一帧才建出来），必须能在**建盒之前**算同一个答案。
     */
    public function offsetAt(float $x): int
    {
        // 遮蔽时按圆点串量 x，再换算回原文偏移：调用方（指针框选 / 表格编辑）
        // 拿到的仍是原文的 UTF-8 字节偏移。
        $display = self::offsetAtX(
            $this->displayText(),
            $this->style,
            $x - $this->insets->left,
            $this->metrics,
            $this->scale,
        );

        return $this->obscure ? $this->fromDisplayOffset($display) : $display;
    }

    /**
     * `TextInputBox`：盒内 `(x, y)` ⇒ 插入点。
     *
     * ★ `y` **被忽略**：单行框只有一个字符间隙序列，点在上边还是下边都是同一个
     *   落点。这不是偷懒 —— 单行输入框里"点空了"没有语义（用户可以点框内任何
     *   高度来定位）。
     */
    #[\Override]
    public function offsetAtPoint(float $x, float $y): int
    {
        return $this->offsetAt($x);
    }

    /**
     * **文本区内** x ⇒ 最近的插入点（字节偏移，恒字符边界）—— `offsetAt()` 的唯一实现。
     *
     * ★ 静态是为了"还没建盒"的调用方（表格双击：指针落在哪一格、插入符该在哪个
     *   字符之间，必须在 `beginEdit()` 那一刻就定下来，而编辑盒要下一帧才存在）。
     *   两条路共用这一个换算 ⇒ 不会出现"点下去落这儿、盒子画在那儿"。
     * ★ `$x <= 0` ⇒ 0（左缘之前一律落到开头）；超出末字 ⇒ `strlen()`。
     */
    public static function offsetAtX(
        string $text,
        TextStyle $style,
        float $x,
        TextMetricsProvider $metrics,
        Scale $scale,
    ): int {
        if ($x <= 0.0) {
            return 0;
        }

        $length = strlen($text);
        $previousOffset = 0;
        $previousX = 0.0;

        foreach (Utf8::decode($text) as $glyph) {
            $bound = $glyph[1] + $glyph[2];
            $boundX = $metrics->advance(substr($text, 0, $bound), $style, $scale);
            if ($x <= ($previousX + $boundX) * 0.5) {
                return $previousOffset;
            }
            $previousOffset = $bound;
            $previousX = $boundX;
        }

        return $length;
    }

    /**
     * 字节偏移 ⇒ **文本区内**的 x（已减去左内边距）。
     *
     * ★ `caretRect()` / `selectionRect()` / `paint()` 共用它 ⇒ 「插入符右边缘」与
     *   「选区右边缘」不可能算出两个值。偏移夹取到 `[0, strlen]`。
     */
    private function advanceAt(int $offset): float
    {
        if ($offset < 0) {
            $offset = 0;
        }
        if ($offset > strlen($this->text)) {
            $offset = strlen($this->text);
        }

        return $this->metrics->advance(
            substr($this->displayText(), 0, $this->toDisplayOffset($offset)),
            $this->style,
            $this->scale,
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

    /** chrome 画在裁剪**之外**：本盒恒裁（`clipsOverflow()`），描边的外半边不能被自己切掉 */
    #[\Override]
    protected function paintOwnChrome(Canvas $canvas): void
    {
        $size = $this->size ?? new Size(0.0, 0.0);
        BoxChrome::paint($canvas, new Rect(0.0, 0.0, $size->width, $size->height), $this->box);
    }

    /** 自持 `BoxStyle`；`mods` 那份是 0（§49）。 */
    #[\Override]
    public function outlineRadius(): float
    {
        return $this->box->radius;
    }

    /** 输入井的底与 `Hover` token 太近 ⇒ 反馈落在边框上（描边，不填满整个盒）。 */
    #[\Override]
    protected function paintInteraction(Canvas $canvas, ?Size $size): void
    {
        if ($size === null) {
            return;
        }

        $this->paintStateLayer(
            $canvas,
            new Rect(0.0, 0.0, $size->width, $size->height),
            $this->outlineRadius(),
            $this->box->borderWidth,
        );
    }

    protected function paintContent(Canvas $canvas): void
    {
        // ★ 裁剪由基类 `RenderBox::paint()` 统一兑现（`clipsOverflow()` 恒真）；
        //   chrome 已搬进 `paintOwnChrome()`（在裁剪之外），这里只剩内容。
        $canvas->save();
        $canvas->translate($this->insets->left, $this->insets->top);

        if ($this->paragraph !== null && $this->paragraph->lineCount() > 0) {
            $color = $this->text === '' ? $this->placeholderColor : $this->textColor;

            $canvas->drawParagraph($this->paragraph, Paint::fill($color), 0.0, 0.0);
        }

        // 选区画在文本**下面**：先铺色，再落字 —— 高亮是背景，不是前景
        // （否则选中的字符会被同色墨盖掉，变成"看得见选了一块、看不出选了什么"）。
        $selection = $this->selectionRect();
        if ($selection !== null) {
            $canvas->drawRect(
                new Rect(
                    $selection->left - $this->insets->left,
                    $selection->top - $this->insets->top,
                    $selection->width,
                    $selection->height,
                ),
                Paint::fill($this->selectionColor),
            );
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
    }
}
