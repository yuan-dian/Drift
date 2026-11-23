<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\AnimationController;
use Drift\Widget\BuildContext;
use Drift\Widget\Element;
use Drift\Widget\Curve;
use Drift\Widget\Padding;
use Drift\Widget\Progress;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Widget;

/**
 * 动画夹具应用（2026-10-21，#52：§17.4.11）。
 *
 * ★ **为什么非要一个夹具应用**：`AnimationController::tick()` 只被 smoke 直接调
 *   到的话，验的是"一段算术"；真正要验的是**链条** ——
 *   `State::tickFrame()` → `Application::tickAnimations()` → `$animating` →
 *   `wantsFrame()` → `FrameClock` 起停。这四跳里任何一跳断了，界面就静止，
 *   **且不报错**。
 *
 * ★ `builds` 计数就是"帧真的重建了"的观测点：动画期间每帧 +1，跑完停在某个数
 *   ⇒ 与"帧时钟停摆"互为佐证（§17.4.9 的第八条判据）。
 *
 * @internal 测试夹具
 */
final class MotionApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Padding::make(new MotionFixtureWidget())->all(8.0);
    }

    public function title(): string
    {
        return 'motion';
    }

    /** @internal 夹具读根元素（`Application::$root` 是 protected） */
    public function rootElement(): ?Element
    {
        return $this->root();
    }
}

final class MotionFixtureWidget extends StatefulWidget
{
    public function createState(): State
    {
        return new MotionFixtureState();
    }
}

final class MotionFixtureState extends State
{
    /** @internal 夹具读它：直接 forward() / reverse() 而不必造按键 */
    public AnimationController $ctl;

    /** @internal 每次 `build()` 加一 ⇒ 观测"帧真的重建了" */
    public int $builds = 0;

    public function initState(): void
    {
        // 100ms 单程 + easeOut：帧时间戳用 16ms 步进 ⇒ 大约 7 帧跑完
        $this->ctl = AnimationController::make(100.0)->curve(Curve::easeOut());
    }

    public function tickFrame(float $timestampMs): bool
    {
        $busy = $this->ctl->tick($timestampMs);
        if ($busy) {
            $this->markDirty();
        }

        return $busy;
    }

    public function build(BuildContext $ctx): Widget
    {
        $this->builds++;

        return Progress::make($this->ctl->value());
    }
}
