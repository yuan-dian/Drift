<?php
declare(strict_types=1);

namespace Drift\Input;

use Drift\Text\TextChange;
use Drift\Text\TextDraft;

/**
 * 编辑键 → `TextDraft` 动作的**唯一**映射表（输入框与表格单元格共用）。
 *
 * ★ 为什么住 `Input` 而不是 `Text`：本表必须同时看见 `KeyEvent` 与 `TextDraft`，
 *   而 §4.3 不允许 `Text` 依赖 `Input`（`Rendering` 要 `Text`，会成环）。
 *   ⇒ 只能由 `Input` 向上看。这也正是"按键翻译"本来该在的层。
 *
 * ★ 返回 `null` = **这个键不归编辑管**，调用方继续找别的消费者。
 *   它与 `TextChange::None`（管了，但没改动）是两件事：合成一个 `bool` 会让
 *   "吞掉一个加速键"与"什么也没干"再也分不开。
 *
 * ★ 收口键（`Enter` / `Tab` / `Esc` / 上下移）**故意不在这里**：它们问的是
 *   "这次编辑怎么收场"，两个调用方答案不同 —— 输入框交给应用，表格是提交后下移。
 *
 * ★ 本表此前在 `TextFieldState::handleKey()` 与 `TableState::editKey()` 各写了一遍，
 *   且后者注释自认"编辑键**逐字同义**"。两处并存时，加一个键位要改两处，
 *   漏一处的表现是"输入框支持、表格不支持"且不报错。
 *
 * ★ 标 `@api` 不是为了"看起来完整"：第三方要写第三个可编辑控件（`TextArea` /
 *   树的重命名框），这里就是它该复用的入口。标 `@internal` 的实际后果是
 *   **下一个人再抄一遍** —— 那正是这条债的形成方式。
 *
 * @api
 */
final class TextKeymap
{
    /**
     * 把一次按键应用到草稿上。
     *
     * ★ 顺序有意义：`isText()` 必须在最后 —— 文本键的 chord 就是那个字符，
     *   放前面会把 `Esc` 之类永远挡在门外。
     *
     * @return TextChange|null `null` = 不是编辑键（调用方**不得**消费这次按键）
     */
    public static function apply(TextDraft $draft, KeyEvent $event): ?TextChange
    {
        $chord = $event->chord;

        if ($chord->is(KeyChord::backspace())) {
            return $draft->deleteBackward();
        }

        if ($chord->is(KeyChord::delete())) {
            return $draft->deleteForward();
        }

        if ($chord->is(KeyChord::left())) {
            return $draft->moveCaret($draft->previousBoundary());
        }

        if ($chord->is(KeyChord::right())) {
            return $draft->moveCaret($draft->nextBoundary());
        }

        if ($chord->is(KeyChord::home())) {
            return $draft->moveCaret(0);
        }

        if ($chord->is(KeyChord::end())) {
            return $draft->moveCaretToEnd();
        }

        if ($chord->is(KeyChord::ctrl('A'))) {
            return $draft->selectAll();
        }

        // ★ 按词移动（`Ctrl+←/→`）与按词扩选（`Ctrl+Shift+←/→`）。
        //   缺了它们的现象不是"按了没反应"，而是按键**静默穿过**编辑器：
        //   `TextKeymap` 返回 null ⇒ 调用方继续找别的消费者 ⇒ 在某些应用里
        //   还会被当成加速键吃掉（例如 `Ctrl+←` 被当成"上一条"）。
        if ($chord->is(KeyChord::make('Left', true))) {
            return $draft->moveCaret($draft->previousWordStart());
        }

        if ($chord->is(KeyChord::make('Right', true))) {
            return $draft->moveCaret($draft->nextWordStart());
        }

        if ($chord->is(KeyChord::shift('Left'))) {
            return $draft->extendSelectionTo($draft->previousBoundary());
        }

        if ($chord->is(KeyChord::shift('Right'))) {
            return $draft->extendSelectionTo($draft->nextBoundary());
        }

        if ($chord->is(KeyChord::make('Left', true, false, true))) {
            return $draft->extendSelectionTo($draft->previousWordStart());
        }

        if ($chord->is(KeyChord::make('Right', true, false, true))) {
            return $draft->extendSelectionTo($draft->nextWordStart());
        }

        if ($chord->is(KeyChord::shift('Home'))) {
            return $draft->extendSelectionTo(0);
        }

        if ($chord->is(KeyChord::shift('End'))) {
            return $draft->extendSelectionTo(\strlen($draft->text()));
        }

        if ($event->isText()) {
            // 有选区 ⇒ `insert()` 内部走整段替换（见 `TextDraft::insert()`）
            return $draft->insert($event->text);
        }

        return null;
    }
}
