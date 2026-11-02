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

    /**
     * `Ctrl+←/→` 按**词**跳（不是按字符）。
     *
     * ★ 这条与 `testLeftAndRightWalkCodePointBoundaries` 的区别只有粒度，
     *   但两者必须分开断言：把 `Ctrl+←` 接成 `previousBoundary()`（字符级）
     *   时，"按一下跨过一个词"的全绿测试看不出任何异常 —— 只是手感变成
     *   "按五次才跨过 `hello`"。
     */
    public function testCtrlLeftAndRightSkipWords(): void
    {
        $draft = self::atEnd('hello world');

        self::assertSame(TextChange::Caret, TextKeymap::apply($draft, KeyEvent::down(KeyChord::ctrl('Left'))));
        self::assertSame(6, $draft->caretOffset(), 'Ctrl+← 应跨过整个 world');

        TextKeymap::apply($draft, KeyEvent::down(KeyChord::ctrl('Left')));
        self::assertSame(0, $draft->caretOffset());

        TextKeymap::apply($draft, KeyEvent::down(KeyChord::ctrl('Right')));
        self::assertSame(6, $draft->caretOffset(), 'Ctrl+→ 应停在下一个词首');

        // 已在末尾 ⇒ 管了但没动（None，不是 null）
        $draft->moveCaretToEnd();
        self::assertSame(
            TextChange::None,
            TextKeymap::apply($draft, KeyEvent::down(KeyChord::ctrl('Right'))),
            '末尾的 Ctrl+→ 要消费掉按键（否则会漏给加速键）',
        );
    }

    /**
     * `Ctrl+Shift+←/→` 按词**扩选**（活动端跳词，锚点不动）。
     *
     * ★ 断言落在 `[selectionStart, selectionEnd)` 上而不是"有没有选区"：
     *   接成字符级扩选时选区照样存在，只有端点会差几个字节。
     */
    public function testCtrlShiftLeftAndRightExtendByWord(): void
    {
        $draft = self::atEnd('hello world');

        TextKeymap::apply($draft, KeyEvent::down(KeyChord::make('Left', ctrl: true, shift: true)));
        self::assertSame(6, $draft->selectionStart());
        self::assertSame(11, $draft->selectionEnd());

        TextKeymap::apply($draft, KeyEvent::down(KeyChord::make('Left', ctrl: true, shift: true)));
        self::assertSame(0, $draft->selectionStart());
        self::assertSame(11, $draft->selectionEnd());

        TextKeymap::apply($draft, KeyEvent::down(KeyChord::make('Right', ctrl: true, shift: true)));
        self::assertSame(6, $draft->selectionStart(), '往右扩要缩回 6（活动端在 0 那侧）');
        self::assertSame(11, $draft->selectionEnd());
    }

    /**
     * `Ctrl+Backspace` / `Ctrl+Delete` 按**词**删。
     *
     * ★ 两端落点不同，是这一族里唯一"看起来像写错"的地方，所以逐字节断言：
     *   `hello world` 末尾按 Ctrl+Backspace ⇒ 停在 6（词首，空格留着）；
     *   0 处按 Ctrl+Delete ⇒ 停在 5（词尾，**不是** 6）。
     *   若把 Ctrl+Delete 接成 `nextWordStart()`，0 ⇒ 6 只会删掉 `hello` 加一个
     *   空格，`world` 还在 —— 而这恰恰是"看起来也没错"的那种实现。
     */
    public function testCtrlBackspaceAndDeleteRemoveWords(): void
    {
        $draft = self::atEnd('hello world');

        self::assertSame(
            TextChange::Text,
            TextKeymap::apply($draft, KeyEvent::down(KeyChord::ctrl('Backspace'))),
        );
        self::assertSame('hello ', $draft->text(), 'Ctrl+Backspace 删到词首，不吞前面的空格');
        self::assertSame(6, $draft->caretOffset());

        // 再按一次 ⇒ 删掉 `hello `（本次含前面的词，落在 0）
        TextKeymap::apply($draft, KeyEvent::down(KeyChord::ctrl('Backspace')));
        self::assertSame('', $draft->text());

        // 向前的镜像：从 0 开始，一次删掉整个 hello
        $draft = self::atEnd('hello world');
        $draft->moveCaret(0);

        self::assertSame(
            TextChange::Text,
            TextKeymap::apply($draft, KeyEvent::down(KeyChord::ctrl('Delete'))),
        );
        self::assertSame(' world', $draft->text(), 'Ctrl+Delete 删到词尾（5），不是词首（6）');
        self::assertSame(0, $draft->caretOffset());

        // 末尾的 Ctrl+Delete ⇒ 管了但没动
        $draft->moveCaretToEnd();
        self::assertSame(
            TextChange::None,
            TextKeymap::apply($draft, KeyEvent::down(KeyChord::ctrl('Delete'))),
            '末尾的 Ctrl+Delete 也要消费掉按键（否则会漏给加速键）',
        );
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
