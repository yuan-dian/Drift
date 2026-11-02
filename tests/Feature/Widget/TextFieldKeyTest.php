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
     * 不归编辑管的键必须**原样放行**。
     *
     * ★ `Ctrl+S` 在编辑中也要能存盘 —— 吞掉它等于"打字时保存失效"，且不报错。
     */
    public function testUnrelatedKeysPassThrough(): void
    {
        $seen = [];
        $state = $this->field('abc', $seen);

        $keys = [KeyChord::enter(), KeyChord::tab(), KeyChord::f(5), KeyChord::ctrl('S'), KeyChord::up(), KeyChord::down()];
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
