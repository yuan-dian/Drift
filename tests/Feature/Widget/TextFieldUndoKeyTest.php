<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\App\FocusManager;
use Drift\Dev\Smoke\App\AccelApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Widget\Element;
use Drift\Widget\StatefulElement;
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
 * ★ 另守一条**路由**判据：撤销键必须到得了输入框。2026-11-03 之前靠
 *   `claimKeys()` 声明独占，现在靠**顺序**（焦点链先于加速键）—— 判据也从
 *   "声明表里有它"换成"加速键表里明明绑了它也收不到"。
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
     * 撤销键必须**到得了输入框** —— 即使应用把同一个组合绑成了加速键。
     *
     * ★ 这条不测"能撤销"，测的是"撤销的键能到达输入框"。少了它，加速键会先吃掉
     *   这些键，而上面的用例（直接喂 `handleKey`）**全部仍然绿**。
     *
     * ★ 2026-11-03 改法：此前测 `FocusManager::claims()`（声明式白名单）。白名单删了
     *   （它列不全、也表达不了"只在编辑时抢"）⇒ 判据换成**行为**：加速键表里明明
     *   绑了 `Ctrl+Z`，焦点在输入框时它必须收不到。这比"声明表里有它"强 ——
     *   后者在键被别处吃掉时照样为真。
     */
    public function testUndoKeysReachTheFieldBeforeAccelerators(): void
    {
        $app = new AccelApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));

        // 焦点进输入框（字段初值 abc），再敲一个字符让撤销有东西可撤
        $app->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $app->dispatchInput(KeyEvent::character('x'));

        $state = null;
        $el = findElement($app->rootElement(), static fn(Element $e): bool => $e->widget() instanceof TextField);
        $state = $el instanceof StatefulElement ? $el->state() : null;
        check('撤销路由 · 前置：焦点进输入框且打进了 x', $state instanceof TextFieldState && $state->text() === 'abcx',
            $state instanceof TextFieldState ? $state->text() : 'n/a');

        // 加速键表里有 Ctrl+Z，但它必须**让位**给输入框
        $app->dispatchInput(KeyEvent::down(KeyChord::ctrl('Z')));
        check(
            '撤销路由 · Ctrl+Z 归输入框（文本回退、accel.undo 没触发）',
            $state instanceof TextFieldState && $state->text() === 'abc' && $app->seen === [],
            ($state instanceof TextFieldState ? $state->text() : 'n/a') . ' seen=[' . implode(',', $app->seen) . ']',
        );

        // 栈空时也不能穿透：键仍归编辑环（`TextChange::None` 而非 `null`）
        $app->dispatchInput(KeyEvent::down(KeyChord::ctrl('Z')));
        $app->dispatchInput(KeyEvent::down(KeyChord::ctrl('Z')));
        check(
            '撤销路由 · 栈空时 Ctrl+Z 仍不穿透给加速键',
            $state instanceof TextFieldState && $state->text() === 'abc' && $app->seen === [],
            ($state instanceof TextFieldState ? $state->text() : 'n/a') . ' seen=[' . implode(',', $app->seen) . ']',
        );

        // 反例：输入框认不得的组合照常落给加速键（焦点优先 ≠ 焦点通吃）
        $app->dispatchInput(KeyEvent::down(KeyChord::ctrl('R')));
        check(
            '撤销路由 · 输入框认不得的 Ctrl+R 仍走加速键',
            $app->seen === ['accel.fire'],
            'seen=[' . implode(',', $app->seen) . ']',
        );

        $this->flushChecks();
    }
}
