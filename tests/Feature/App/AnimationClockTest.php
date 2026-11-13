<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Smoke\App\SpinnerApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\SpinnerState;
use Drift\Widget\StatefulElement;

/**
 * 帧时钟驱动的动画（§17.4.10，2026-10-18）。
 *
 * 量的是"**没人调 `tick()`**，相位也自己走"：`attach()` 与 `frame()` 走同一入口 ⇒
 * 首帧就推进一格；`wantsFrame()` 是壳决定"还要不要下一帧"的唯一依据。
 *
 * ★ 反例方向有两半，缺一条就不成立：带动画的应用必须 `wantsFrame() === true`
 *   （否则动画停住），**不带**动画的应用必须为 `false`（否则空闲时 60fps 空转烧 CPU）。
 *   只测前半，把 `wantsFrame()` 写成 `return true` 也全绿。
 * ★ 真机侧的能力在 `tools/probe-grab.cc` 的 `--motion`（帧间变化像素数），由
 *   `php tools/build-grab.php` 编出的 `probe-grab.exe` 提供 —— 那里才判得
 *   "屏幕上真的在转"。★ 这条通道不在 V2 自动清单里，需手工跑；旧脚本
 *   `tools/probe-async.php` 已退役，别再按旧文档找它。
 */
final class AnimationClockTest extends SmokeTestCase
{
    // ① attach 走同一入口 ⇒ 挂载即推进一格
    public function testAttachAlreadyAdvancesAnimation(): void
    {
        $app = new SpinnerApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(120.0, 120.0)));

        $root = $app->root();
        $state = $root instanceof StatefulElement ? $root->state() : null;
        check(
            '§17.4.10 · 首帧就推进了动画（attach 走同一入口）',
            $state instanceof SpinnerState && $state->step() === 1,
        );
        check('§17.4.10 · 有动画在跑 ⇒ wantsFrame() 为真', $app->wantsFrame() === true);
    }

    // ② 每帧推进一格；反例方向：没有动画就不该要下一帧
    public function testEachFrameAdvancesOneStep(): void
    {
        $app = new SpinnerApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(120.0, 120.0)));
        $root = $app->root();
        $state = $root instanceof StatefulElement ? $root->state() : null;

        $app->frame(16.0, BoxConstraints::tight(new Size(120.0, 120.0)));
        $app->frame(32.0, BoxConstraints::tight(new Size(120.0, 120.0)));
        check(
            '§17.4.10 · 每帧推进一格（没人调 tick）',
            $state instanceof SpinnerState && $state->step() === 3,
        );

        $quiet = new EchoApplication();
        $quiet->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(120.0, 120.0)));
        check(
            '§17.4.10 · 无动画的应用 wantsFrame() 为假（空闲不空转）',
            $quiet->wantsFrame() === false,
        );
    }
}
