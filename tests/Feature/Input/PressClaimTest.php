<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

use Drift\Dev\Smoke\App\PressLabApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;
use Drift\Widget\Button;
use Drift\Widget\Element;

/**
 * 「按下归我」（`ownsPress`）：控件声明接管按下之后，**谁该接管、谁该让位**。
 *
 * ## 修的是 `instanceof` 塔
 *
 * `activateFocusedWidget()` 是一串 `instanceof`（Menu / ToggleBase / Segment /
 * Button），而且**收不到坐标、收不到按键** ⇒ 位置即语义的控件（Slider）根本
 * 做不了。`ownsPress` 让控件自己声明"按下归我"，框架只做路由。
 *
 * ## 三个主题分在三个文件
 *
 * | 文件 | 主题 | 宿主 |
 * |---|---|---|
 * | **本文件** | `ownsPress` 的**接管 / 让位** | `PressLabApplication` |
 * | `KeyboardSeamTest` | `keyboard()` 缝（自己声明 + 沿父链）| 同上 + `KeyAncestorApplication` |
 * | `Feature/App/TitleBarDragTest` | 顶条当标题栏（`windowDrag` 的让位规则）| `TitleBarLabApplication` |
 *
 * ★ 拆开的理由不是"行数"，是**坏法不同**：本文件坏在"事件发错节点"，
 *   `KeyboardSeam` 坏在"键走错顺序"，`TitleBar` 坏在"点击被静默吞掉"。
 *   将来改任一条缝，只需跑对应那个文件。
 *
 * ## 与 smoke 原写法的一处实质修正
 *
 * smoke 的 b 条用 `move(5.0, 5.0)` 表示"拖到控件外"。但接管按钮是 `Column`
 * 的第一个子、就在左上角 —— `(5,5)` **很可能仍在它自己的盒内**，那样
 * "捕获期不命中测试"对它是**恒真**的（做了命中测试也会命中它）。这里改成
 * 由盒现算、且落在**另一个控件**（旁观按钮）上：实现若做了命中测试，事件会
 * 跑到 `watchLog` 里 ⇒ 判据才可证伪。
 *
 * ★ 另一个实质变化：每个用例各开一个新 app，基线一律从零开始。smoke 是顺序
 *   脚本，`dragStarts` / `ownPresses` 这类计数跨用例累加 ⇒ 断言只能写"涨了"，
 *   写不了"等于几"。独立之后全部改成精确值。
 */
final class PressClaimTest extends SmokeTestCase
{
    private const W = 320.0;

    private const H = 400.0;

    private static function open(): PressLabApplication
    {
        $app = new PressLabApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(self::W, self::H)));

        return $app;
    }

    /**
     * 元素的盒 → `[x, y, w, h]`（根坐标）。
     *
     * ★ 必须**布局之后**现取：Widget 不可变，重建会换新盒。
     */
    private static function boxOf(?Element $el): array
    {
        $box = $el?->effectiveBox();
        if ($box === null || $box->size() === null) {
            return [0.0, 0.0, 0.0, 0.0];
        }
        [$x, $y] = boxOrigin($box);

        return [$x, $y, $box->size()->width, $box->size()->height];
    }

    /** 元素中心（根坐标） */
    private static function centerOf(?Element $el): array
    {
        [$x, $y, $w, $h] = self::boxOf($el);

        return [$x + $w / 2.0, $y + $h / 2.0];
    }

    private static function buttonWith(PressLabApplication $app, callable $predicate): ?Element
    {
        return findElement(
            $app->root(),
            static fn(Element $e): bool => $e->widget() instanceof Button && $predicate($e->widget())
        );
    }

    /** 声明了 `ownsPress: true` 的按钮（「接管」） */
    private static function ownButton(PressLabApplication $app): ?Element
    {
        return self::buttonWith($app, static fn($w): bool => $w->pointerOwnsPressValue());
    }

    /** 声明了 `pointer()` 但 `ownsPress` 为默认 false 的按钮（「旁观」） */
    private static function watchButton(PressLabApplication $app): ?Element
    {
        return self::buttonWith(
            $app,
            static fn($w): bool => $w->pointerHandlerValue() !== null && !$w->pointerOwnsPressValue()
        );
    }

    /** 什么缝都没声明的纯按钮（「子按钮」，在祖先的 `ownsPress` 子树里） */
    private static function childButton(PressLabApplication $app): ?Element
    {
        return self::buttonWith(
            $app,
            static fn($w): bool => $w->pressedHandler() !== null
                && $w->pointerHandlerValue() === null
                && $w->keyHandlerValue() === null
        );
    }

    /**
     * `ownsPress: true` ⇒ down 交给控件（回调收到 down、指针被捕获），
     * **命令不发**，但**焦点仍落上去**。
     *
     * ★ 三条缺一不可：
     *   ① 只验"回调收到 down"会奖励"既接管又照发命令"的实现；
     *   ② 只验"命令不发"会奖励"干脆什么都不做"的实现（回调也不跑）；
     *   ③ 焦点那条防过度修正 —— `ownsPress` 跳过的只是 `activateFocusedWidget()`，
     *      不是 `focusHit()`。焦点丢了 ⇒ 控件从此键盘不可达，且**不报错**。
     */
    public function testOwnsPressClaimsPointerWithoutActivating(): void
    {
        $app = self::open();
        $own = self::ownButton($app);
        [$x, $y] = self::centerOf($own);

        $app->dispatchInput(PointerEvent::down($x, $y));
        check(
            'Press · ownsPress：down 交给控件（回调收到 down、指针被捕获）',
            $app->ownLog === ['down'] && $app->captureNode() === $own,
            'log=' . \implode(',', $app->ownLog)
                . ' captured=' . ($app->captureNode() === $own ? 'yes' : 'no')
        );
        check(
            'Press · ownsPress：按下**不**走 activateFocusedWidget（命令不发）',
            $app->ownPresses === 0,
            'ownPresses=' . $app->ownPresses
        );
        check(
            'Press · ownsPress：焦点**仍落上去**（键盘可达的前提）',
            $app->focusedElement() === $own,
            'focused=' . ($app->focusedElement() === $own ? 'own' : 'other')
        );
    }

    /**
     * 接管期把指针拖到**控件外** ⇒ 事件仍归它（捕获的全部意义：不做命中测试）。
     *
     * ★ 落点由盒现算，且故意落在**旁观按钮**上 —— 实现若在捕获期还做命中测试，
     *   事件会改发给旁观按钮，`ownLog` 里就不会有 `move`、`watchLog` 里会出现。
     *   两边同时可观测，判据才不是恒真。
     */
    public function testCaptureSurvivesPointerLeavingTheWidget(): void
    {
        $app = self::open();
        $own = self::ownButton($app);
        [$x, $y] = self::centerOf($own);

        // 落点：接管按钮**下方** 30px —— 明确在自己的盒外，且落在下一个控件上
        [$ox, $oy, $ow, $oh] = self::boxOf($own);
        $outX = $ox + $ow / 2.0;
        $outY = $oy + $oh + 30.0;

        $app->dispatchInput(PointerEvent::down($x, $y));
        $app->dispatchInput(PointerEvent::move($outX, $outY));
        check(
            'Press · ownsPress：拖到控件外仍归它（捕获期不命中测试）',
            $app->ownLog === ['down', 'move'] && $app->watchLog === [] && $app->captureNode() === $own,
            'log=' . \implode(',', $app->ownLog)
                . ' watchLog=' . \implode(',', $app->watchLog)
                . ' captured=' . ($app->captureNode() === $own ? 'yes' : 'no')
        );
    }

    /**
     * up ⇒ 收口：捕获自动释放、回调收到 up。
     *
     * ★ 不释放就是"事件永远发给死节点" —— 之后整个界面的点击全部失效，
     *   而且**没有任何报错**。判据必须同时钉住"收到 up"和"捕获已空"。
     */
    public function testUpReleasesCapture(): void
    {
        $app = self::open();
        $own = self::ownButton($app);
        [$x, $y] = self::centerOf($own);
        [$ox, $oy, $ow, $oh] = self::boxOf($own);

        $app->dispatchInput(PointerEvent::down($x, $y));
        $app->dispatchInput(PointerEvent::move($ox + $ow / 2.0, $oy + $oh + 30.0));
        $app->dispatchInput(PointerEvent::up($ox + $ow / 2.0, $oy + $oh + 30.0));
        check(
            'Press · ownsPress：up ⇒ 捕获释放且回调收到 up',
            $app->ownLog === ['down', 'move', 'up'] && $app->captureNode() === null,
            'log=' . \implode(',', $app->ownLog)
                . ' captured=' . ($app->captureNode() === null ? 'no' : 'yes')
        );
    }

    /**
     * 零回归：**默认**声明（`ownsPress = false`）⇒ 命令照发、回调一次都不跑。
     *
     * ★ 为什么这条必须单独存在：`pointer()` 在 `ownsPress` 之前就有消费者
     *   （浮层锚点按钮）。若把语义扩成"默认含按下"，那些控件会**静默**失去
     *   点击能力。`ownsPress` 默认 false 就是为了让它们逐字节不变。
     * ★ "回调不跑"和"命令照发"也是一对：只验前者会奖励"pointer() 整个失效"。
     */
    public function testDefaultDeclarationKeepsActivation(): void
    {
        $app = self::open();
        [$x, $y] = self::centerOf(self::watchButton($app));

        $app->dispatchInput(PointerEvent::down($x, $y));
        check(
            'Press · 默认 pointer()：down 仍走激活（命令照发）且回调不跑 —— 零回归',
            $app->watchPresses === 1 && $app->watchLog === [] && $app->captureNode() === null,
            'presses=' . $app->watchPresses
                . ' log=' . \implode(',', $app->watchLog)
                . ' captured=' . ($app->captureNode() === null ? 'no' : 'yes')
        );
    }

    /**
     * 让位：祖先声明了 `ownsPress`，但命中点在**子按钮**上 ⇒ 子按钮赢。
     *
     * ★ 2026-10-26 改判（阶段 2 的 `Table` 行立的口子）：原先断言"祖先吞掉按下"。
     *   行级 `ownsPress` 让"整行可点"成立，但它会顺手抢走 cell 里按钮的按下 ⇒
     *   表格里放按钮**点不动且不报错**。判据改成与 `windowDragOwner()` 同构：
     *   命中点在可聚焦 / `Activatable` 的控件上 ⇒ 这次按下归它，祖先没资格
     *   替它决定。
     * ★ 两条一起断言：只看 `childPresses === 1` 会奖励"祖先也接管了"的实现
     *   （命令仍会发），只有 `ancestorLog === []` 才证明祖先**根本没收到**。
     */
    public function testChildButtonWinsOverAncestorOwnsPress(): void
    {
        $app = self::open();
        [$x, $y] = self::centerOf(self::childButton($app));

        $app->dispatchInput(PointerEvent::down($x, $y));
        check(
            'Press · 祖先 ownsPress + 子按钮 ⇒ 子按钮赢（表格行里放按钮能点）',
            $app->ancestorLog === [] && $app->childPresses === 1,
            'log=' . \implode(',', $app->ancestorLog) . ' childPresses=' . $app->childPresses
        );
        $app->dispatchInput(PointerEvent::up($x, $y));
    }
}
