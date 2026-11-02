<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Input;

use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\TextKeymap;
use Drift\Text\TextChange;
use Drift\Text\TextDraft;
use PHPUnit\Framework\TestCase;

/**
 * 编辑键 → 草稿动作的那张表。
 *
 * ★ 这 12 条映射此前在 `TextFieldState` 与 `TableState` 各写了一遍，而**两处
 *   都没有测试**：`TextFieldTest` 里一个 `KeyEvent` 都没有，表格的 `KeyboardTest`
 *   只覆盖非编辑态（Tab / 方向移光标），`EditTest` 只覆盖"输入文本 + Enter 提交"。
 *   于是 `Backspace` / `Home` / `End` / `Shift+方向` / `Ctrl+A` 在两个控件上都是
 *   零覆盖 —— 收口成一张表之后，这里一条用例同时守住两个控件。
 *
 * ★ 另两组断言是这个接口的**形状**，不是行为：
 *   · 不归编辑管的键返回 `null`（调用方必须放行，不能吞）
 *   · 管了但没改动返回 `TextChange::None`（调用方要消费，只是不必重画）
 *   合成一个 `bool` 会让这两件事再也分不开。
 */
final class TextKeymapTest extends TestCase
{
    /** 插入符落在末尾的草稿（方向键 / 退格才有东西可动） */
    private static function atEnd(string $text): TextDraft
    {
        $draft = new TextDraft($text);
        $draft->moveCaretToEnd();

        return $draft;
    }

    public function testBackspaceDeletesOneCodePoint(): void
    {
        $draft = self::atEnd('中文');

        self::assertSame(TextChange::Text, TextKeymap::apply($draft, KeyEvent::down(KeyChord::backspace())));
        self::assertSame('中', $draft->text());
    }

    public function testDeleteRemovesForward(): void
    {
        $draft = new TextDraft('abc');
        $draft->moveCaret(0);

        self::assertSame(TextChange::Text, TextKeymap::apply($draft, KeyEvent::down(KeyChord::delete())));
        self::assertSame('bc', $draft->text());
    }

    public function testLeftAndRightWalkCodePointBoundaries(): void
    {
        $draft = self::atEnd('中文');

        TextKeymap::apply($draft, KeyEvent::down(KeyChord::left()));
        self::assertSame(\strlen('中'), $draft->caretOffset());

        TextKeymap::apply($draft, KeyEvent::down(KeyChord::right()));
        self::assertSame(\strlen('中文'), $draft->caretOffset());
    }

    public function testHomeAndEnd(): void
    {
        $draft = self::atEnd('abc');

        TextKeymap::apply($draft, KeyEvent::down(KeyChord::home()));
        self::assertSame(0, $draft->caretOffset());

        TextKeymap::apply($draft, KeyEvent::down(KeyChord::end()));
        self::assertSame(3, $draft->caretOffset());
    }

    public function testCtrlASelectsAll(): void
    {
        $draft = self::atEnd('abc');

        TextKeymap::apply($draft, KeyEvent::down(KeyChord::ctrl('A')));

        self::assertTrue($draft->hasSelection());
        self::assertSame(0, $draft->selectionStart());
        self::assertSame(3, $draft->selectionEnd());
    }

    public function testShiftLeftAndRightExtendSelection(): void
    {
        $draft = self::atEnd('abc');

        TextKeymap::apply($draft, KeyEvent::down(KeyChord::shift('Left')));
        self::assertSame(2, $draft->selectionStart());
        self::assertSame(3, $draft->selectionEnd());

        TextKeymap::apply($draft, KeyEvent::down(KeyChord::shift('Right')));
        self::assertSame(3, $draft->selectionStart());
        self::assertSame(3, $draft->selectionEnd());
    }

    public function testShiftHomeAndEndExtendToEdges(): void
    {
        $draft = self::atEnd('abcd');

        TextKeymap::apply($draft, KeyEvent::down(KeyChord::shift('Home')));
        self::assertSame(0, $draft->selectionStart());
        self::assertSame(4, $draft->selectionEnd());

        $draft->clearSelection();
        $draft->moveCaret(0);

        TextKeymap::apply($draft, KeyEvent::down(KeyChord::shift('End')));
        self::assertSame(0, $draft->selectionStart());
        self::assertSame(4, $draft->selectionEnd());
    }

    public function testTextIsInsertedAtCaret(): void
    {
        $draft = self::atEnd('ab');

        self::assertSame(TextChange::Text, TextKeymap::apply($draft, KeyEvent::character('井')));
        self::assertSame('ab井', $draft->text());
    }

    /** 有选区时插入文本 ⇒ 整段替换（不是"先删再插"两次通知） */
    public function testTextReplacesSelection(): void
    {
        $draft = self::atEnd('abcd');
        TextKeymap::apply($draft, KeyEvent::down(KeyChord::ctrl('A')));

        TextKeymap::apply($draft, KeyEvent::character('x'));

        self::assertSame('x', $draft->text());
    }

    /**
     * 收口键（`Enter` / `Tab` / `F2` / `Esc` / 上下移 / 加速键）**不归本表管**。
     *
     * ★ 返回 `null` 而不是 `TextChange::None`：调用方要据此**放行**这次按键，
     *   而 `None` 表示"我管了，只是没改动"（那要消费掉）。混起来就会出现
     *   "编辑中按 Ctrl+S 再也存不了盘"。
     */
    public function testClosingKeysAreNotConsumed(): void
    {
        $draft = self::atEnd('abc');

        $keys = [
            KeyChord::enter(),
            KeyChord::tab(),
            KeyChord::escape(),
            KeyChord::f(2),
            KeyChord::up(),
            KeyChord::down(),
            KeyChord::ctrl('S'),
        ];
        foreach ($keys as $chord) {
            self::assertNull(TextKeymap::apply($draft, KeyEvent::down($chord)), $chord->label() . ' 不该被本表消费');
        }

        self::assertSame('abc', $draft->text());
    }

    /**
     * 管了但没动 ⇒ `TextChange::None`（**不是** `null`）。
     *
     * ★ 空文本按退格、插入符已在行首再按 Home 都是这种情形：按键必须被消费
     *   （否则会漏给上层去触发加速键），但不需要重画。
     */
    public function testNoOpStillConsumesTheKey(): void
    {
        $draft = new TextDraft('');

        self::assertSame(TextChange::None, TextKeymap::apply($draft, KeyEvent::down(KeyChord::backspace())));
        self::assertSame(TextChange::None, TextKeymap::apply($draft, KeyEvent::down(KeyChord::home())));
    }
}
