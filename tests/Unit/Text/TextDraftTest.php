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
}
