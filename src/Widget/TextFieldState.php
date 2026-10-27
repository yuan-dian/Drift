<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\Rect;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderTextField;
use Drift\Text\TextChange;
use Drift\Text\TextDraft;

/**
 * `TextField` 的纯 UI 态（§11.5 / §12.3）。
 *
 * ★ **为什么编辑缓冲在 `State` 而不是 Store**：§11.5 的判据是"必须跨 build 活、
 *   且只影响这一棵子树"—— 编辑中的草稿与插入符位置正是这一类（§12.3 的
 *   "选中 / 焦点 / **编辑中** / 滚动"）。落库是 `onChanged` 之后调用方的事。
 *
 * ★ **文本状态机不在本类**：缓冲 / 插入符 / 选区的字节数学（含"按码位删除、
 *   永不切半个汉字"）在 `Drift\Text\TextDraft`，与表格的单元格编辑**共用同一份**。
 *   本类只负责三件它独有的事：
 *   1. **焦点**（`beginEditing()` / `endEditing()` 是渲染态开关 —— §9.1"焦点是
 *      渲染态，不放业务值"，它决定插入符画不画）；
 *   2. **通知**（文本真变了才调 `onChanged`，只挪插入符不通知）；
 *   3. **渲染**（`build()` 出 `TextFieldBox`，盒里有插入符与选区几何）。
 *
 *   合成一个类会让"再来一个文本面"（表格单元格 / 将来的 `TextArea`）要么复制
 *   那套字节数学、要么继承一个带着焦点与渲染的类 —— 两条都是错的方向。
 *
 * ★ **编辑态与焦点**：`FocusManager`（`Drift\App`）在焦点落到本字段时调
 *   `beginEditing()`、走开时调 `endEditing()`；按键经 `handleKey()` 翻译成编辑
 *   动作 —— 三者合起来就是"输入层最小集"的 widget 侧接口。
 *
 * @api
 */
final class TextFieldState extends State implements EditableText
{
    /**
     * 文本状态机（`initState()` 里按 `value()` 建；`didUpdateWidget()` 采纳新值）。
     *
     * ★ 声明成不可空 + 在 `initState()` 里赋值（而不是字段初始化器）：初值来自
     *   Widget，`State` 构造时还拿不到 `widget()`。
     */
    private TextDraft $draft;

    /** @internal 渲染态：在编辑中 ⇒ 画插入符 */
    private bool $editing = false;

    #[\Override]
    public function initState(): void
    {
        // 初值来自 widget 的 value()（§A.2.9 的受控形态：值由外部给）
        $this->draft = new TextDraft($this->field()->valueText());
    }

    /**
     * 外部推了新值 ⇒ 采纳它，并把插入符移到末尾。
     *
     * ★ **同值不动**由 `TextDraft::setText()` 保证：否则调用方每帧重传同一个值
     *   都会把插入符打回末尾，用户正在中间插入时就"跟手输入不丢"这条承诺直接失效。
     */
    #[\Override]
    public function didUpdateWidget(Widget $widget): void
    {
        parent::didUpdateWidget($widget);

        if (!$widget instanceof TextField) {
            return;
        }

        $this->draft->setText($widget->valueText());
    }

    /** 当前文本（**已包含未提交的编辑**，与 `TextField::valueText()` 可能不同） */
    public function text(): string
    {
        return $this->draft->text();
    }

    public function caretOffset(): int
    {
        return $this->draft->caretOffset();
    }

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
        // ★ 选区是编辑态的一部分 ⇒ **不管当前在不在编辑都收口**。早先的实现在
        //   `!editing` 时早退，于是「不在编辑却有选区」这个状态能活下来
        //   （屏幕上留一片来路不明的蓝，且下次进入编辑时它还在）。
        //   smoke 的「endEditing() 清掉选区」就是那时候的红。
        $hadSelection = $this->draft->hasSelection();
        $this->draft->clearSelection();

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
        $this->settle($this->draft->insert($text));
    }

    /** 退格：有选区删整段，否则按码位删前一个字符 */
    public function deleteBackward(): void
    {
        $this->settle($this->draft->deleteBackward());
    }

    /** Delete：有选区删整段，否则按码位删后一个字符 */
    public function deleteForward(): void
    {
        $this->settle($this->draft->deleteForward());
    }

    /** 移动插入符（字节偏移，吸附到字符边界）；折叠选区 */
    public function moveCaret(int $offset): void
    {
        $this->settle($this->draft->moveCaret($offset));
    }

    public function moveCaretToEnd(): void
    {
        $this->settle($this->draft->moveCaretToEnd());
    }

    public function hasSelection(): bool
    {
        return $this->draft->hasSelection();
    }

    /** 选区起点（两个端里小的那个） */
    public function selectionStart(): int
    {
        return $this->draft->selectionStart();
    }

    /** 选区终点（两个端里大的那个） */
    public function selectionEnd(): int
    {
        return $this->draft->selectionEnd();
    }

    /** 选中的文本（无选区 ⇒ 空串） */
    public function selectedText(): string
    {
        return $this->draft->selectedText();
    }

    /** 全选（`Ctrl+A`）；空文本没有"全选"这回事 */
    public function selectAll(): void
    {
        $this->settle($this->draft->selectAll());
    }

    /** 折叠选区（两端都归位到插入符）；无选区 ⇒ 无事发生 */
    public function clearSelection(): void
    {
        $this->settle($this->draft->clearSelection());
    }

    /** 扩展选区到 `$offset`（只挪活动端，锚点不动） */
    public function extendSelectionTo(int $offset): void
    {
        $this->settle($this->draft->extendSelectionTo($offset));
    }

    /** 用 `$text` 整段替换选区；无选区 ⇒ 退化成在插入符处插入 */
    public function replaceSelection(string $text): void
    {
        $this->settle($this->draft->replaceSelection($text));
    }

    /** 删掉选区（无选区 ⇒ 无事发生） */
    public function deleteSelection(): void
    {
        $this->settle($this->draft->deleteSelection());
    }

    /**
     * 处理一次按键（§9.1 路由链第 ③ 环落到这里）。
     *
     * ★ **映射规则**（把 `KeyEvent` 翻译成编辑动作）：
     *   · `Backspace` / `Delete` ⇒ 有选区删**整段**，否则按码位删前 / 删后
     *   · `Left` / `Right` ⇒ 移到前 / 后一个**码位边界**（折叠选区）
     *   · `Shift` + `Left` / `Right` / `Home` / `End` ⇒ **扩展**选区（只挪活动端）
     *   · `Home` / `End` ⇒ 行首 / 行尾
     *   · `Ctrl+A` ⇒ 全选；`Esc` ⇒ 有选区时吃掉它
     *   · 带文本的按键 ⇒ 在插入符处插入（有选区则整段替换）
     *   · 其余（含加速键）⇒ **返回 false**，让上层继续找别的消费者
     *
     * ★ 返回 `bool` 而不是 `void`：§9.1 的路由链要能问"这一环吃掉了没有"。
     *   返回 `false` 时**不改任何状态**（不偷偷吞键）。
     */
    public function handleKey(KeyEvent $event): bool
    {
        $chord = $event->chord;

        if ($chord->is(KeyChord::backspace())) {
            $this->deleteBackward();

            return true;
        }

        if ($chord->is(KeyChord::delete())) {
            $this->deleteForward();

            return true;
        }

        if ($chord->is(KeyChord::left())) {
            $this->moveCaret($this->draft->previousBoundary());

            return true;
        }

        if ($chord->is(KeyChord::right())) {
            $this->moveCaret($this->draft->nextBoundary());

            return true;
        }

        if ($chord->is(KeyChord::home())) {
            $this->moveCaret(0);

            return true;
        }

        if ($chord->is(KeyChord::end())) {
            $this->moveCaretToEnd();

            return true;
        }

        if ($chord->is(KeyChord::ctrl('A'))) {
            $this->selectAll();

            return true;
        }

        if ($chord->is(KeyChord::shift('Left'))) {
            // ★ Shift+方向键 = 扩展选区（只挪活动端）。与不带 Shift 的两个分支
            //   走**同一对边界助手**，区别只在"折叠还是扩展"
            //   （见 moveCaret / extendSelectionTo 的分工）。
            $this->extendSelectionTo($this->draft->previousBoundary());

            return true;
        }

        if ($chord->is(KeyChord::shift('Right'))) {
            $this->extendSelectionTo($this->draft->nextBoundary());

            return true;
        }

        if ($chord->is(KeyChord::shift('Home'))) {
            $this->extendSelectionTo(0);

            return true;
        }

        if ($chord->is(KeyChord::shift('End'))) {
            $this->extendSelectionTo(\strlen($this->draft->text()));

            return true;
        }

        if ($chord->is(KeyChord::escape())) {
            // 有选区 ⇒ Esc 吃掉它（各平台"取消选择"的默认键），并**消费这次按键**
            // —— 放行会让 Application 的 activateFocused 顺手把按钮触发掉。
            if ($this->draft->hasSelection()) {
                $this->clearSelection();

                return true;
            }

            return false;
        }

        if ($event->isText()) {
            // 有选区时 insert() 内部走整段替换（见 `TextDraft::insert()`）
            $this->insert($event->text);

            return true;
        }

        return false;
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
        // ★ 必须**穿过包装层**找 `RenderTextField`：有 label 时 `build()` 返回
        //   `Column[Text(标签), 字段盒]`，`effectiveBox()` 给的是 `Column` 的盒。
        //   用它判类型 ⇒ `null` ⇒ 壳收到"无插入符" ⇒ 候选框贴屏幕左上角
        //   （2026-10-19 实测：带 label 的字段全错，无 label 的全对）。
        $box = $this->element()?->findBox(
            static fn(RenderBox $candidate): bool => $candidate instanceof RenderTextField,
        );

        return $box?->caretRect();
    }

    public function build(BuildContext $ctx): Widget
    {
        $field = $this->field();

        $box = TextFieldBox::make(
            $this->draft->text(),
            $field->placeholderText(),
            $this->editing ? $this->draft->caretOffset() : null,
            // 选区恒给（相等即"无选区"）；焦点走开时 endEditing() 已清掉
            $this->draft->selectionStart(),
            $this->draft->selectionEnd(),
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
     * 收口一次编辑动作：**文本真变了才通知**，任何可见变化都重画。
     *
     * ★ 通知顺序与 2026-10-24 的实现一致（先 `onChanged` 再标脏）：调用方在
     *   回调里读 `text()` 拿到的是**新值**，而不是"改之前"。
     * ★ `TextChange::None` 直接早退 —— 发假通知会让调用方以为用户改了东西
     *   （G2：宁可少报，不可错报）。
     */
    private function settle(TextChange $change): void
    {
        if ($change === TextChange::None) {
            return;
        }

        if ($change === TextChange::Text) {
            $handler = $this->field()->changedHandler();
            if ($handler !== null) {
                $handler($this->draft->text());
            }
        }

        $this->markDirty();
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
