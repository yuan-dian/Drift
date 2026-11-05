<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Application;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Widget;

/**
 * **帧内标脏不能丢帧**（`DEBT` §42）。
 *
 * `bumpPaintEpoch()` 在帧内不 `scheduleFrame()` —— 它假定"本帧 paint 会消费这笔脏"。
 * 这个假定只有当变更发生在 **paint 之前**才成立。输入完全可能在 paint 之后到达
 * （帧内的回调、泵里派发的事件），此时 epoch 涨了、却没有下一帧被叫醒 ⇒ 画面
 * 一直停在上一帧，直到用户再动一下。真机症状就是"点击后第一条指针输入被吃掉"。
 *
 * ## 为什么直接钉 `beginFrame()` 而不是跑真帧
 *
 * 真帧里"输入落在 paint 之后"要靠线程/消息时序凑，测出来是概率性的。手工
 * `beginFrame()` 把时序收成一行：`beginFrame()` 会清掉帧开始时的欠账，此后
 * `wantsFrame()` 恒为 false —— 正是 `bumpPaintEpoch()` 不上膛的那个窗口。
 *
 * ★ 三条断言必须同时在：`标脏了`（否则"上膛"可能是别的东西顺带举的手）、
 *   `帧内也上膛`（本棒的主张）、`没标脏不上膛`（否则修法会退化成无条件
 *   `scheduleFrame()`，每帧空转 —— 项目自己的 `continueFrame()` 会报 DIAGNOSTIC）。
 */
final class InputPaintDebtTest extends SmokeTestCase
{
    private static function constraints(): BoxConstraints
    {
        return BoxConstraints::tight(new Size(240.0, 120.0));
    }

    private function buttonApp(): Application
    {
        $app = new class extends Application {
            public function buildRoot(): Widget
            {
                return Column::make()->paddingAll(20.0)->children([Button::make('x')]);
            }
        };

        $app->attach(new SurfaceProbe(), self::constraints());
        $app->frame(0.0, self::constraints());

        return $app;
    }

    /** 把悬停动画跑完，让管线落到"没人要帧"的稳态 */
    private function settle(Application $app): void
    {
        for ($i = 1; $i <= 40; ++$i) {
            $app->frame(1000.0 + $i * 16.0, self::constraints());
        }
    }

    /** 帧外标脏：`bumpPaintEpoch()` 自己举手 —— 对照组，本来就是通的 */
    public function testOffFrameInputArmsTheClockOnce(): void
    {
        $app = $this->buttonApp();
        $this->settle($app);
        $p = $app->pipeline();

        check('前置 · 稳态 wantsFrame() = false', $p->wantsFrame() === false);
        $before = $p->frameRequestCount();
        $epoch = $p->paintEpoch();

        $app->dispatchInput(PointerEvent::move(40.0, 30.0));

        check('帧外 · 真的标脏了（否则下面那条是假绿）', $p->paintEpoch() > $epoch);
        check('帧外 · 帧时钟上膛', $p->wantsFrame() === true);
        check(
            '帧外 · 只补一笔（不许重复 schedule）',
            $p->frameRequestCount() === $before + 1,
            'delta=' . (string) ($p->frameRequestCount() - $before),
        );

        $this->flushChecks();
    }

    /** 本棒主张：帧内标脏也必须有一帧来画 */
    public function testInFrameInputStillArmsTheClock(): void
    {
        $app = $this->buttonApp();
        $this->settle($app);
        $app->dispatchInput(PointerEvent::move(200.0, 100.0));
        $this->settle($app);
        $p = $app->pipeline();

        $p->beginFrame();
        check('前置 · 帧开始清账后 wantsFrame() = false', $p->wantsFrame() === false);
        $epoch = $p->paintEpoch();

        $app->dispatchInput(PointerEvent::move(40.0, 30.0));
        $dirty = $p->paintEpoch() > $epoch;

        check('帧内 · 真的标脏了（否则下面那条是假绿）', $dirty);
        check(
            '帧内 · 帧时钟仍上膛（修法前恒为 false ⇒ 画面停到下一次输入）',
            $p->wantsFrame() === true,
            'wantsFrame=false ⇒ 这笔脏没有下一帧来画',
        );
        $p->endFrame();

        $this->flushChecks();
    }

    /** 没标脏就不许补帧 —— 防止兜底退化成无条件 scheduleFrame() 的空转 */
    public function testUndirtiedInputDoesNotArmTheClock(): void
    {
        $app = $this->buttonApp();
        $this->settle($app);
        $p = $app->pipeline();

        $p->beginFrame();
        $app->dispatchInput(PointerEvent::move(40.0, 30.0));   // 首次：标脏 + 补帧
        $p->endFrame();
        $this->settle($app);

        $p->beginFrame();
        $epoch = $p->paintEpoch();
        $app->dispatchInput(PointerEvent::move(40.0, 30.0));   // 同盒同值：不该标脏

        check('同值输入不标脏（前置）', $p->paintEpoch() === $epoch);
        check('同值输入不上膛（不许空转）', $p->wantsFrame() === false);
        $p->endFrame();

        $this->flushChecks();
    }
}
