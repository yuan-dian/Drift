<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Text;

use Drift\Text\TextChange;
use Drift\Text\TextDraft;
use PHPUnit\Framework\TestCase;

/**
 * `TextDraft` 的**字节数学**。
 *
 * ★ 这块的错法都是"按一下退格删掉半个汉字"级别，而且**不会报错** ——
 *   所以每条都要钉死。字符串在 PHP 里是字节序列，插入符是字节偏移，
 *   而用户看到的是一串码位；两者之间的换算错了，界面只是"有时候怪怪的"。
 */
final class TextDraftTest extends TestCase
{
    public function testDeleteBackwardRemovesCodePointNotByte(): void
    {
        $draft = new TextDraft('中文ab');
        $draft->deleteBackward();

        self::assertSame('中文a', $draft->text());
        // 插入符落在**字符边界**上（不是"减一字节"）
        self::assertSame(\strlen('中文a'), $draft->caretOffset());
    }

    /** 偏移 1 落在"中"的字节中间 ⇒ 吸附回 0，并报 `Caret`（要重画插入符） */
    public function testCaretSnapsToCharacterBoundary(): void
    {
        $draft = new TextDraft('中文');

        self::assertSame(TextChange::Caret, $draft->moveCaret(1));
        self::assertSame(0, $draft->caretOffset());
    }

    public function testMoveToSamePositionIsNone(): void
    {
        $draft = new TextDraft('abc');
        $draft->moveCaret(3);

        // ★ 同位置 ⇒ None（不重画、不通知）。报 Caret 会让每帧都标脏。
        self::assertSame(TextChange::None, $draft->moveCaret(3));
    }

    public function testSelectAllIsIdempotent(): void
    {
        $draft = new TextDraft('abc');
        $draft->selectAll();

        self::assertSame('abc', $draft->selectedText());
        self::assertSame(TextChange::None, $draft->selectAll());
    }

    /** 有选区时键入 ⇒ 整段替换（不是"在插入符处再插一段"） */
    public function testInsertReplacesSelection(): void
    {
        $draft = new TextDraft('abcd');
        $draft->moveCaret(0);
        $draft->extendSelectionTo(2);
        $draft->insert('XY');

        self::assertSame('XYcd', $draft->text());
        self::assertFalse($draft->hasSelection());
    }

    // ── 按词 / 段选（双击 / 三击的手感来源）────────────────────────────

    /**
     * 活动端落在**选区末尾**。
     *
     * ★ 这条不报"选了什么"，报的是"接下来按 Shift+→ 从哪走"：活动端若在词首，
     *   扩选会从词首往右重选，用户看到的是"选区缩回去再长出来"。
     */
    public function testSelectWordLeavesActiveEndAtSelectionEnd(): void
    {
        $draft = new TextDraft('hello world');
        $draft->moveCaret(7);

        self::assertSame(TextChange::Caret, $draft->selectWordAt(7));
        self::assertSame('world', $draft->selectedText());
        self::assertSame(11, $draft->caretOffset(), '插入符（活动端）在选区末尾');
    }

    /** 同一处再选一次 ⇒ `None`（不重画）；否则双击连击的每一下都会标脏 */
    public function testSelectWordIsIdempotent(): void
    {
        $draft = new TextDraft('hello world');
        $draft->selectWordAt(7);

        self::assertSame(TextChange::None, $draft->selectWordAt(7));
    }

    /** 段 = 整串（单行编辑器），与 `selectAll()` 同结果但走的是另一条路 */
    public function testSelectParagraphCoversWholeSingleLine(): void
    {
        $draft = new TextDraft('用户 1');
        $draft->selectParagraphAt(3);

        self::assertSame('用户 1', $draft->selectedText());
    }

    /**
     * `selectRange` 的两个端点都吸附到字符边界，且**顺序反了要换过来**。
     *
     * ★ 不换的话 `selectionStart() > selectionEnd()`：高亮宽度为负，画面上是
     *   "什么都没选中"，而 `selectedText()` 返回空串 —— 两处都不知道自己错了。
     */
    public function testSelectRangeSnapsAndOrdersEndpoints(): void
    {
        $draft = new TextDraft('中文abc');
        // 0..9 反向给；1 落在"中"的字节中间 ⇒ 吸附到 0
        $draft->selectRange(9, 1);

        self::assertSame('中文abc', $draft->selectedText());
        self::assertSame(0, $draft->selectionStart());
        self::assertSame(9, $draft->selectionEnd());
    }

    /**
     * ★ 空区间 = 折叠（等于只挪插入符），不报错：空文本里双击、双击在边界上
     *   都会走到这里。若这里抛异常，双击空白格会崩在真机上。
     */
    public function testSelectRangeOfEmptySpanCollapses(): void
    {
        $draft = new TextDraft('');
        $draft->selectWordAt(0);

        self::assertFalse($draft->hasSelection());
        self::assertSame(0, $draft->caretOffset());
    }
}
