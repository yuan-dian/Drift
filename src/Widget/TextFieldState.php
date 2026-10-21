<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Geometry\Rect;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Layout\RenderBox;
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
 * ★ **选区是"锚点 + 活动端"两个字节偏移**（恒字符边界）：`hasSelection()` ⇔ 两者
 *   不等。不带 Shift 的移动 / 任何一次真的文本改动都**折叠**选区（两端归位到
 *   插入符）—— 选中的是旧文本的一段，替换之后它已经不存在了。反过来 Shift+方向键
 *   只挪活动端，于是"从起点一路选到当前"与"从当前往回选"是同一个模型。
 *
 * ★ **编辑态与焦点**：`beginEditing()` / `endEditing()` 是**渲染态开关**（§9.1：
 *   "焦点是渲染态，不放业务值"），它决定插入符画不画。`FocusManager`（`Drift\App`）
 *   在焦点落到本字段时调 `beginEditing()`、走开时调 `endEditing()`；按键经
 *   `handleKey()` 翻译成编辑动作 —— 三者合起来就是"输入层最小集"的 widget 侧接口。
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

    /** @internal 选区锚点（字节偏移，恒字符边界）；与 `$selExtent` 相等 ⇔ 无选区 */
    private int $selAnchor = 0;

    /** @internal 选区活动端（字节偏移，恒字符边界）—— Shift+方向键 / 指针拖动挪的是它 */
    private int $selExtent = 0;

    public function initState(): void
    {
        // 初值来自 widget 的 value()（§A.2.9 的受控形态：值由外部给）
        $this->buffer = $this->field()->valueText();
        $this->caret = strlen($this->buffer);
        // 初始无选区：锚点 = 活动端（这个不变量由各编辑动作共同维护）
        $this->selAnchor = $this->caret;
        $this->selExtent = $this->caret;
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
        // 外部换值 ⇒ 选区一起作废（它描述的是**旧文本**的字节区间）
        $this->selAnchor = $this->caret;
        $this->selExtent = $this->caret;
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
        // ★ 选区是编辑态的一部分 ⇒ **不管当前在不在编辑都收口**。早先的实现在
        //   `!editing` 时早退，于是「不在编辑却有选区」这个状态能活下来
        //   （屏幕上留一片来路不明的蓝，且下次进入编辑时它还在）。
        //   smoke 的「endEditing() 清掉选区」就是那时候的红。
        $hadSelection = $this->selAnchor !== $this->selExtent;
        if ($hadSelection) {
            $this->selAnchor = $this->caret;
            $this->selExtent = $this->caret;
        }

        if (!$this->editing && !$hadSelection) {
            return;   // 两样都没有 ⇒ 真的无事发生（不标脏）
        }

        $this->editing = false;
        $this->markDirty();
    }

    /**
     * 在插入符处插入文本（IME 提交 / 直接键入的落点，§8.4）。
     *
     * ★ **有选区 ⇒ 整段替换**：键入覆盖选中的文本（与各平台编辑器的默认行为
     *   一致）。于是调用方不必先问"现在有没有选区"。
     *
     * 插入符移到插入内容的末尾。
     */
    public function insert(string $text): void
    {
        if ($text === '') {
            return;
        }

        if ($this->selAnchor !== $this->selExtent) {
            $this->replaceSelection($text);

            return;
        }

        $this->apply(
            substr($this->buffer, 0, $this->caret) . $text . substr($this->buffer, $this->caret),
            $this->caret + strlen($text),
        );
    }

    /**
     * 退格：**有选区 ⇒ 删掉整段**；否则删掉插入符前一个字符（按码位，不是按字节）。
     */
    public function deleteBackward(): void
    {
        if ($this->selAnchor !== $this->selExtent) {
            $this->deleteSelection();

            return;
        }

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

    /** Delete：**有选区 ⇒ 删掉整段**；否则删掉插入符后一个字符 */
    public function deleteForward(): void
    {
        if ($this->selAnchor !== $this->selExtent) {
            $this->deleteSelection();

            return;
        }

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

        // ★ 早退条件必须带着选区看：偏移没变但**有选区**时也得走下去 ——
        //   "在同一位置再点一下取消选择"是可见变化（高亮消失）。
        if ($snapped === $this->caret && $this->selAnchor === $this->selExtent) {
            return;
        }

        $this->caret = $snapped;
        // 不带 Shift 的移动 = 折叠选区（锚点与活动端都归位到新插入符）
        $this->selAnchor = $snapped;
        $this->selExtent = $snapped;
        $this->markDirty();
    }

    public function moveCaretToEnd(): void
    {
        $this->moveCaret(strlen($this->buffer));
    }

    // ── 选区（锚点 / 活动端模型；渲染与剪贴板命令都建在它上面）──────────

    /** 有没有选区（锚点 ≠ 活动端） */
    public function hasSelection(): bool
    {
        return $this->selAnchor !== $this->selExtent;
    }

    /** 选区起点（两个端里小的那个 —— 展示与删除用它，别用锚点） */
    public function selectionStart(): int
    {
        if ($this->selAnchor < $this->selExtent) {
            return $this->selAnchor;
        }

        return $this->selExtent;
    }

    /** 选区终点（两个端里大的那个） */
    public function selectionEnd(): int
    {
        if ($this->selAnchor > $this->selExtent) {
            return $this->selAnchor;
        }

        return $this->selExtent;
    }

    /** 选中的文本（无选区 ⇒ 空串） */
    public function selectedText(): string
    {
        if ($this->selAnchor === $this->selExtent) {
            return '';
        }

        $start = $this->selectionStart();

        return substr($this->buffer, $start, $this->selectionEnd() - $start);
    }

    /** 全选（`Ctrl+A`）；空文本没有"全选"这回事 */
    public function selectAll(): void
    {
        $end = strlen($this->buffer);
        if ($end === 0) {
            return;
        }

        if ($this->selectionStart() === 0 && $this->selectionEnd() === $end && $this->caret === $end) {
            return;   // 已经全选 ⇒ 无变化不重建（G2：宁可少报）
        }

        $this->selAnchor = 0;
        $this->selExtent = $end;
        $this->caret = $end;
        $this->markDirty();
    }

    /** 折叠选区（两端都归位到插入符）；无选区 ⇒ 无事发生 */
    public function clearSelection(): void
    {
        if ($this->selAnchor === $this->selExtent) {
            return;
        }

        $this->selAnchor = $this->caret;
        $this->selExtent = $this->caret;
        $this->markDirty();
    }

    /**
     * 扩展选区到 `$offset`（吸附到字符边界）—— Shift+方向键的手感来源。
     *
     * ★ **只挪活动端，锚点不动** ⇒ "从起点一路选到当前"与"从当前往回选"是同一个
     *   模型。指针拖动框选给的也是这个形状（见 #50 的 `dispatchPointer` 分支）。
     */
    public function extendSelectionTo(int $offset): void
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

        // 活动端没动、且本来就没选区 ⇒ 真的无事发生（连脏都不标）
        if ($snapped === $this->selExtent && $this->selAnchor === $this->selExtent) {
            return;
        }

        $this->caret = $snapped;
        $this->selExtent = $snapped;
        $this->markDirty();
    }

    /**
     * 用 `$text` 整段替换选区；无选区 ⇒ 退化成在插入符处插入。
     *
     * ★ 键入 / 粘贴 / 剪切三方共用这一个落点 ⇒ 不可能走出两种语义。
     */
    public function replaceSelection(string $text): void
    {
        if ($this->selAnchor === $this->selExtent) {
            $this->insert($text);

            return;
        }

        $start = $this->selectionStart();
        $next = substr($this->buffer, 0, $start) . $text . substr($this->buffer, $this->selectionEnd());

        if ($next === $this->buffer) {
            // 替换成同一段文本 ⇒ 缓冲没变，但选区必须收口（用户确实按了删除）
            $caret = $start + strlen($text);
            $this->caret = $caret;
            $this->selAnchor = $caret;
            $this->selExtent = $caret;
            $this->markDirty();

            return;
        }

        $this->apply($next, $start + strlen($text));
    }

    /** 删掉选区（无选区 ⇒ 无事发生） */
    public function deleteSelection(): void
    {
        if ($this->selAnchor === $this->selExtent) {
            return;
        }

        $this->replaceSelection('');
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
            $this->moveCaret($this->previousBoundary());

            return true;
        }

        if ($chord->is(KeyChord::right())) {
            $this->moveCaret($this->nextBoundary());

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
            $this->extendSelectionTo($this->previousBoundary());

            return true;
        }

        if ($chord->is(KeyChord::shift('Right'))) {
            $this->extendSelectionTo($this->nextBoundary());

            return true;
        }

        if ($chord->is(KeyChord::shift('Home'))) {
            $this->extendSelectionTo(0);

            return true;
        }

        if ($chord->is(KeyChord::shift('End'))) {
            $this->extendSelectionTo(strlen($this->buffer));

            return true;
        }

        if ($chord->is(KeyChord::escape())) {
            // 有选区 ⇒ Esc 吃掉它（各平台"取消选择"的默认键），并**消费这次按键**
            // —— 放行会让 Application 的 activateFocused 顺手把按钮触发掉。
            if ($this->selAnchor !== $this->selExtent) {
                $this->clearSelection();

                return true;
            }

            return false;
        }

        if ($event->isText()) {
            // 有选区时 insert() 内部走整段替换（见该方法的注释）
            $this->insert($event->text);

            return true;
        }

        return false;
    }

    /**
     * 插入符**前一个**字符边界（0 = 已在最前）。
     *
     * ★ 不能写成 `moveCaret($this->caret - 1)` 的镜像：字节偏移差 1 很可能落在
     *   多字节字符**中间**，而 `moveCaret()` 吸附到"不大于偏移的最近边界" ⇒
     *   把 -1 吸回原地（按了没反应）。所以向左必须自己找上一个边界。
     */
    private function previousBoundary(): int
    {
        $previous = 0;
        foreach ($this->boundaries() as $bound) {
            if ($bound >= $this->caret) {
                break;
            }
            $previous = $bound;
        }

        return $previous;
    }

    /** 插入符**后一个**字符边界（末尾 = 已在最后） */
    private function nextBoundary(): int
    {
        $next = strlen($this->buffer);
        foreach ($this->boundaries() as $bound) {
            if ($bound > $this->caret) {
                $next = $bound;
                break;
            }
        }

        return $next;
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
            $this->buffer,
            $field->placeholderText(),
            $this->editing ? $this->caret : null,
            // 选区恒给（相等即"无选区"）；焦点走开时 endEditing() 已清掉
            $this->selectionStart(),
            $this->selectionEnd(),
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
        // 任何一次真的文本改动都折叠选区：它选中的是**旧文本**的一段，
        // 替换之后那段字节区间已经不存在了（留着 = 高亮一片来路不明的文本）。
        $this->selAnchor = $nextCaret;
        $this->selExtent = $nextCaret;

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
