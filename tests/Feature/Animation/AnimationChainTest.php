<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Animation;

use Drift\Dev\Smoke\App\MotionApplication;
use Drift\Dev\Smoke\App\MotionFixtureState;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Element;
use Drift\Widget\StatefulElement;

/**
 * 动画链条（特征级）：State::tickFrame → Application::tickAnimations → animating →
 * wantsFrame → 帧真的重建。
 *
 * ★ 纯算术（`AnimationControllerTest`）全对而这条链断了 ⇒ 界面静止且**不报错**
 *   （§17.4.10 那一课的形状）。所以必须有一个真实跑帧的夹具应用来验"帧真的重建了"。
 *
 * ★ 判据（与 `tools/smoke.php` ㊹ 段 ② 一致）。
 */
final class AnimationChainTest extends SmokeTestCase
{
    private function openMotionApp(): MotionApplication
    {
        $app = new MotionApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(200.0, 60.0)));
        $app->frame(0.0, BoxConstraints::tight(new Size(200.0, 60.0)));

        return $app;
    }

    private function locateFixture(MotionApplication $app): MotionFixtureState
    {
        $el = findElement(
            $app->rootElement(),
            static fn(Element $el): bool => $el instanceof StatefulElement
                && $el->state() instanceof MotionFixtureState,
        );
        check('动画链 · 夹具状态可定位', $el instanceof StatefulElement);
        $this->flushChecks();

        $state = $el instanceof StatefulElement ? $el->state() : null;

        return $state instanceof MotionFixtureState ? $state : throw new \LogicException('MotionFixtureState 未定位');
    }

    public function testIdleWantsNoFrame(): void
    {
        $app = $this->openMotionApp();
        $fixture = $this->locateFixture($app);

        check(
            '动画链 · 没动画时 wantsFrame() = false（空闲不空转）',
            $app->wantsFrame() === false,
        );

        $this->flushChecks();
        unset($fixture);
    }

    public function testForwardArmsAnimating(): void
    {
        $app = $this->openMotionApp();
        $fixture = $this->locateFixture($app);
        $buildsAtRest = $fixture->builds;

        $fixture->ctl->forward();
        // ★ 起手那一帧就把 animating 立起来 —— 否则"点了播放但时钟没上膛"，
        //   界面静止且**没有任何报错**（§17.4.9 的同一条缺陷）。
        $app->frame(16.0, BoxConstraints::tight(new Size(200.0, 60.0)));
        check(
            '动画链 · 起手帧就把 animating 立起来 ⇒ wantsFrame() = true',
            $app->wantsFrame() === true,
        );

        $app->frame(32.0, BoxConstraints::tight(new Size(200.0, 60.0)));
        check(
            '动画链 · 动画期间每帧重建（builds 增长）',
            $fixture->builds > $buildsAtRest,
            'builds=' . $fixture->builds . ' atRest=' . $buildsAtRest,
        );

        $this->flushChecks();
    }

    public function testRunsToCompletionAndStops(): void
    {
        $app = $this->openMotionApp();
        $fixture = $this->locateFixture($app);

        $fixture->ctl->forward();
        $app->frame(16.0, BoxConstraints::tight(new Size(200.0, 60.0)));
        $app->frame(32.0, BoxConstraints::tight(new Size(200.0, 60.0)));
        $app->frame(2000.0, BoxConstraints::tight(new Size(200.0, 60.0)));
        check(
            '动画链 · 跑完：值精确 1、不在跑（终点帧仍要下一帧收口）',
            $fixture->ctl->progress() === 1.0 && !$fixture->ctl->isAnimating(),
            't=' . $fixture->ctl->progress(),
        );

        $app->frame(2016.0, BoxConstraints::tight(new Size(200.0, 60.0)));
        $buildsDone = $fixture->builds;
        $app->frame(2032.0, BoxConstraints::tight(new Size(200.0, 60.0)));
        check(
            '动画链 · 收口后 wantsFrame() 回落 false 且不再重建（帧时钟真的停了）',
            $app->wantsFrame() === false && $fixture->builds === $buildsDone,
            'builds=' . $fixture->builds . ' done=' . $buildsDone,
        );

        $this->flushChecks();
    }
}
