<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\Dev\Smoke\App\NavApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;

/**
 * 外壳的**激活**两半（§9.1 / §12.2）：焦点控件上按 Enter / Space，键走到哪条路。
 *
 * ## 两条路径，两个断言
 *
 * | 控件 | 键 | 走哪条 | 判据 |
 * |---|---|---|---|
 * | `NavButton` | Enter | 命令系统（带 `CommandArg`） | recorder 收到 `nav.users#7/` |
 * | `Button` | Space | 纯 UI 回调 | `onPressed` 被调一次 |
 *
 * ★ 两条都要：`NavButton` 只验命令会奖励"所有控件都发命令"（`Button` 的
 *   `onPressed` 从此不触发，纯 UI 按钮全哑）；`Button` 只验回调会奖励"命令系统
 *   没接上"（导航按钮点了没反应，而**不报错**）。
 * ★ 这条链是 `Dock → AppBar → NavButton/Button` 的端到端，任何一层把键吞掉
 *   都会红 —— 单元层的 `AppBarTest` 只验结构，验不到这里。
 *
 * ★ 与 `Feature/Input/KeyRoutingPriorityTest` 分工：那边判**加速键优先于焦点链**
 *   （注册表命中就不往下走），这边判焦点链末端"激活"的两条出口。
 */
final class ShellCommandTest extends SmokeTestCase
{
    private static function open(): NavApplication
    {
        $app = new NavApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));

        return $app;
    }

    /**
     * Tab 到 `NavButton` ⇒ Enter ⇒ 命令**带 arg** 派发。
     *
     * ★ 断言里带 `#7` 与尾随的 `/`：只验"命令名对"会漏掉 `CommandArg` 没传进去
     *   的实现（现象是命令收到了但参数是默认值，业务侧刷新了错误的页）。
     */
    public function testEnterOnNavButtonDispatchesCommandWithArg(): void
    {
        $app = self::open();
        $app->dispatchInput(KeyEvent::down(KeyChord::tab()));      // 焦点 ⇒ 第一个 NavButton
        $app->dispatchInput(KeyEvent::down(KeyChord::enter()));    // 激活 ⇒ 命令带 arg 派发

        check(
            '激活 · 焦点 NavButton 的 Enter ⇒ 命令带 arg 派发（nav.users#7）',
            $app->recorder()->seen === ['nav.users#7/'],
            \implode(',', $app->recorder()->seen)
        );
    }

    /**
     * Tab 两次到 `Button` ⇒ Space ⇒ `onPressed` 被调（纯 UI 回调路径）。
     *
     * ★ 用 Space 而不是 Enter：两条路径的键**故意不同**，这样"两个键都走命令系统"
     *   和"两个键都走回调"的实现都会各自红一条。
     */
    public function testSpaceOnButtonCallsOnPressed(): void
    {
        $app = self::open();
        $app->dispatchInput(KeyEvent::down(KeyChord::tab()));      // 焦点 ⇒ NavButton
        $app->dispatchInput(KeyEvent::down(KeyChord::tab()));      // 焦点 ⇒ 取消按钮
        $app->dispatchInput(KeyEvent::down(KeyChord::make('Space'))); // 激活 ⇒ onPressed

        check(
            '激活 · 焦点 Button 的 Space ⇒ onPressed 被调（纯 UI 回调路径）',
            $app->pressed === ['cancelled'],
            \implode(',', $app->pressed)
        );
    }
}
