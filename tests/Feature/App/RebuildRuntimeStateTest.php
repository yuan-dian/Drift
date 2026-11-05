<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Application;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;
use Drift\Layout\RenderButton;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Widget;

/**
 * **点击会重建的按钮之后，悬停/按下态必须当次恢复**（`DEBT` §42）。
 *
 * `Element::syncBox()` 每次重建都换一个新 `RenderObject`，`inheritRuntimeState()`
 * 把 `hovered` / `pressed` 连同动画对象搬给新盒；但 `FocusManager` 的
 * `$hoverBox` / `$pressBox` 仍握着旧盒。于是：
 *
 *   「移开」清的是旧盒（共享的动画对象把画面拉暗，标志却没同步）⇒
 *   「移回」落到新盒时 `setHovered(true)` 同值早退 ⇒ 画面永久停在暗帧，
 *   直到再切换一次状态把标志纠正。真机上就是「点一下之后 hover 要移两次才回来」。
 *
 * ★ 必须点**会 `setState` 的按钮**：不重建的按钮根本不换盒，怎么测都绿
 *   （`press-sequence.php` 的两侧对表就是这样绕开了这条 bug）。
 * ★ 每次都从树里重新 `findBoxOf()`：拿着旧引用测，等于把重建这件事藏起来。
 */
final class RebuildRuntimeStateTest extends SmokeTestCase
{
    /** @return array{Application, SurfaceProbe, BoxConstraints} */
    private function fixture(): array
    {
        $tight = BoxConstraints::tight(new Size(240.0, 120.0));
        $app = new class extends Application {
            public function buildRoot(): Widget
            {
                return HoverRebuildWidget::make();
            }
        };
        $probe = new SurfaceProbe();
        $probe->resize(240, 120, 1.0);
        $app->attach($probe, $tight);
        $app->frame(0.0, $tight);

        return [$app, $probe, $tight];
    }

    /** @return array{\Closure, \Closure, \Closure} settle / box / rgb */
    private function helpers(Application $app, SurfaceProbe $probe, BoxConstraints $tight): array
    {
        $clock = 1000.0;
        $settle = static function () use ($app, $tight, &$clock): void {
            for ($i = 1; $i <= 40; ++$i) {
                $app->frame($clock + $i * 16.0, $tight);
            }
            $clock += 640.0;
        };
        $box = static fn(): ?RenderButton => $app->root()?->findBoxOf(RenderButton::class);
        $rgb = static function () use ($probe): string {
            $c = $probe->raster()?->pixelAt(60, 30);

            return $c === null ? '' : ((string) $c->r) . ',' . ((string) $c->g) . ',' . ((string) $c->b);
        };

        return [$settle, $box, $rgb];
    }

    /** 走完「悬停 → 点击重建 → 移开」并返回现场，供两个断言共用 */
    /** @return array{Application, \Closure, \Closure, \Closure, string, string} */
    private function afterRebuildAndLeave(): array
    {
        [$app, $probe, $tight] = $this->fixture();
        [$settle, $box, $rgb] = $this->helpers($app, $probe, $tight);

        $settle();
        $idBefore = \spl_object_id($box());

        $app->dispatchInput(PointerEvent::move(40.0, 30.0));
        $settle();
        $light = $rgb();
        check(
            '前置 · 悬停起来了',
            ($box()?->hoverProgress() ?? 0.0) > 0.99,
            'hoverP=' . (string) ($box()?->hoverProgress() ?? -1.0),
        );

        // ★ down / up 之间必须跑帧：真机 `click()` 的两条消息各自会被帧循环隔开，
        //   连发会让重建落在 up 之后，时序与症状对不上（测出来是假绿）。
        $app->dispatchInput(PointerEvent::down(40.0, 30.0));
        $settle();
        $app->dispatchInput(PointerEvent::up(40.0, 30.0));
        $settle();
        check(
            '前置 · 点击真的换了盒（没换 ⇒ 后面全是假绿）',
            \spl_object_id($box()) !== $idBefore,
            'before=' . (string) $idBefore . ' after=' . (string) \spl_object_id($box()),
        );

        $app->dispatchInput(PointerEvent::move(200.0, 100.0));
        $settle();
        $dark = $rgb();
        check('前置 · 移开后盒内变暗', $dark !== $light, "light=$light dark=$dark");

        return [$app, $settle, $box, $rgb, $light, $dark];
    }

    /** 本棒主张：第一次移回就该亮，不需要第二次 */
    public function testHoverRecoversOnFirstMoveBack(): void
    {
        [$app, $settle, $box, $rgb, $light] = $this->afterRebuildAndLeave();

        $app->dispatchInput(PointerEvent::move(40.0, 30.0));
        $settle();

        check(
            '本棒 · 第一次移回 hoverProgress 就到 1（修法前恒为 0）',
            ($box()?->hoverProgress() ?? 0.0) > 0.99,
            'hoverP=' . (string) ($box()?->hoverProgress() ?? -1.0),
        );
        check(
            '本棒 · 第一次移回盒内像素回到悬停档',
            $rgb() === $light,
            'rgb=' . $rgb() . ' light=' . $light,
        );

        $this->flushChecks();
    }

    /** 同一分叉也会吞掉第二次按下；这条守住按下态不是一次性的 */
    public function testSecondPressStillRenders(): void
    {
        [$app, $settle, $box] = $this->afterRebuildAndLeave();

        $app->dispatchInput(PointerEvent::move(40.0, 30.0));
        $settle();
        $app->dispatchInput(PointerEvent::down(40.0, 30.0));
        $settle();

        check(
            '本棒 · 重建之后第二次按下仍有按下进度',
            ($box()?->pressProgress() ?? 0.0) > 0.99,
            'pressP=' . (string) ($box()?->pressProgress() ?? -1.0),
        );

        $this->flushChecks();
    }
}

/** 点一下就改自己的文本 ⇒ 必然走 `setState` ⇒ 必然换盒 */
final class HoverRebuildWidget extends StatefulWidget
{
    public static function make(): self
    {
        return new self();
    }

    public function createState(): State
    {
        return new HoverRebuildState();
    }
}

final class HoverRebuildState extends State
{
    public int $n = 0;

    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->paddingAll(20.0)->children([
            Button::make('点我 ' . $this->n)->onPressed(function (): void {
                $this->setState(function (): void {
                    ++$this->n;
                });
            }),
        ]);
    }
}
