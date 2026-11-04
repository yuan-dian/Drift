<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Widget\TextField;
use Drift\Widget\TextFieldState;

/**
 * 输入框的**键位路由**（`handleKey`）。
 *
 * ★ 这个入口此前零覆盖：`TextFieldTest` 只直接调 `insert()` / `deleteBackward()` /
 *   移动插入符这些方法，从没按过一个键。于是把 `handleKey` 里那张键位表整个拆掉
 *   （改成永远返回 `false`），**全部测试仍然绿** —— 编辑键的接线是谁也验不到的。
 *   编辑键的映射本身由 `tests/Unit/Input/TextKeymapTest.php` 守；这里只守
 *   "本控件确实走了那张表，且自己的收口规则没被吃掉"。
 *
 * ★ 两条语义必须分开断言（`TextKeymap` 的返回值形状就是为它们设计的）：
 *   · 不归编辑管 ⇒ 返回 `false` ⇒ 上层（加速键 / activateFocused）还能看到这个键
 *   · 管了但没改动 ⇒ 返回 `true` ⇒ 消费掉，不漏给上层
 */
final class TextFieldKeyTest extends SmokeTestCase
{
    /** 挂一块输入框，返回 [state, onChanged 收到的值序列] */
    private function field(string $initial, array &$seen): TextFieldState
    {
        $root = TextField::make()->value($initial)->onChanged(function (string $v) use (&$seen): void {
            $seen[] = $v;
        })->createElement();
        $root->mount(null, smokeContext());

        $state = $root->state();
        self::assertInstanceOf(TextFieldState::class, $state);

        return $state;
    }

    public function testEditingKeysReachTheDraft(): void
    {
        $seen = [];
        $state = $this->field('ab', $seen);

        check('输入框 · 文本键在插入符处插入', $state->handleKey(KeyEvent::character('X')) && $state->text() === 'abX');
        check('输入框 · 退格删掉一个码位', $state->handleKey(KeyEvent::down(KeyChord::backspace())) && $state->text() === 'ab');
        check('输入框 · 方向键移插入符', $state->handleKey(KeyEvent::down(KeyChord::left())) && $state->caretOffset() === 1);
        check('输入框 · Delete 删后一个码位', $state->handleKey(KeyEvent::down(KeyChord::delete())) && $state->text() === 'a');
        check('输入框 · End 到末尾', $state->handleKey(KeyEvent::down(KeyChord::end())) && $state->caretOffset() === 1);
        check('输入框 · Home 到行首', $state->handleKey(KeyEvent::down(KeyChord::home())) && $state->caretOffset() === 0);
        check('输入框 · Ctrl+A 全选', $state->handleKey(KeyEvent::down(KeyChord::ctrl('A'))) && $state->hasSelection());

        // Shift+方向从**行尾**往前扩展（Ctrl+A 之后活动端在行首，再 shift+left 是塌缩）
        $state->handleKey(KeyEvent::down(KeyChord::end()));
        check(
            '输入框 · Shift+Left 扩展选区（只挪活动端）',
            $state->handleKey(KeyEvent::down(KeyChord::shift('Left'))) && $state->selectionStart() === 0 && $state->selectionEnd() === 1,
        );

        // 有选区时打字 ⇒ 整段替换
        $state->handleKey(KeyEvent::character('Z'));
        check('输入框 · 有选区时打字整段替换', $state->text() === 'Z', '实际 ' . $state->text());

        $this->flushChecks();
    }

    public function testOnChangedOnlyFiresWhenTextChanges(): void
    {
        $seen = [];
        $state = $this->field('ab', $seen);

        $state->handleKey(KeyEvent::down(KeyChord::left()));    // 只挪插入符
        $state->handleKey(KeyEvent::down(KeyChord::home()));    // 已在行首，无变化
        check('输入框 · 只挪插入符不通知 onChanged', $seen === [], '实际 ' . json_encode($seen));

        $state->handleKey(KeyEvent::character('Q'));
        check('输入框 · 文本变了才通知', $seen === ['Qab'], '实际 ' . json_encode($seen));

        $this->flushChecks();
    }

    /**
     * `Esc` 是**输入框自己的收口**，不在 `TextKeymap` 里（表格的 `Esc` 是取消编辑）。
     *
     * ★ 无选区时**必须放行**：吞掉它会让 `Application` 的"取消/关闭"再也收不到
     *   `Esc`（模态、菜单全靠它）。
     */
    public function testEscapeClearsSelectionButOtherwisePassesThrough(): void
    {
        $seen = [];
        $state = $this->field('abc', $seen);
        $esc = KeyEvent::down(KeyChord::escape());

        check('输入框 · 无选区时 Esc 放行（不改状态、不消费）', $state->handleKey($esc) === false && !$state->hasSelection());

        $state->handleKey(KeyEvent::down(KeyChord::ctrl('A')));
        check('输入框 · 有选区时 Esc 被消费', $state->handleKey($esc) === true);
        check('输入框 · 有选区时 Esc 清掉选区（文本不动）', !$state->hasSelection() && $state->text() === 'abc');

        $this->flushChecks();
    }

    /**
     * `Ctrl+←/→` 与 `Ctrl+Shift+←/→` 在**真实控件**上也要通到草稿。
     *
     * ★ 表的映射由 `TextKeymapTest` 守，这里守的是"控件没在进表之前把它拦掉"
     *   —— `TextFieldState::handleKey()` 在调 `TextKeymap::apply()` 之前有一小段
     *   自己的判断（`Esc` 那一处），任何"提前 return false"都会让这一族键
     *   静默失效，而单元测试看不见。
     */
    public function testWordKeysReachTheDraft(): void
    {
        $seen = [];
        $state = $this->field('hello world', $seen);

        check(
            '输入框 · Ctrl+← 跨过一个词',
            $state->handleKey(KeyEvent::down(KeyChord::ctrl('Left'))) && $state->caretOffset() === 6,
            '插入符在 ' . $state->caretOffset(),
        );

        check(
            '输入框 · Ctrl+Shift+← 按词扩选',
            $state->handleKey(KeyEvent::down(KeyChord::make('Left', ctrl: true, shift: true)))
                && $state->selectionStart() === 0
                && $state->selectionEnd() === 6,
            \sprintf('[%d,%d)', $state->selectionStart(), $state->selectionEnd()),
        );

        $state->handleKey(KeyEvent::down(KeyChord::make('Right', ctrl: true, shift: true)));
        check(
            '输入框 · Ctrl+Shift+→ 把活动端收回来',
            $state->selectionStart() === 6 && $state->selectionEnd() === 6,
            \sprintf('[%d,%d)', $state->selectionStart(), $state->selectionEnd()),
        );

        $this->flushChecks();
    }

    /**
     * `Ctrl+Backspace` / `Ctrl+Delete` 在**真实控件**上落到草稿。
     *
     * ★ 除了文本，还钉 `$seen`：删除必须走一次 `TextChange::Text` ⇒ `onChanged`
     *   只报**一次**最终值。若哪天有人把它改成"逐字符调 `deleteBackward()`"，
     *   文本最终一样，而应用会收到一串中间值 —— 外部看到的是"值一直在变"。
     */
    public function testWordDeleteKeysReachTheDraft(): void
    {
        $seen = [];
        $state = $this->field('hello world', $seen);

        check(
            '输入框 · Ctrl+Backspace 删到词首（空格留着）',
            $state->handleKey(KeyEvent::down(KeyChord::ctrl('Backspace')))
                && $state->text() === 'hello '
                && $state->caretOffset() === 6,
            \sprintf('%s @ %d', \json_encode($state->text()), $state->caretOffset()),
        );
        check('输入框 · 一次按键只通知一次外部', $seen === ['hello '], \json_encode($seen));

        // ★ 选区**故意与词边界不重合**（`[0,10)`）：若选区是"全选"，走选区与
        //   "按词删到 0"的结果一样，这条断言就抓不到"删除不看选区"这个错。
        //   这里走选区得 `d`，走词边界（`previousWordStart(10)` = 6）得 `hello d`。
        $state = $this->field('hello world', $seen);
        $state->handleKey(KeyEvent::down(KeyChord::ctrl('A')));
        $state->handleKey(KeyEvent::down(KeyChord::shift('Left')));
        check(
            '输入框 · 有选区时 Ctrl+Backspace 只删选区（不按词边界）',
            $state->handleKey(KeyEvent::down(KeyChord::ctrl('Backspace')))
                && $state->text() === 'd',
            \json_encode($state->text()),
        );

        $seen = [];
        $state = $this->field('hello world', $seen);
        $state->handleKey(KeyEvent::down(KeyChord::home()));

        check(
            '输入框 · Ctrl+Delete 删到词尾（不是词首）',
            $state->handleKey(KeyEvent::down(KeyChord::ctrl('Delete'))) && $state->text() === ' world',
            \json_encode($state->text()),
        );

        $state->handleKey(KeyEvent::down(KeyChord::end()));
        check(
            '输入框 · 末尾的 Ctrl+Delete 被消费但不报变化',
            $state->handleKey(KeyEvent::down(KeyChord::ctrl('Delete'))) === true
                && $state->text() === ' world'
                && $seen === [' world'],
            \json_encode($seen),
        );

        $this->flushChecks();
    }

    /**
     * `Enter` 提交：**有消费者才认领**。
     *
     * ★ 三条成对验：接了 handler 必须吃掉（否则上层还可能开浮层）；没接必须放行
     *   （`ComboBox` 的"Enter 开面板"走的就是放行那条路）；组合键一律归应用。
     */
    public function testEnterSubmitsOnlyWhenHandlerIsAttached(): void
    {
        $submitted = [];
        $root = TextField::make()
            ->value('ab')
            ->onSubmitted(function (string $v) use (&$submitted): void {
                $submitted[] = $v;
            })
            ->createElement();
        $root->mount(null, smokeContext());

        $state = $root->state();
        self::assertInstanceOf(TextFieldState::class, $state);

        // 先敲一个字：提交拿到的是**草稿**（受控值还没回写也一样）
        $state->handleKey(KeyEvent::character('X'));
        check('输入框 · Enter 提交当前文本（含未回写的那笔）', $state->handleKey(KeyEvent::down(KeyChord::enter())) && $submitted === ['abX'], \json_encode($submitted));
        check('输入框 · Enter 不插换行（单行框没有这个本职）', $state->text() === 'abX', \json_encode($state->text()));

        $submitted = [];
        $modifiers = [
            'Ctrl+Enter' => KeyChord::ctrl('Enter'),
            'Alt+Enter' => KeyChord::alt('Enter'),
            'Shift+Enter' => KeyChord::shift('Enter'),
        ];
        foreach ($modifiers as $label => $chord) {
            check(
                '输入框 · ' . $label . ' 归应用（不提交、不消费）',
                $state->handleKey(KeyEvent::down($chord)) === false && $submitted === [],
                \json_encode($submitted),
            );
        }

        $plain = TextField::make()->value('ab')->createElement();
        $plain->mount(null, smokeContext());
        $plainState = $plain->state();
        self::assertInstanceOf(TextFieldState::class, $plainState);
        check('输入框 · 没接 onSubmitted ⇒ Enter 放行给上层', $plainState->handleKey(KeyEvent::down(KeyChord::enter())) === false);

        $this->flushChecks();
    }

    /**
     * 不归编辑管的键必须**原样放行**。
     *
     * ★ `Ctrl+S` 在编辑中也要能存盘 —— 吞掉它等于"打字时保存失效"，且不报错。
     */
    public function testUnrelatedKeysPassThrough(): void
    {
        $seen = [];
        $state = $this->field('abc', $seen);

        $keys = [KeyChord::tab(), KeyChord::f(5), KeyChord::ctrl('S'), KeyChord::up(), KeyChord::down()];
        foreach ($keys as $chord) {
            check(
                '输入框 · ' . $chord->label() . ' 放行给上层',
                $state->handleKey(KeyEvent::down($chord)) === false,
            );
        }

        check('输入框 · 放行的键不改文本', $state->text() === 'abc');

        $this->flushChecks();
    }
}
