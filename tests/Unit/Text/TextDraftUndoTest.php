<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Text;

use Drift\Text\TextChange;
use Drift\Text\TextDraft;
use PHPUnit\Framework\TestCase;

/**
 * `TextDraft` 的撤销栈。
 *
 * ★ 这块的判据分两类，**两类都要有**：
 *   ① "能撤"（打字能撤、删除能撤）—— 只测这个，把栈写成"每个动作一步"也能过；
 *   ② "合并成一步"（打 10 个字撤一次全回去）—— 这才是可用与不可用的分界。
 *   且 ② 必须配一条反例（移动光标之后**不许**再合并），否则"把合并写成无条件"
 *   同样能过 —— 而那种实现在真机上的表现是"撤一次把整段历史全撤光"。
 *
 * ★ 还有一条最容易被漏掉、且漏了不报错：**撤销要恢复选区**。只回文本不回选区，
 *   用户看到的是"文本对了但高亮没了"，且没有任何断言会红。
 */
final class TextDraftUndoTest extends TestCase
{
    /**
     * 连续打字合并成**一步** —— 撤销的可用性就取决于这一条。
     *
     * 反例（每个按键一步）的表现：输入"你好世界"后按 Ctrl+Z 只退掉"界"，
     * 用户要按四次才能退干净，而绝大多数人会以为 Ctrl+Z 坏了。
     */
    public function testTypingMergesIntoOneStep(): void
    {
        $draft = new TextDraft('');

        foreach (['你', '好', '世', '界'] as $glyph) {
            $draft->insert($glyph);
        }

        self::assertSame('你好世界', $draft->text());
        self::assertSame(TextChange::Text, $draft->undo());
        self::assertSame('', $draft->text(), '一次性输入的一整串必须一步撤完');
        self::assertNull($draft->undo(), '撤到起点之后就不该还有东西可撤');
    }

    /**
     * 光标移动**打断**合并 ⇒ 前后两串打字是两步。
     *
     * 这是上一条的反例：把 `breakMerge()` 从 `moveCaret()` 里去掉，这条会红
     * （第一次 undo 就会把两串一起撤掉）。
     */
    public function testCaretMoveBreaksMerge(): void
    {
        $draft = new TextDraft('');

        $draft->insert('a');
        $draft->insert('b');     // 与上一步合并
        $draft->moveCaret(0);    // ★ 打断
        $draft->insert('X');
        $draft->insert('Y');     // 与上一步合并
        self::assertSame('XYab', $draft->text());

        $draft->undo();
        self::assertSame('ab', $draft->text(), '只该撤掉光标移动之后那一串');

        $draft->undo();
        self::assertSame('', $draft->text(), '再撤一次才是光标移动之前那一串');

        self::assertNull($draft->undo(), '光标移动本身不是可撤销动作，不该占一步');
    }

    /**
     * 选区变化同样打断合并 —— 三条路径都要断（`selectRange` / `selectAll` /
     * `extendSelectionTo`）。
     *
     * ★ 它们各自是独立实现（都不是互相调用），所以漏掉任何一处都必须能被抓到；
     *   只测其中一个的话，另外两处的 `breakMerge()` 删掉也不会红。而漏掉的后果是
     *   "选中一段、打字覆盖，再按 Ctrl+Z 把覆盖连同覆盖前的输入一起撤光"。
     *
     * ★ `clearSelection()` 不在这里：撤销前必然已有选区，而有选区必然经过上面
     *   三条之一（那处已经打断过了）⇒ 它那行 `breakMerge()` 是对不变量的防御，
     *   造不出"只靠它才不合并"的现场。
     */
    public function testEverySelectionChangeBreaksMerge(): void
    {
        // ① selectRange（指针框选 / 双击选词都走它）
        $range = new TextDraft('abcd');      // 插入符在末尾
        $range->insert('X');                 // abcdX
        $range->selectRange(4, 5);           // 选中末尾那个 X
        $range->insert('Y');                 // abcdY
        $range->undo();
        self::assertSame('abcdX', $range->text(), 'selectRange 没有打断合并');

        // ② selectAll（Ctrl+A）
        $all = new TextDraft('abcd');
        $all->insert('X');                   // abcdX
        $all->selectAll();
        $all->insert('Y');                   // Y
        $all->undo();
        self::assertSame('abcdX', $all->text(), 'selectAll 没有打断合并');

        // ③ extendSelectionTo（Shift+方向键）
        $extend = new TextDraft('abcd');
        $extend->insert('X');                // abcdX
        $extend->extendSelectionTo(0);       // 选区 0..5
        $extend->insert('Y');                // Y
        $extend->undo();
        self::assertSame('abcdX', $extend->text(), 'extendSelectionTo 没有打断合并');
    }

    /** 连续删除也合并成一步（按住退格删 5 个字符，一次全回来） */
    public function testDeletingMergesIntoOneStep(): void
    {
        $draft = new TextDraft('abcdef');

        $draft->deleteBackward();
        $draft->deleteBackward();
        $draft->deleteBackward();
        self::assertSame('abc', $draft->text());

        $draft->undo();
        self::assertSame('abcdef', $draft->text(), '连续退格要一步恢复');
    }

    /**
     * 输入与删除**不合并**（不同类别）。
     *
     * 反例：把 kind 恒传 `'type'`（或恒传同一个常量），这条会红 —— 而它的表现是
     * "打字、退格、再打字" 之后按 Ctrl+Z 把三段一起撤光。
     */
    public function testTypeAndDeleteAreSeparateSteps(): void
    {
        $draft = new TextDraft('');

        $draft->insert('ab');
        $draft->deleteBackward();
        self::assertSame('a', $draft->text());

        $draft->undo();
        self::assertSame('ab', $draft->text(), '先撤删除');

        $draft->undo();
        self::assertSame('', $draft->text(), '再撤输入');
    }

    /**
     * 撤销恢复**选区**，不只是文本。
     *
     * 漏了这一条的实现（快照只存文本）在真机上的表现：选中一段、输入覆盖、
     * 撤销 ⇒ 文本回来了但没有高亮，用户得自己再选一遍。
     */
    public function testUndoRestoresSelection(): void
    {
        $draft = new TextDraft('hello world');

        $draft->selectRange(0, 5);          // 选中 "hello"
        $draft->insert('HI');               // 覆盖 → "HI world"
        self::assertSame('HI world', $draft->text());

        $draft->undo();
        self::assertSame('hello world', $draft->text());
        self::assertSame(0, $draft->selectionStart(), '撤销要恢复覆盖前的选区起点');
        self::assertSame(5, $draft->selectionEnd(), '撤销要恢复覆盖前的选区终点');
        self::assertSame('hello', $draft->selectedText());
    }

    /** 撤销也恢复插入符位置（未选中时的编辑） */
    public function testUndoRestoresCaret(): void
    {
        $draft = new TextDraft('abcd');
        $draft->moveCaret(2);               // a|bcd

        $draft->insert('XY');               // abXY|cd
        $draft->undo();

        self::assertSame('abcd', $draft->text());
        self::assertSame(2, $draft->caretOffset(), '插入符要回到编辑前的位置');
    }

    /** 重做回到撤销前；且新编辑让重做栈作废（撤销树只有一支） */
    public function testRedoAndItsInvalidation(): void
    {
        $draft = new TextDraft('');

        $draft->insert('ab');
        $draft->undo();
        self::assertSame('', $draft->text());
        self::assertTrue($draft->canRedo());

        self::assertSame(TextChange::Text, $draft->redo());
        self::assertSame('ab', $draft->text());

        $draft->undo();
        self::assertSame('', $draft->text());

        $draft->insert('X');                        // ★ 新编辑
        self::assertFalse($draft->canRedo(), '撤销之后一旦有新编辑，旧的重做分支必须作废');
        self::assertNull($draft->redo());
    }

    /**
     * 外部推值（受控组件的 `value()`）**不进撤销栈，并且清空整个历史**。
     *
     * 两个坑各写一条断言：
     * ① 进了栈 ⇒ 受控组件每帧重建灌同一个值，历史被垃圾淹没；
     * ② 只"不进栈"却不清理 ⇒ 撤销会退到一个已被外部数据取代的文本上
     *    （"撤销把新数据撤没了"）。浏览器对 `input.value = x` 也是重置 undo 栈。
     */
    public function testExternalSetTextClearsHistory(): void
    {
        $draft = new TextDraft('old');
        $draft->insert('!');
        self::assertTrue($draft->canUndo());

        $draft->setText('from-store');

        self::assertFalse($draft->canUndo(), '外部推值之后历史必须作废');
        self::assertFalse($draft->canRedo());
        self::assertNull($draft->undo());
        self::assertSame('from-store', $draft->text());
    }

    /** 同值推入不算外部推值（否则每帧重传同一个值都会清掉用户的历史） */
    public function testSetTextWithSameValueKeepsHistory(): void
    {
        $draft = new TextDraft('x');
        $draft->insert('y');
        self::assertTrue($draft->canUndo());

        $draft->setText('xy');   // 与当前同值

        self::assertTrue($draft->canUndo(), '同值重复推入不该动历史');
        $draft->undo();
        self::assertSame('x', $draft->text());
    }

    /**
     * 栈上限：超过 100 步就丢最旧的。
     *
     * ★ 用"输入 / 删除"交替造步：两者 kind 不同 ⇒ 每次都独立入栈。
     *   （只连续 `insert` 会合并成一步，根本到不了上限 —— 那样测出来的"通过"
     *   是假的。）
     */
    public function testUndoLimitDropsOldest(): void
    {
        $draft = new TextDraft('');

        for ($i = 0; $i < 150; $i++) {
            $draft->insert('x');
            $draft->deleteBackward();
        }

        $steps = 0;
        while ($draft->undo() !== null) {
            ++$steps;
        }

        self::assertSame(100, $steps, '栈上限是 100 步，多出来的最旧项要丢掉');
    }

    /** 空栈：`undo()` 返回 `null`（"这一步根本不存在"），不是 `TextChange::None` */
    public function testEmptyStackReturnsNull(): void
    {
        $draft = new TextDraft('x');

        self::assertFalse($draft->canUndo());
        self::assertFalse($draft->canRedo());
        self::assertNull($draft->undo());
        self::assertNull($draft->redo());
        self::assertSame('x', $draft->text(), '没得撤时不许动文本');
    }

    /**
     * 快速连续 `insert`（IME 逐字上屏的形状）合并成一步。
     *
     * ★ 这条**不声称**等价于真实 IME 组合期：组合串在平台侧怎么递进来取决于壳
     *   （`WM_IME_COMPOSITION` 在 C++ 宿主）。这里只钉住"连续的 `insert` 不产生
     *   一字符一步的垃圾栈"这条纯逻辑性质。
     */
    public function testRapidSuccessiveInsertsMergeIntoOneStep(): void
    {
        $draft = new TextDraft('');

        $draft->insert('n');
        $draft->insert('i');
        $draft->insert('你');

        $draft->undo();
        self::assertSame('', $draft->text(), '逐字上屏的一串要一步撤完');
    }
}
