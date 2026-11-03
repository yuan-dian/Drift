<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\Rect;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\TextKeymap;
use Drift\Text\TextChange;
use Drift\Text\TextDraft;

/**
 * **可编辑文本控件**的 State 基类（`TextFieldState` / `TextAreaState`）。
 *
 * ★ 抽出来的理由不是"少写字"，是**别让第二个文本面变成第二套行为**：
 *   焦点进出、`onChanged` 只在文本真变时触发、`Esc` 吃选区、编辑键走
 *   `TextKeymap` —— 这些与"一行还是多行"**无关**，却正是最容易在复制粘贴时
 *   漂掉的东西（现象：多行框里 `Esc` 顺手触发了按钮、或者只挪插入符也发通知）。
 *
 * ★ 子类只需回答三件事，且都不涉及字节数学：
 *   ① 受控值从哪来（`controlledValue()`）
 *   ② 外部推了新值怎么办（`adoptValue()`）
 *   ③ 文本变了通知谁（`notifyChanged()`）
 *   再加 `build()` 与 `caretRect()`（盒不同，几何也不同）。
 *
 * ★ **文本状态机仍不在本类**：缓冲 / 插入符 / 选区 / 撤销栈全在
 *   `Drift\Text\TextDraft`（与表格单元格共用）。本类只是"把按键翻译成编辑动作，
 *   然后通知 + 重画"。
 *
 * @api 实现第三个文本控件（`CodeEditor` 等）时继承它，不要重抄一遍
 */
abstract class EditableTextState extends State implements EditableText
{
    /**
     * 文本状态机（`initState()` 里按受控值建；`didUpdateWidget()` 采纳新值）。
     *
     * ★ 声明成不可空 + 在 `initState()` 里赋值（而不是字段初始化器）：初值来自
     *   Widget，`State` 构造时还拿不到 `widget()`。
     */
    private ?TextDraft $draft = null;

    /** @internal 渲染态：在编辑中 ⇒ 画插入符 */
    private bool $editing = false;

    // ── 子类回答的三个问题 ──────────────────────────────────────────────

    /** 受控值（`Widget::valueText()` 那一层） */
    abstract protected function controlledValue(): string;

    /** Widget 换了 ⇒ 采纳它的新值（同值不动由 `TextDraft::setText()` 保证） */
    abstract protected function adoptValue(Widget $widget): void;

    /** 文本**真变了**才调（只挪插入符不算） */
    abstract protected function notifyChanged(string $text): void;

    // ── 生命周期 ────────────────────────────────────────────────────────

    #[\Override]
    public function initState(): void
    {
        $this->draft = new TextDraft($this->controlledValue());
    }

    #[\Override]
    public function didUpdateWidget(Widget $widget): void
    {
        parent::didUpdateWidget($widget);
        $this->adoptValue($widget);
    }

    /** 状态机本体（子类要读插入符 / 做行级移动时用；**不要**在外面另存一份文本） */
    final protected function draft(): TextDraft
    {
        if ($this->draft === null) {
            // 框架不变量：initState() 先于任何公开方法被调
            $this->draft = new TextDraft($this->controlledValue());
        }

        return $this->draft;
    }

    /** 当前文本（**已包含未提交的编辑**，可能与受控值不同） */
    public function text(): string
    {
        return $this->draft()->text();
    }

    public function caretOffset(): int
    {
        return $this->draft()->caretOffset();
    }

    // ── 编辑态 ──────────────────────────────────────────────────────────

    public function isEditing(): bool
    {
        return $this->editing;
    }

    /**
     * 焦点进出 ⇒ 编辑态。
     *
     * ★ 这条本来是 `FocusManager` 里的 `instanceof TextFieldState` 特例；2026-10-24
     *   迁到 `State::didChangeFocus()`，框架不再认识具体控件。
     */
    public function didChangeFocus(bool $focused): void
    {
        if ($focused) {
            $this->beginEditing();

            return;
        }

        $this->endEditing();
    }

    /** 进入编辑态 ⇒ 插入符出现 */
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
        // ★ 选区是编辑态的一部分 ⇒ **不管当前在不在编辑都收口**。早先的实现在
        //   `!editing` 时早退，于是「不在编辑却有选区」这个状态能活下来
        //   （屏幕上留一片来路不明的蓝，且下次进入编辑时它还在）。
        $hadSelection = $this->draft()->hasSelection();
        $this->draft()->clearSelection();

        if (!$this->editing && !$hadSelection) {
            return;   // 两样都没有 ⇒ 真的无事发生（不标脏）
        }

        $this->editing = false;
        $this->markDirty();
    }

    // ── 编辑动作：全部转给 `TextDraft`，本类只做"通知 + 重画" ──────────────

    /** 在插入符处插入文本（有选区 ⇒ 整段替换，见 `TextDraft::insert()`） */
    public function insert(string $text): void
    {
        $this->settle($this->draft()->insert($text));
    }

    /** 退格：有选区删整段，否则按码位删前一个字符 */
    public function deleteBackward(): void
    {
        $this->settle($this->draft()->deleteBackward());
    }

    /** Delete：有选区删整段，否则按码位删后一个字符 */
    public function deleteForward(): void
    {
        $this->settle($this->draft()->deleteForward());
    }

    /** 移动插入符（字节偏移，吸附到字符边界）；折叠选区 */
    public function moveCaret(int $offset): void
    {
        $this->settle($this->draft()->moveCaret($offset));
    }

    public function moveCaretToEnd(): void
    {
        $this->settle($this->draft()->moveCaretToEnd());
    }

    public function hasSelection(): bool
    {
        return $this->draft()->hasSelection();
    }

    /** 选区起点（两个端里小的那个） */
    public function selectionStart(): int
    {
        return $this->draft()->selectionStart();
    }

    /** 选区终点（两个端里大的那个） */
    public function selectionEnd(): int
    {
        return $this->draft()->selectionEnd();
    }

    /** 选中的文本（无选区 ⇒ 空串） */
    public function selectedText(): string
    {
        return $this->draft()->selectedText();
    }

    /** 全选（`Ctrl+A`）；空文本没有"全选"这回事 */
    public function selectAll(): void
    {
        $this->settle($this->draft()->selectAll());
    }

    /** 折叠选区（两端都归位到插入符）；无选区 ⇒ 无事发生 */
    public function clearSelection(): void
    {
        $this->settle($this->draft()->clearSelection());
    }

    /** 扩展选区到 `$offset`（只挪活动端，锚点不动） */
    public function extendSelectionTo(int $offset): void
    {
        $this->settle($this->draft()->extendSelectionTo($offset));
    }

    /** 选中 `$offset` 所在的词（双击）—— 边界算法在 `TextBoundary`，这里只做通知 + 重画 */
    public function selectWordAt(int $offset): void
    {
        $this->settle($this->draft()->selectWordAt($offset));
    }

    /** 选中 `$offset` 所在的段（三击；单行框里就是整串） */
    public function selectParagraphAt(int $offset): void
    {
        $this->settle($this->draft()->selectParagraphAt($offset));
    }

    /** 用 `$text` 整段替换选区；无选区 ⇒ 退化成在插入符处插入 */
    public function replaceSelection(string $text): void
    {
        $this->settle($this->draft()->replaceSelection($text));
    }

    /** 删掉选区（无选区 ⇒ 无事发生） */
    public function deleteSelection(): void
    {
        $this->settle($this->draft()->deleteSelection());
    }

    // ── 按键 ────────────────────────────────────────────────────────────

    /**
     * 处理一次按键（§9.1 路由链第 ③ 环落到这里）。
     *
     * 三段，顺序不能颠倒：
     *   ① `interceptKey()` —— 控件自己的**行级**语义（多行的 `Enter` 换行、
     *      上下移行、`Home`/`End` 行首行尾）。必须在 `TextKeymap` **之前**：
     *      同一个 `Home`，单行是"文本首"、多行是"行首"，这是两个答案；
     *   ② 编辑键（退格 / Delete / 方向 / Home / End / Shift+方向 / Ctrl+A / 文本）
     *      走 `TextKeymap` —— 与表格单元格是**同一张表**；
     *   ③ `Esc` 有选区时吃掉它，并**消费**这次按键（放行会让 `Application` 的
     *      `activateFocused` 顺手把按钮触发掉）。
     *
     * ★ 返回 `false` 时**不改任何状态**（不偷偷吞键）。
     */
    public function handleKey(KeyEvent $event): bool
    {
        if ($this->interceptKey($event)) {
            return true;
        }

        $change = TextKeymap::apply($this->draft(), $event);

        if ($change !== null) {
            $this->settle($change);

            return true;
        }

        if ($event->chord->is(KeyChord::escape()) && $this->draft()->hasSelection()) {
            $this->settle($this->draft()->clearSelection());

            return true;
        }

        return false;
    }

    /**
     * 编辑键**之前**的拦截：子类用行级语义覆盖 `TextKeymap` 的默认答案。
     *
     * ★ 返回 `true` = 已处理（**必须**自己调 `settle()` 或显式改状态），
     *   不要再走 `TextKeymap` —— 基类不会替你补一次。
     * ★ 默认不拦：单行输入框的 `TextKeymap` 答案就是最终答案。
     */
    protected function interceptKey(KeyEvent $event): bool
    {
        return false;
    }

    // ── 渲染 ────────────────────────────────────────────────────────────

    /**
     * 收口一次编辑动作：**文本真变了才通知**，任何可见变化都重画。
     *
     * ★ 通知顺序：先 `notifyChanged` 再标脏 —— 调用方在回调里读 `text()` 拿到的是
     *   **新值**，而不是"改之前"。
     * ★ `TextChange::None` 直接早退 —— 发假通知会让调用方以为用户改了东西
     *   （G2：宁可少报，不可错报）。
     */
    final protected function settle(TextChange $change): void
    {
        if ($change === TextChange::None) {
            return;
        }

        if ($change === TextChange::Text) {
            $this->notifyChanged($this->draft()->text());
        }

        $this->markDirty();
    }

    /** 插入符矩形（**盒内坐标**）—— 子类从自己的盒上取 */
    abstract public function caretRect(): ?Rect;
}
