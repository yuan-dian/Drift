<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

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
 * 按键路由优先级：加速键（第 ① 环）vs 焦点链 vs 控件独占（§9.1）。
 *
 * ## 三个环的先后顺序就是全部判据
 *
 * 1. 加速键表先查 —— 命中 ⇒ 命令走全链，**不再往下**；
 * 2. 未绑定 ⇒ 落到焦点控件；
 * 3. 控件用 `claims()` 声明独占 ⇒ **反过来**让位（编辑命令优先于全局快捷键）。
 *
 * 每一条都成对验"该开火的开了 / 该拦的没漏"，只有一半会读不出优先级是否真生效。
 * （`Ctrl+C`/`Ctrl+V` 的门控回落属编辑命令环，见 `EditingCommandLoopTest`。）
 */
final class KeyRoutingPriorityTest extends SmokeTestCase
{
    public function testAcceleratorHitsBeforeFocusChain(): void
    {
        $accelApp = new AccelApplication();
        $accelApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
        $accelApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('R')));
        check(
            '加速键 · Ctrl+R ⇒ 命令走全链（第 ① 环命中，焦点之前）',
            $accelApp->seen === ['accel.fire'],
        );

        $accelApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
        check(
            '加速键 · 未绑定的键不走第 ① 环（Tab 仍归焦点链，seen 不变）',
            $accelApp->seen === ['accel.fire'],
        );

        // 让位规则 ②：焦点控件声明独占 ⇒ 不吃快捷键
        $claimApp = new AccelApplication();
        $claimApp->withClaimer = true;
        $claimApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
        $claimApp->dispatchInput(KeyEvent::down(KeyChord::tab())); // 焦点落到 TextField（文档序第一个可聚焦）
        check(
            '加速键 · 焦点控件声明独占 Ctrl+A ⇒ 让位给输入框（seen 为空）',
            $claimApp->seen === [],
        );

        $claimApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('R')));
        check(
            '加速键 · 未独占的组合照常命中（Ctrl+R 仍走第 ① 环）',
            $claimApp->seen === ['accel.fire'],
        );

        $this->flushChecks();
    }

    /**
     * `TextField` 构造时自带 `claimKeys([ctrl('A')])` —— 让位规则 ② 的第一个**生产消费者**。
     *
     * 夹具里那句 `claimKeys()` 只是把同一机制摆在眼前；真正要守的是"不带夹具开关也照样
     * 让位，且键真的落到 `TextFieldState::handleKey()`（全选发生）"。
     */
    public function testTextFieldClaimsSelectAllByConstruction(): void
    {
        $prodApp = new AccelApplication();
        $prodApp->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
        $prodApp->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $prodApp->dispatchInput(KeyEvent::down(KeyChord::ctrl('A')));
        $prodEl = findElement($prodApp->rootElement(), fn(Element $el) => $el->widget() instanceof TextField);
        $prodState = $prodEl instanceof StatefulElement ? $prodEl->state() : null;
        check(
            '让位规则② · TextField 自带 Ctrl+A 独占（让位给输入框，且全选真的发生）',
            $prodApp->seen === [] && $prodState instanceof TextFieldState
                && $prodState->selectionStart() === 0 && $prodState->selectionEnd() === 3,
            'seen=[' . implode(',', $prodApp->seen) . '] sel='
                . ($prodState?->selectionStart() ?? -1) . '..' . ($prodState?->selectionEnd() ?? -1),
        );

        $this->flushChecks();
    }
}
