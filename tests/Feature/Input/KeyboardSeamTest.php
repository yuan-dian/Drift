<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

use Drift\Dev\Smoke\App\KeyAncestorApplication;
use Drift\Dev\Smoke\App\PressLabApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Widget\Button;
use Drift\Widget\Element;

/**
 * 「键盘归我」（`keyboard()`）缝：键**先给谁、给不到时落到哪**。
 *
 * ## 两条独立的路由规则
 *
 * ① **自己声明**：焦点控件自己声明了 `keyboard()` ⇒ 键先给它，返回 `true`
 *    吃掉、返回 `false` 让位给下游（激活 / `TextFieldState` 等）。
 * ② **沿父链**：焦点控件没声明，但**祖先**声明了 ⇒ 沿父链找最近的声明者。
 *    这条是 2026-10-24 补的：此前只读焦点控件自己的 widget ⇒ 包裹式控件
 *    （`ComboBox` 包 `TextField`）声明的 `keyboard()` 是**死的**，而
 *    `Widget::keyboard()` 的 docblock 一直写着"任何控件都能挂"。
 *
 * ★ 两条规则由两个宿主 app 分别承载：`PressLabApplication`（①，声明在自己身上）
 *   与 `KeyAncestorApplication`（②，声明在外层 `Column` 上、焦点落在里面的
 *   `TextField` 上）。同一个文件是因为它们是**同一条缝的两个分支** ——
 *   将来加 IME / 加修饰键优先级时，两边都要一起想。
 *
 * ## 判据为什么用 Backspace + `TextFieldState::text()`
 *
 * "键有没有被吃掉"没有返回值可看（`dispatchKey()` 不暴露结果）。可观测的差别
 * 只能是**副作用**：外层吃掉 Backspace ⇒ 文本不变；让位 ⇒ 文本真被删一个字符。
 * 这是真实差别，不是恒真断言。
 */
final class KeyboardSeamTest extends SmokeTestCase
{
    private const W = 320.0;

    private const H = 400.0;

    private static function openPress(): PressLabApplication
    {
        $app = new PressLabApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(self::W, self::H)));

        return $app;
    }

    private static function centerOf(?Element $el): array
    {
        $box = $el?->effectiveBox();
        if ($box === null || $box->size() === null) {
            return [0.0, 0.0];
        }
        [$x, $y] = boxOrigin($box);

        return [$x + $box->size()->width / 2.0, $y + $box->size()->height / 2.0];
    }

    private static function buttonWith(PressLabApplication $app, callable $predicate): ?Element
    {
        return findElement(
            $app->root(),
            static fn(Element $e): bool => $e->widget() instanceof Button && $predicate($e->widget())
        );
    }

    /** 声明了 `keyboard()` 的按钮（「键盘」） */
    private static function keyButton(PressLabApplication $app): ?Element
    {
        return self::buttonWith($app, static fn($w): bool => $w->keyHandlerValue() !== null);
    }

    /** 声明了 `ownsPress` 但**没有** `keyboard()` 的按钮（「接管」） */
    private static function ownButton(PressLabApplication $app): ?Element
    {
        return self::buttonWith(
            $app,
            static fn($w): bool => $w->pointerOwnsPressValue() && $w->keyHandlerValue() === null
        );
    }

    /** 点一下把焦点送上去（down 即激活，副作用由各用例自行扣除） */
    private static function click(PressLabApplication $app, ?Element $el): void
    {
        [$x, $y] = self::centerOf($el);
        $app->dispatchInput(PointerEvent::down($x, $y));
        $app->dispatchInput(PointerEvent::up($x, $y));
    }

    /**
     * 焦点控件声明了 `keyboard()` ⇒ **框架不认识的键**也归它（返回 true 吃掉）。
     *
     * ★ 用方向键而不是 Enter：Enter 框架自己认识（走激活），方向键不认识 ——
     *   这一条才证明"任意键都能被控件接走"，而不是"框架把已知键转发了"。
     */
    public function testKeyboardHandlerOnFocusedWidgetReceivesKey(): void
    {
        $app = self::openPress();
        self::click($app, self::keyButton($app));

        $app->dispatchInput(KeyEvent::down(KeyChord::right()));
        check(
            'Press · keyboard()：焦点控件收到方向键（框架不认识的键也能被控件吃掉）',
            $app->keyLog === ['Right'],
            'keyLog=' . \implode(',', $app->keyLog)
        );
    }

    /**
     * `keyboard()` 返回 `false` ⇒ 让位到下游：Enter 继续走激活，按钮真被按下。
     *
     * ★ 两条一起断言：只看 `keyLog` 会奖励"收到了但没让位"；只看 `keyPresses`
     *   会奖励"根本没调 handler"。`keyPresses` 用**增量**而不是绝对值 ——
     *   聚焦那一次点击已经给它 +1 了（`down` 即激活），写死 1 会把正确行为判成 bug。
     */
    public function testKeyboardReturningFalseFallsThroughToActivation(): void
    {
        $app = self::openPress();
        self::click($app, self::keyButton($app));

        $before = $app->keyPresses;
        $app->keyHandled = false;
        $app->dispatchInput(KeyEvent::down(KeyChord::enter()));
        check(
            'Press · keyboard() 返回 false ⇒ 让位到下游（Enter 仍激活按钮）',
            $app->keyLog === ['Enter'] && $app->keyPresses === $before + 1,
            'keyLog=' . \implode(',', $app->keyLog)
                . ' keyPresses=' . $before . '→' . $app->keyPresses
        );
    }

    /**
     * 反例：焦点在**没声明** `keyboard()` 的控件上 ⇒ 该键无人接，也不串台。
     *
     * ★ 没有这一条，实现写成"随便找一个声明者"（比如全局第一个）也能过前两条。
     *   独立 app 让基线是空的 ⇒ 直接断言 `keyLog === []`，比 smoke 里
     *   "累积后没变"更强（那边要求读者自己记住前面有几条）。
     */
    public function testFocusOnUndeclaredWidgetGetsNothing(): void
    {
        $app = self::openPress();
        self::click($app, self::ownButton($app));

        $app->dispatchInput(KeyEvent::down(KeyChord::right()));
        check(
            'Press · 反例：焦点在没声明 keyboard() 的控件上 ⇒ 该键无人接（不串台）',
            $app->keyLog === [],
            'keyLog=' . \implode(',', $app->keyLog)
        );
    }

    /**
     * `keyboard()` 沿**父链**：焦点在 `TextField` 上，外层 `Column` 的声明也收到键。
     *
     * 三条构成一条完整证据链：
     * ① 方向键（`TextFieldState` 不认）⇒ **只有**外层能收到 ⇒ 父链走得通；
     * ② 外层吃掉 Backspace ⇒ 文本不动 ⇒ 顺序是"外层先于焦点控件"；
     * ③ 外层让位 Backspace ⇒ 文本真被删一个字符 ⇒ 顺序没被改坏（`keyboard()`
     *    仍排在 `TextFieldState::handleKey()` 之前，但让位后能落下去）。
     *
     * ★ ③ 的期望值从 `caretOffset()` **现推**，不猜插入符在哪：点击落在输入框
     *   中部 ⇒ 插入符在 `'a'`/`'b'` 之间 ⇒ 删的是 `'a'`（结果 `'b'`）。写死 `'a'`
     *   会把正确行为判成 bug。
     * ★ 只有 ①② 会奖励"外层永远吃掉"的实现；只有 ③ 会奖励"外层根本不参与"。
     */
    public function testKeyboardWalksAncestorChain(): void
    {
        $app = new KeyAncestorApplication();
        $c = BoxConstraints::loose(new Size(300.0, 200.0));
        $app->attach(new SurfaceProbe(), $c);
        $app->frame(16.0, $c);

        // 点一下 ⇒ 焦点落到 TextField 上（命中测试 ⇒ 焦点）
        [$x, $y] = self::centerOf($app->fieldElement());
        $app->dispatchInput(PointerEvent::down($x, $y));
        $app->dispatchInput(PointerEvent::up($x, $y));

        // ① 方向键：TextFieldState 不认它 ⇒ 只有外层能收到
        $app->dispatchInput(KeyEvent::down(KeyChord::right()));
        check(
            'Press · keyboard() 沿父链：焦点在 TextField 上，外层声明的 handler 也收到键',
            $app->ancestorLog === ['Right'],
            'ancestorLog=' . \implode(',', $app->ancestorLog)
        );

        // ② 外层吃掉 Backspace ⇒ 键不到 TextFieldState ⇒ 文本不动
        $app->ancestorEats = true;
        $app->ancestorLog = [];
        $app->dispatchInput(KeyEvent::down(KeyChord::backspace()));
        check(
            'Press · keyboard() 沿父链：外层吃掉 Backspace ⇒ 焦点控件的文本不被改',
            $app->ancestorLog === ['Backspace'] && $app->fieldState()?->text() === 'ab',
            'ancestorLog=' . \implode(',', $app->ancestorLog)
                . ' text=' . ($app->fieldState()?->text() ?? '-')
        );

        // ③ 外层让位 ⇒ 键落到 TextFieldState::handleKey() ⇒ 文本真被删一个字符
        $caret = $app->fieldState()?->caretOffset();
        $before = $app->fieldState()?->text() ?? '';
        $app->ancestorEats = false;
        $app->ancestorLog = [];
        $app->dispatchInput(KeyEvent::down(KeyChord::backspace()));
        $expected = \substr($before, 0, $caret - 1) . \substr($before, $caret);
        check(
            'Press · keyboard() 沿父链：外层让位 ⇒ 键落到 TextFieldState（删掉插入符前一个字符）',
            $app->ancestorLog === ['Backspace'] && $app->fieldState()?->text() === $expected,
            \sprintf(
                'ancestorLog=%s caret=%d %s ⇒ %s（期望 %s）',
                \implode(',', $app->ancestorLog),
                $caret,
                $before,
                $app->fieldState()?->text() ?? '-',
                $expected
            )
        );
    }
}
