<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Frame;

use Drift\App\Application;
use Drift\App\FrameClock;
use Drift\Dev\Smoke\App\RevealApplication;
use Drift\Dev\Smoke\App\SlowFrameApplication;
use Drift\Dev\Smoke\Frame\FakeTimers;
use Drift\Dev\Tests\Support\Frame;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;

/**
 * 帧预算与 D1 降级（W7 L5-2 / L5-3）。
 *
 * `06` §3：一帧 16.7ms；超了先砍语义推送（D1），layout / paint / present 永不砍。
 * 这一组把 `overBudget` 与它的唯一消费者（D1）一起验 —— 只立标志不消费就是空转分支。
 *
 * ⑭~⑯ 各自独立构造 app（判据是"连续 N 帧"，从 0 起也成立）；
 * ⑰⑱ 共享一个 app 的状态流（⑱ 依赖 ⑰ 跑完 8 帧后的节流周期）。
 */
final class BudgetTest extends SmokeTestCase
{
    // ⑭ 连续 9 帧超预算 ⇒ 还没立（单帧超多半是 GC / 系统抢占）
    public function testNineOverBudgetFramesDoNotTrigger(): void
    {
        $app = Frame::open(new RevealApplication(), 320.0, 200.0);
        for ($i = 0; $i < 9; $i++) {
            $app->app->noteFrameCost(20.0);
        }
        check(
            '帧预算 · 连续 9 帧超预算 ⇒ 还不降级（单帧超不算"真超"）',
            !$app->app->overBudget() && $app->app->frameCostMs() === 20.0,
            'over=' . ($app->app->overBudget() ? '1' : '0') . ' cost=' . $app->app->frameCostMs(),
        );
        $this->flushChecks();
    }

    // ⑮ 第 10 帧 ⇒ 立起来，且只报一条 DIAGNOSTIC
    public function testTenthFrameTriggersOverBudgetAndReportsOnce(): void
    {
        $app = Frame::open(new RevealApplication(), 320.0, 200.0)->resetErrors();
        for ($i = 0; $i < Application::OVER_BUDGET_FRAMES; $i++) {
            $app->app->noteFrameCost(20.0);
        }
        $diag = $app->diagnostics();
        check(
            '帧预算 · 第 10 帧立 overBudget 并只报一条 DIAGNOSTIC',
            $app->app->overBudget()
                && count($diag) === 1
                && str_contains($diag[0], '连续 10 帧超过'),
            'over=' . ($app->app->overBudget() ? '1' : '0') . ' diag=' . count($diag),
        );
        $this->flushChecks();
    }

    // ⑯ 一帧回到预算内 ⇒ 立刻复位
    public function testBackUnderBudgetResetsImmediately(): void
    {
        $app = Frame::open(new RevealApplication(), 320.0, 200.0);
        for ($i = 0; $i < Application::OVER_BUDGET_FRAMES; $i++) {
            $app->app->noteFrameCost(20.0);
        }
        $app->app->noteFrameCost(10.0);
        check(
            '帧预算 · 回到预算内 ⇒ 立刻复位（不用等 10 帧好帧）',
            !$app->app->overBudget() && $app->app->frameCostMs() === 10.0,
            'over=' . ($app->app->overBudget() ? '1' : '0') . ' cost=' . $app->app->frameCostMs(),
        );
        $this->flushChecks();
    }

    // ⑰ D1：超预算时语义推送降到每 4 帧一次
    // ⑱ D1：焦点变化永不节流（skip=3 也照样立刻推）
    public function testD1ThrottlesSemanticsAndUnblocksFocus(): void
    {
        // ⑰ 先立 overBudget
        $app = Frame::open(new RevealApplication(), 320.0, 200.0)->resetErrors();
        for ($i = 0; $i < Application::OVER_BUDGET_FRAMES; $i++) {
            $app->app->noteFrameCost(20.0);
        }
        for ($i = 0; $i < 8; $i++) {
            $app->tick(16.0);
        }
        check(
            '帧预算 · D1：超预算时语义推送每 4 帧一次（8 帧 ⇒ 2 次，不是 8 次）',
            $app->pushCount() === 2 && $app->app->overBudget(),
            'pushes=' . $app->pushCount(),
        );

        // ⑱ 第 9 帧（无 Tab）按周期推一次；Tab 之后的帧强制推
        $app->resetPushes();
        $app->tick(16.0);
        $afterNinth = $app->pushCount();
        $app->tick(16.0, 320.0, 200.0);
        $app->app->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $app->tick(16.0, 320.0, 200.0);
        check(
            '帧预算 · D1：焦点变化永不节流（skip=3 也照样立刻推）',
            $afterNinth === 1 && $app->pushCount() === 2,
            '第9帧=' . $afterNinth . ' Tab后=' . $app->pushCount(),
        );
        $this->flushChecks();
    }

    // ⑲ FrameClock 真的在量帧耗时（不是只有算术对）
    public function testFrameClockFeedsCostToApp(): void
    {
        $timers = new FakeTimers();
        $app = Frame::open(new SlowFrameApplication(), 320.0, 200.0);
        $clock = new FrameClock($timers);
        $clock->tick($app->app, 16.0, BoxConstraints::tight(new Size(320.0, 200.0)));
        check(
            '帧预算 · FrameClock 真的把帧耗时喂给应用（慢帧 ⇒ frameCostMs > 预算）',
            $app->app->frameCostMs() > Application::BUDGET_MS,
            (string) $app->app->frameCostMs(),
        );
        $this->flushChecks();
    }
}
