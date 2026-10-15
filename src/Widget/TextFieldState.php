<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\Rect;
use Drift\Layout\RenderTextField;
use Drift\Text\Utf8;

/**
 * `TextField` 的纯 UI 态（§11.5 / §12.3）。
 *
 * ★ **为什么编辑缓冲在 `State` 而不是 Store**：§11.5 的判据是"必须跨 build 活、
 *   且只影响这一棵子树"—— 编辑中的草稿与插入符位置正是这一类（§12.3 的
 *   "选中 / 焦点 / **编辑中** / 滚动"）。落库是 `onChanged` 之后调用方的事。
 *
 * ★ **插入符偏移是字节偏移，且永远落在字符边界上**。`Utf8::decode()` 给出每个
 *   码位的 (字节偏移, 字节长度)，所有移动 / 删除都按它走 —— 于是不会把多字节
 *   字符切成半个（切了之后喂给排版器会得到非法 UTF-8）。
 *
 * ★ **编辑态与焦点**：`beginEditing()` / `endEditing()` 是**渲染态开关**（§9.1：
 *   "焦点是渲染态，不放业务值"），它决定插入符画不画。今天没有输入层，所以由
 *   调用方显式驱动；M3 的 `FocusNode` 会在这两个方法上接。`FocusNode` 到位之前，
 *   本类就是"输入层最小集"的 widget 侧接口。
 *
 * @api
 */
final class TextFieldState extends State
{
    /** @internal 当前文本（受控：外部推新值经 `didUpdateWidget()` 采纳） */
    private string $buffer = '';

    /** @internal 插入符字节偏移；恒为字符边界 */
    private int $caret = 0;

    /** @internal 渲染态：在编辑中 ⇒ 画插入符 */
    private bool $editing = false;

    public function initState(): void
    {
        // 初值来自 widget 的 value()（§A.2.9 的受控形态：值由外部给）
        $this->buffer = $this->field()->valueText();
        $this->caret = strlen($this->buffer);
    }

    /**
     * 外部推了新值 ⇒ 采纳它，并把插入符移到末尾。
     *
     * ★ **同值不动**：否则调用方每帧重传同一个值都会把插入符打回末尾，
     *   用户正在中间插入时就"跟手输入不丢"这条承诺直接失效。
     */
    public function didUpdateWidget(Widget $widget): void
    {
        parent::didUpdateWidget($widget);

        if (!$widget instanceof TextField) {
            return;
        }

        $incoming = $widget->valueText();
        if ($incoming === $this->buffer) {
            return;
        }

        $this->buffer = $incoming;
        $this->caret = strlen($incoming);
    }

    /** 当前文本（**已包含未提交的编辑**，与 `TextField::valueText()` 可能不同） */
    public function text(): string
    {
        return $this->buffer;
    }

    public function caretOffset(): int
    {
        return $this->caret;
    }

    public function isEditing(): bool
    {
        return $this->editing;
    }

    /** 进入编辑态 ⇒ 插入符出现（M3 由 `FocusNode` 驱动） */
    public function beginEditing(): void
    {
        if ($this->editing) {
            return;
        }
        $this->editing = true;
        $this->markDirty();
    }

    /** 退出编辑态 ⇒ 插入符消失；文本保留（不回滚 —— 回滚是调用方的事） */
    public function endEditing(): void
    {
        if (!$this->editing) {
            return;
        }
        $this->editing = false;
        $this->markDirty();
    }

    /**
     * 在插入符处插入文本（IME 提交 / 直接键入的落点，§8.4）。
     *
     * 插入符移到插入内容的末尾。
     */
    public function insert(string $text): void
    {
        if ($text === '') {
            return;
        }

        $this->apply(
            substr($this->buffer, 0, $this->caret) . $text . substr($this->buffer, $this->caret),
            $this->caret + strlen($text),
        );
    }

    /** 退格：删掉插入符**前一个字符**（按码位，不是按字节） */
    public function deleteBackward(): void
    {
        $bounds = $this->boundaries();
        $previous = 0;
        foreach ($bounds as $bound) {
            if ($bound >= $this->caret) {
                break;
            }
            $previous = $bound;
        }

        if ($previous === $this->caret) {
            return; // 已在最前 ⇒ 无字符可删
        }

        $this->apply(
            substr($this->buffer, 0, $previous) . substr($this->buffer, $this->caret),
            $previous,
        );
    }

    /** Delete：删掉插入符**后一个字符** */
    public function deleteForward(): void
    {
        if ($this->caret >= strlen($this->buffer)) {
            return; // 已在最后 ⇒ 无字符可删
        }

        $next = strlen($this->buffer);
        foreach ($this->boundaries() as $bound) {
            if ($bound > $this->caret) {
                $next = $bound;
                break;
            }
        }

        $this->apply(
            substr($this->buffer, 0, $this->caret) . substr($this->buffer, $next),
            $this->caret,
        );
    }

    /**
     * 移动插入符（**按字节偏移**，会吸附到不大于它的最近字符边界）。
     *
     * 落点即字符内部 ⇒ 吸附而不是报错：输入层给的是"点到了哪个像素"，
     * 换算成字节偏移时天然可能落在字符中间。
     */
    public function moveCaret(int $offset): void
    {
        if ($offset < 0) {
            $offset = 0;
        }
        if ($offset > strlen($this->buffer)) {
            $offset = strlen($this->buffer);
        }

        $snapped = 0;
        foreach ($this->boundaries() as $bound) {
            if ($bound > $offset) {
                break;
            }
            $snapped = $bound;
        }

        if ($snapped === $this->caret) {
            return;
        }

        $this->caret = $snapped;
        $this->markDirty();
    }

    public function moveCaretToEnd(): void
    {
        $this->moveCaret(strlen($this->buffer));
    }

    /**
     * 插入符矩形（**盒内坐标**）。
     *
     * ★ 这是 IME 定位候选窗的几何来源（§8.4：`caretRect()` 不是可选项，是 IME
     *   能用的前提）。窗口坐标要沿父链累积平移，属平台层 —— 这里只给几何，
     *   不假装知道自己在屏幕上的位置。
     *
     * ★ **它读的是"最近一次 build 出来的盒"**：编辑方法只标脏，重建发生在帧末
     *   （§5.2 裁决 3）。所以连续 `moveCaret()` 之后不 settle 就读，会拿到上一帧
     *   的几何 —— 平台侧应当在**帧时**读（那时盒已同步），别在事件处理中途读。
     *
     * @return Rect|null `null` = 还没挂载出盒，或本字段没有渲染盒
     */
    public function caretRect(): ?Rect
    {
        $box = $this->element()?->effectiveBox();

        return $box instanceof RenderTextField ? $box->caretRect() : null;
    }

    public function build(BuildContext $ctx): Widget
    {
        $field = $this->field();

        $box = TextFieldBox::make(
            $this->buffer,
            $field->placeholderText(),
            $this->editing ? $this->caret : null,
        );

        $label = $field->labelText();
        if ($label === null) {
            return $box;
        }

        // 标签在字段上方：`Column` 负责间距，`Text` 负责排版 —— 字段盒不必
        // 知道"标签"这件事（它只管"一个输入井"）。
        return Column::make()->spacing(4.0)->children([
            Text::make($label)->caption(),
            $box,
        ]);
    }

    /**
     * 改缓冲 + 通知外部（§11.3.1）。
     *
     * ★ **无变化就不通知、不重建** —— 发假通知会让调用方以为用户改了东西
     *   （G2：宁可少报，不可错报）。
     */
    private function apply(string $nextText, int $nextCaret): void
    {
        if ($nextText === $this->buffer) {
            return;
        }

        $this->buffer = $nextText;
        $this->caret = $nextCaret;

        $handler = $this->field()->changedHandler();
        if ($handler !== null) {
            $handler($nextText);
        }

        $this->markDirty();   // 值已改完，只标脏（与 Cell::setIfChanged 同路）
    }

    /** @return list<int> 所有合法插入点的字节偏移（含 0 与末尾），升序 */
    private function boundaries(): array
    {
        $bounds = [0];
        foreach (Utf8::decode($this->buffer) as $glyph) {
            $bounds[] = $glyph[1] + $glyph[2];
        }

        return $bounds;
    }

    private function field(): TextField
    {
        $widget = $this->widget();
        if (!$widget instanceof TextField) {
            // 框架不变量：StatefulElement 按 get_class 配对，配错不可能发生
            Errors::report('TextFieldState 只能配 TextField（框架内部不变量被破坏）');
        }

        /** @var TextField $widget */
        return $widget;
    }
}
