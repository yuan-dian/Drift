<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\AnimatedOpacity;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 隐式动画夹具应用（2026-10-29）：`AnimatedOpacity` 的**目标在 settle 期间**才变。
 *
 * ★ **为什么非要一个夹具应用**：真机上"点「淡入」画面一动不动、再点一次才动"
 *   这个现象，只有把 `Application::frame()` 的**顺序**跑出来才复现得着 ——
 *   单独调 `State::tickFrame()` 永远是对的（那条路上目标早就改完了）。
 *
 * ★ 判据链条：`didUpdateWidget()`（settle 期间）→ `requestFrame()` →
 *   `$animating` → `wantsFrame()` → `FrameClock` 上膛。任何一跳断了，界面
 *   就静止，**且不报错**。
 *
 * @internal 测试夹具
 */
final class FadeApplication extends Application
{
    public function buildRoot(): Widget
    {
        // ★ 直接返回夹具 Widget（**不套 `Padding`**）：本测试要拿**根 State**
        //   （`rootElement()` 得是 `StatefulElement`）。套一层包装就得再往下找，
        //   而"往下找"这一步在夹具里没有观测价值。
        return new FadeFixtureWidget();
    }

    public function title(): string
    {
        return 'fade';
    }

    /** @internal 夹具读根元素（`Application::$root` 是 protected） */
    public function rootElement(): ?Element
    {
        return $this->root();
    }
}

final class FadeFixtureWidget extends StatefulWidget
{
    public function createState(): State
    {
        return new FadeFixtureState();
    }
}

final class FadeFixtureState extends State
{
    /** @internal 夹具读它：改目标走这条路，不必造按键 */
    public float $target = 1.0;

    /** @internal 每次 `build()` 加一 ⇒ 观测"帧真的重建了" */
    public int $builds = 0;

    /** @internal 夹具入口：改目标 + 标脏（模拟应用侧的 `setState`） */
    public function setTarget(float $target): void
    {
        $this->target = $target;
        $this->markDirty();
    }

    public function build(BuildContext $ctx): Widget
    {
        $this->builds++;

        return AnimatedOpacity::make(
            $this->target,
            Column::make()->children([Text::make('淡入淡出')])
        );
    }
}
