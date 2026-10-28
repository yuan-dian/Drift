<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Widget\TextField;

/**
 * 选区模型（#49）：**锚点 + 活动端**，端点恒为字符边界。
 *
 * Shift+方向键只挪活动端；不带 Shift 的移动与任何一次文本改动折叠选区。
 * 这批判据锁的是"选了一段之后，键入/退格/删除都吃它"这条用户可见契约 ——
 * 全是 `TextFieldState` 的状态机，不经布局也不出图 ⇒ Unit 层。
 *
 * ★ 每条都带**方向性**：只验"两端不等"抓不住"实现把起点也动了"这种错
 *   （那种错下面 Shift+Right / Shift+End 两条会给出 [0,3] / [0,4]）。
 */
final class TextFieldSelectionTest extends SmokeTestCase
{
    public function testSelectAllAndAnchorModel(): void
    {
        $ctx = smokeContext();

        $selRoot = TextField::make()->value('abcd')->createElement();
        $selRoot->mount(null, $ctx);
        $selState = $selRoot->state();
        check('选区 · 初始无选区（锚点 = 活动端 = 插入符）', $selState?->hasSelection() === false);

        $selState?->selectAll();
        check(
            '选区 · selectAll() ⇒ 整段被选中，插入符落到末尾',
            $selState?->hasSelection() === true
                && $selState?->selectedText() === 'abcd'
                && $selState?->selectionStart() === 0
                && $selState?->selectionEnd() === 4
                && $selState?->caretOffset() === 4,
            'sel=' . var_export($selState?->selectedText(), true)
                . ' @' . (string) ($selState?->caretOffset() ?? -1)
        );

        // ★ 反向证据：选区两端**不等**才叫有选区。把"全选"实现成只挪插入符的话，
        //   这一条与上面的 selectedText 会一起红。
        check(
            '选区 · 有选区时锚点 ≠ 活动端（不是"只挪了插入符"）',
            $selState?->selectionStart() !== $selState?->selectionEnd(),
        );

        $selState?->moveCaret(2);
        check(
            '选区 · 不带 Shift 的移动折叠选区（两端归位到新插入符）',
            $selState?->hasSelection() === false
                && $selState?->selectionStart() === 2
                && $selState?->selectionEnd() === 2
                && $selState?->caretOffset() === 2,
        );

        $this->flushChecks();
    }

    public function testShiftArrowsMoveOnlyActiveEnd(): void
    {
        $ctx = smokeContext();

        $selRoot = TextField::make()->value('abcd')->createElement();
        $selRoot->mount(null, $ctx);
        $selState = $selRoot->state();
        $selState?->moveCaret(2);

        // 从插入符 2 往左选两个字 ⇒ [0, 2]，插入符跟着活动端走到 0
        $selState?->handleKey(KeyEvent::down(KeyChord::shift('Left')));
        $selState?->handleKey(KeyEvent::down(KeyChord::shift('Left')));
        check(
            '选区 · Shift+Left ×2 ⇒ [0,2]，活动端（=插入符）在 0',
            $selState?->selectionStart() === 0 && $selState?->selectionEnd() === 2
                && $selState?->caretOffset() === 0,
        );

        // ★ 方向性：锚点留在 2 ⇒ 再 Shift+Right 应把活动端从 0 挪回 1（选区 [1,2]）。
        //   若实现把"起点"也动了（锚点跟活动端跑），这里会得到 [0,3] —— 正是要防的。
        $selState?->handleKey(KeyEvent::down(KeyChord::shift('Right')));
        check(
            '选区 · Shift+Right 收缩活动端（锚点不动）⇒ [1,2] 而非 [0,3]',
            $selState?->selectionStart() === 1 && $selState?->selectionEnd() === 2,
            'sel=' . $selState?->selectionStart() . '..' . $selState?->selectionEnd()
        );

        $selState?->handleKey(KeyEvent::down(KeyChord::shift('Home')));
        check(
            '选区 · Shift+Home ⇒ 活动端到行首（[0,2]）',
            $selState?->selectionStart() === 0 && $selState?->selectionEnd() === 2,
        );
        $selState?->handleKey(KeyEvent::down(KeyChord::shift('End')));
        // ★ 锚点从 Shift+Home 起就停在 2，没跟着活动端跑 ⇒ 这里是 [2,4]。
        //   写成 [0,4] 的那个版本把"起点"也动了 —— 那正是锚点模型要防的错。
        check(
            '选区 · Shift+End ⇒ 活动端到行尾，锚点仍在 2（[2,4] 而非 [0,4]）',
            $selState?->selectionStart() === 2 && $selState?->selectionEnd() === 4,
            'sel=' . $selState?->selectionStart() . '..' . $selState?->selectionEnd()
        );

        $this->flushChecks();
    }

    public function testSelectAllShortcutAndEscape(): void
    {
        $ctx = smokeContext();

        $selRoot = TextField::make()->value('abcd')->createElement();
        $selRoot->mount(null, $ctx);
        $selState = $selRoot->state();

        // Ctrl+A 经 handleKey 进来（§9.1 第 ③ 环）
        $selState?->moveCaret(1);
        $selState?->handleKey(KeyEvent::down(KeyChord::ctrl('A')));
        check(
            '选区 · Ctrl+A ⇒ 全选（handleKey 吃掉了这个组合）',
            $selState?->selectionStart() === 0 && $selState?->selectionEnd() === 4,
        );

        // Esc 吃掉选区并**消费按键**（返回 true ⇒ 上层不会拿去激活焦点控件）
        $selEsc = $selState?->handleKey(KeyEvent::down(KeyChord::escape()));
        check(
            '选区 · Esc ⇒ 清掉选区并返回 true（不放行给 activateFocused）',
            $selEsc === true && $selState?->hasSelection() === false,
        );
        check(
            '选区 · 无选区时 Esc 返回 false（别平白吞键）',
            $selState?->handleKey(KeyEvent::down(KeyChord::escape())) === false,
        );

        $this->flushChecks();
    }

    public function testTypingAndDeletingConsumeSelection(): void
    {
        $ctx = smokeContext();

        $selRoot = TextField::make()->value('abcd')->createElement();
        $selRoot->mount(null, $ctx);
        $selState = $selRoot->state();

        // 键入覆盖选区
        $selState?->selectAll();
        $selState?->handleKey(KeyEvent::character('X'));
        check(
            '选区 · 键入 ⇒ 整段替换（abcd → X，插入符落在 1）',
            $selState?->text() === 'X' && $selState?->caretOffset() === 1
                && $selState?->hasSelection() === false,
            'text=' . $selState?->text() . ' @' . (string) ($selState?->caretOffset() ?? -1)
        );

        // 退格 / 删除吃整段
        $selRoot->update(TextField::make()->value('abcd'));
        $selState?->moveCaret(1);
        $selState?->extendSelectionTo(3);
        $selState?->deleteBackward();
        check(
            '选区 · 退格 ⇒ 删掉整段（abcd → ad）',
            $selState?->text() === 'ad' && $selState?->caretOffset() === 1,
            'text=' . $selState?->text() . ' @' . (string) ($selState?->caretOffset() ?? -1)
        );

        $selRoot->update(TextField::make()->value('abcd'));
        $selState?->extendSelectionTo(2);
        $selState?->deleteForward();
        check(
            '选区 · Delete ⇒ 删掉整段（abcd → ab）',
            $selState?->text() === 'ab' && $selState?->caretOffset() === 2,
            'text=' . $selState?->text() . ' @' . (string) ($selState?->caretOffset() ?? -1)
        );

        // 无选区时退格仍是"删一个码位"（别被上面的批量语义带坏）
        $selRoot->update(TextField::make()->value('ab'));
        $selState?->moveCaretToEnd();
        $selState?->deleteBackward();
        check(
            '选区 · 无选区时退格仍只删一个字符（ab → a）',
            $selState?->text() === 'a',
            'text=' . $selState?->text()
        );

        $this->flushChecks();
    }

    public function testMultibyteSelectionStaysOnCharacterBoundaries(): void
    {
        $ctx = smokeContext();

        // ★ 多字节：选区端点恒为字符边界（选"中"不会切出半个字符）
        $cjkSelRoot = TextField::make()->value('中文ab')->createElement();
        $cjkSelRoot->mount(null, $ctx);
        $cjkSelState = $cjkSelRoot->state();
        $cjkSelState?->handleKey(KeyEvent::down(KeyChord::ctrl('A')));
        check(
            '选区 · 多字节全选 ⇒ 端点是字节边界（0..8，不是 0..5 这种切半的值）',
            $cjkSelState?->selectionStart() === 0 && $cjkSelState?->selectionEnd() === 8
                && $cjkSelState?->selectedText() === '中文ab',
            'sel=' . $cjkSelState?->selectionStart() . '..' . $cjkSelState?->selectionEnd()
        );
        $cjkSelState?->handleKey(KeyEvent::down(KeyChord::shift('Left')));
        // 锚点被 Ctrl+A 定在 0 ⇒ 活动端从 8 退到 7（一个完整字符），选区是 [0,7]
        check(
            '选区 · Shift+Left 一格跨一个多字节字符（活动端 8 → 7，落在边界上）',
            $cjkSelState?->selectionStart() === 0 && $cjkSelState?->selectionEnd() === 7,
            'sel=' . $cjkSelState?->selectionStart() . '..' . $cjkSelState?->selectionEnd()
        );
        // [0,7) 正是「中文a」 ⇒ 键入 Z 整段替换成 Zb（保留末尾的 b）
        $cjkSelState?->insert('Z');
        check(
            '选区 · 多字节选区上键入 ⇒ 整段替换（中文ab → Zb）',
            $cjkSelState?->text() === 'Zb',
            'text=' . $cjkSelState?->text()
        );

        $this->flushChecks();
    }

    public function testExternalValueChangeDropsSelection(): void
    {
        $ctx = smokeContext();

        // 外部推新值 ⇒ 选区作废（它描述的是旧文本的字节区间）
        $cjkSelRoot = TextField::make()->value('中文ab')->createElement();
        $cjkSelRoot->mount(null, $ctx);
        $cjkSelState = $cjkSelRoot->state();
        $cjkSelState?->selectAll();
        $cjkSelRoot->update(TextField::make()->value('新'));
        check(
            '选区 · 外部换值 ⇒ 选区一起作废（不高亮一段已经不存在的文本）',
            $cjkSelState?->hasSelection() === false && $cjkSelState?->text() === '新',
        );

        $this->flushChecks();
    }

    public function testExtendSelectionSnapsToCharacterBoundary(): void
    {
        $ctx = smokeContext();

        // ★ 吸附：喂一个落在字符**中间**的偏移，端点必须落到字符边界上。
        //   （否则选中的是半个字符，取出来就是非法 UTF-8。）
        //   这条锁 extendSelectionTo() 的吸附逻辑 —— Shift+方向键给的偏移**天生是
        //   边界**，不专门喂一次坏值，那段代码等于从没被测过（2026-10-21 反例实测：
        //   把吸附整段删掉，前面 26 条夹具一条都不红）。
        $cjkSnapRoot = TextField::make()->value('中文ab')->createElement();
        $cjkSnapRoot->mount(null, $ctx);
        $cjkSnapState = $cjkSnapRoot->state();
        // ★ initState() 把插入符放在**末尾**（既有行为）⇒ 锚点一开始在 8。
        //   从 8 把活动端收回到「文」中间(4) ⇒ 吸附到 3，选区 [3,8]。
        $cjkSnapState?->extendSelectionTo(4);
        check(
            '选区 · extendSelectionTo 吸附到字符边界（4 → 3，不切半个字符）',
            $cjkSnapState?->selectionStart() === 3 && $cjkSnapState?->selectionEnd() === 8
                && $cjkSnapState?->selectedText() === '文ab',
            'sel=' . $cjkSnapState?->selectionStart() . '..' . $cjkSnapState?->selectionEnd()
                . '=' . $cjkSnapState?->selectedText()
        );

        // 把插入符挪回行首再拉过界 ⇒ 夹到 strlen；锚点 0 ⇒ 整段被选中
        $cjkSnapState?->moveCaret(0);
        $cjkSnapState?->extendSelectionTo(99);
        check(
            '选区 · extendSelectionTo 越过末尾夹到 strlen（99 → 8，锚点 0 ⇒ 整段）',
            $cjkSnapState?->selectionStart() === 0 && $cjkSnapState?->selectionEnd() === 8,
            'sel=' . $cjkSnapState?->selectionStart() . '..' . $cjkSnapState?->selectionEnd()
        );
        check(
            '选区 · 选中的文本是完整合法 UTF-8（吸附正确才取得出整串）',
            $cjkSnapState?->selectedText() === '中文ab',
            'sel=' . $cjkSnapState?->selectedText()
        );

        $this->flushChecks();
    }

    public function testEndEditingClearsSelection(): void
    {
        $ctx = smokeContext();

        // 焦点走开 ⇒ 选区清掉（屏幕上不留来路不明的蓝）
        $cjkSelRoot = TextField::make()->value('中文ab')->createElement();
        $cjkSelRoot->mount(null, $ctx);
        $cjkSelState = $cjkSelRoot->state();
        $cjkSelState?->selectAll();
        $cjkSelState?->endEditing();
        check(
            '选区 · endEditing() 清掉选区（选区跟着编辑态走）',
            $cjkSelState?->hasSelection() === false && $cjkSelState?->isEditing() === false,
        );

        $this->flushChecks();
    }
}
