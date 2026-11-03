<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\App\FocusManager;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Widget\TextField;
use Drift\Widget\TextFieldState;

/**
 * 输入框的撤销 / 重做**接线**（表本身由 `TextKeymapTest` 守）。
 *
 * ★ 分两层测的理由与编辑键那时相同：`TextKeymap` 返回 `TextChange` 之后，
 *   还要经过 `TextFieldState::settle()` 才会 `markDirty()` + 通知 `onChanged`。
 *   映射对了而接线断了的现象是"文本在内存里回退了，但界面不动" ——
 *   在没有这层判据时不会红。
 *
 * ★ 另守一条**路由**判据：`Ctrl+Z` 必须被输入框声明独占。否则加速键在焦点之前
 *   就先把它吃掉了，`handleKey` 永远收不到 —— 而这时所有单元测试照样全绿
 *   （它们直接造 `KeyEvent` 喂 `handleKey`，绕过了加速键那一环）。
 */
final class TextFieldUndoKeyTest extends SmokeTestCase
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

    public function testUndoAndRedoThroughHandleKey(): void
    {
        $seen = [];
        $state = $this->field('', $seen);

        $state->handleKey(KeyEvent::character('a'));
        $state->handleKey(KeyEvent::character('b'));
        check('撤销 · 连续输入后文本是 ab', $state->text() === 'ab', '实际 ' . $state->text());

        check(
            '撤销 · Ctrl+Z 一步撤掉整串连续输入',
            $state->handleKey(KeyEvent::down(KeyChord::ctrl('Z'))) && $state->text() === '',
            '实际 ' . $state->text(),
        );
        check('撤销 · 回退要通知 onChanged', $seen === ['a', 'ab', ''], '实际 ' . json_encode($seen, JSON_UNESCAPED_UNICODE));

        check(
            '撤销 · Ctrl+Y 重做回来（不是又撤一步）',
            $state->handleKey(KeyEvent::down(KeyChord::ctrl('Y'))) && $state->text() === 'ab',
            '实际 ' . $state->text(),
        );
        check('撤销 · 重做也要通知', end($seen) === 'ab', '实际 ' . json_encode(end($seen), JSON_UNESCAPED_UNICODE));

        // 栈空之后按键仍要被**消费**（返回 true），只是不改文本
        $state->handleKey(KeyEvent::down(KeyChord::ctrl('Z')));
        check(
            '撤销 · 栈空时 Ctrl+Z 仍被输入框消费（不穿透给加速键）',
            $state->handleKey(KeyEvent::down(KeyChord::ctrl('Z'))) === true && $state->text() === '',
            '实际 ' . $state->text(),
        );

        $this->flushChecks();
    }

    /**
     * `Ctrl+Z` / `Ctrl+Y` / `Ctrl+Shift+Z` 必须被输入框**声明独占**。
     *
     * ★ 这条不测"能撤销"，测的是"撤销的键能到达输入框"。少了它，加速键会先吃掉
     *   这些键，而上面的用例（直接喂 `handleKey`）**全部仍然绿**。
     */
    public function testUndoKeysAreClaimedExclusively(): void
    {
        $root = TextField::make()->value('')->createElement();
        $root->mount(null, smokeContext());

        $fm = new FocusManager();
        $fm->collect([$root]);
        $fm->focusNext();

        check('撤销 · 输入框独占 Ctrl+Z', $fm->claims(KeyChord::ctrl('Z')));
        check('撤销 · 输入框独占 Ctrl+Y', $fm->claims(KeyChord::ctrl('Y')));
        check(
            '撤销 · 输入框独占 Ctrl+Shift+Z',
            $fm->claims(KeyChord::make('Z', ctrl: true, shift: true)),
        );
        // 反例：不相干的组合不许一起吞掉（否则加速键表会被意外挡掉一片）
        check(
            '撤销 · 没顺手吞掉 Ctrl+Shift+A 之类',
            !$fm->claims(KeyChord::make('A', ctrl: true, shift: true)) && !$fm->claims(KeyChord::ctrl('R')),
        );

        $this->flushChecks();
    }
}
