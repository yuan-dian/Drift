<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\Dev\Smoke\App\CompositeRootApp;
use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Smoke\Commands\CommandState;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Column;
use Drift\Widget\Text;

/**
 * Application：组合根（§4.5 / §11.4.3 全链）。
 *
 * ## 命令派发链的四环，判据重点在"顺序"不在"能通"
 *
 * ①② 树内冒泡 → ③ `CommandRouter` → ④ 谁都没接 ⇒ Diagnostic。
 * 每条"能通"的判据都配一条"另一边没被调"的反证：
 * - 树内接走 ⇒ 注册表的 handler 一次都不该被调到（顺序反了页面永远收不到自己的命令）；
 * - 谁都没接 ⇒ 返回 false **且**写环形缓冲（只验返回值分不出"记了"与"静默"）。
 *
 * ## 组合根那条是 2026-10 修的真缺陷
 *
 * 应用根常是**组合节点**（`StatelessWidget`，自己没盒、底下才有）⇒ 以前
 * `Frame::run` 拿 `renderObject()` 得到 null ⇒ 直接 return ⇒ **整屏空白且不报错**
 * （C6 只查上界，什么都不画也不算违反约束）。现在走 `effectiveBox()`。
 */
final class ApplicationTest extends SmokeTestCase
{
    public function testAttachAndFrameDriving(): void
    {
        $surface3 = new SurfaceProbe();
        $surface3->resize(320, 240, 1.0);
        $app = new EchoApplication();
        $app->attach($surface3, BoxConstraints::tight(new Size(320.0, 240.0)));

        check('Application · buildRoot 被调一次（组合根只组合）', $app->kerned);
        check('Application · 根 Element 挂上且有盒', $app->root() !== null && $app->rootBox() !== null);
        check('Application · attach 即跑首帧（表面收到 beginFrame）', $surface3->frames === 1);
        check('Application · title() 由应用给', $app->title() === '冒烟应用');

        // ★★ 平台驱动后续帧（N1 之后语义变了，2026-10-30）：
        //   **"跑一帧"不再等于"画一帧"**。静态树连跑两帧 ⇒ 帧循环照跑（泵、动画、
        //   语义推送都在），但 `paint` + `present` 整段跳过 —— 这正是泵帧分离的
        //   目的（空闲窗口不再每秒 60 次整帧 blit）。所以这里断的是"不空转重绘"，
        //   而"变了就会画"由下一条守着。
        $app->frame(16.0, BoxConstraints::tight(new Size(320.0, 240.0)));
        $app->frame(32.0, BoxConstraints::tight(new Size(320.0, 240.0)));
        check(
            'Application · 静态树反复驱动 frame() 不空转重绘（首帧之后 paint 全跳）',
            $surface3->frames === 1 && $surface3->ends === 1,
            "frames={$surface3->frames} ends={$surface3->ends}",
        );

        // ★ 对照组：门控只跳"没事发生"的帧。帧外直调 `update()` 换盒 ⇒ settle +
        //   layout 必然跑 ⇒ 这一帧必须画出来。没有这条，上面那条用"永远不画"也能绿。
        $app->root()?->update(Column::make()->children([Text::make('第三行')]));
        $app->frame(48.0, BoxConstraints::tight(new Size(320.0, 240.0)));
        check(
            'Application · 树变了 ⇒ 下一帧立刻重画（门控不漏）',
            $surface3->frames === 2 && $surface3->ends === 2,
            "frames={$surface3->frames} ends={$surface3->ends}",
        );

        $this->flushChecks();
    }

    public function testCommandDispatchChain(): void
    {
        $app = new EchoApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));

        // ★ 命令派发链（§11.4.3 四环齐全）
        //   第 ①② 环：树内冒泡；第 ③ 环：CommandRouter；第 ④ 环：谁都没接 ⇒ Diagnostic
        check(
            'Application · 注册表里的命令接得走（第 ③ 环）',
            $app->dispatch('echo.hello') === true
        );

        // ★ 顺序：树内冒泡**优先于**注册表。同一个名字两边都能接 ⇒ 树内先接，
        //   注册表的 handler 一次都不该被调到。顺序反了页面就永远收不到自己的命令。
        //   ★★ 必须**同名重叠**才验得着：名字不同时注册表返回 false，树内照样接住，
        //     顺序反了也是绿的（2026-10-28 变异实测确认）。判据落在 handler 的 `seen`。
        $ordered = new EchoApplication();
        $ordered->withCommandWidget = true;
        $ordered->overlapHandler = new \Drift\Dev\Smoke\Commands\EchoCommands();
        $ordered->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
        check(
            'Application · 树内冒泡优先于注册表（页面自己最知道命令是不是它的）',
            $ordered->dispatch('widget.echo') === true
        );
        $routerHits = 0;
        $ordered->root()?->visitChildren(function (\Drift\Widget\Element $e) use (&$routerHits): void {
            $st = $e instanceof \Drift\Widget\StatefulElement ? $e->state() : null;
            if ($st instanceof CommandState) {
                $routerHits = count($st->handled);
            }
        });
        check(
            'Application · 树内接走后注册表没被调（handler 的 seen 不增长）',
            $routerHits === 1 && $ordered->overlapHandler->seen === [],
            'tree=' . (string) $routerHits . ' router=' . (string) count($ordered->overlapHandler->seen)
        );
        check(
            'Application · 链上谁都没接 ⇒ 返回 false + Diagnostic（不静默）',
            $app->dispatch('nobody.wants.this') === false
        );
        check(
            'Application · 没人接的命令写进环形缓冲',
            str_contains((string) json_encode(Errors::recentDiagnostics()), 'nobody.wants.this')
        );

        $this->flushChecks();
    }

    /** 组合根（无盒）仍能排到视口尺寸，且画面有墨（不是静默空白） */
    public function testCompositeRootStillLaysOut(): void
    {
        $compositeSurface = new SurfaceProbe();
        $compositeSurface->resize(320, 240, 1.0);
        $compositeApp = new CompositeRootApp();
        $compositeApp->attach($compositeSurface, BoxConstraints::tight(new Size(320.0, 240.0)));
        check(
            'Application · 组合根（无盒）仍能排到视口尺寸',
            $compositeApp->rootBox()?->size()->width === 320.0,
            (string) ($compositeApp->rootBox()?->size()->width ?? -1.0)
        );
        check(
            'Application · 组合根的画面有墨（不是静默空白）',
            $compositeSurface->raster() !== null && inkCount($compositeSurface->raster()) > 0,
            '墨点数 ' . (string) ($compositeSurface->raster() !== null ? inkCount($compositeSurface->raster()) : -1)
        );

        $this->flushChecks();
    }
}
