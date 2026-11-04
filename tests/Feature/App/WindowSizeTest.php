<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\Dev\Smoke\App\ResponsiveApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Theme\ThemeData;
use Drift\Widget\WindowSize;

/**
 * 窗口档位：断点边界、`BuildContext` 的推导，以及「跨档才重建」这条链。
 *
 * ★ 最后一条是重点：档内不重建是**有意的**（拖动 resize 是高频路径），
 *   所以 `$ctx->width` 在档内会陈旧 —— 判据把这件事明确固化下来，
 *   免得以后有人拿它做响应式判断。
 */
final class WindowSizeTest extends SmokeTestCase
{
    public function testBreakpointBoundaries(): void
    {
        check('档位 · 599 ⇒ Compact', WindowSize::fromWidth(599.0) === WindowSize::Compact);
        check('档位 · 600 ⇒ Medium', WindowSize::fromWidth(600.0) === WindowSize::Medium);
        check('档位 · 999 ⇒ Medium', WindowSize::fromWidth(999.0) === WindowSize::Medium);
        check('档位 · 1000 ⇒ Expanded', WindowSize::fromWidth(1000.0) === WindowSize::Expanded);
        check('档位 · 1439 ⇒ Expanded', WindowSize::fromWidth(1439.0) === WindowSize::Expanded);
        check('档位 · 1440 ⇒ Large', WindowSize::fromWidth(1440.0) === WindowSize::Large);
        check(
            '档位 · atLeast 是包含比较（Expanded 自己算够）',
            WindowSize::Expanded->atLeast(WindowSize::Expanded)
                && !WindowSize::Medium->atLeast(WindowSize::Expanded)
                && WindowSize::Large->atLeast(WindowSize::Compact),
        );

        $this->flushChecks();
    }

    public function testContextDerivesSizeFromWidth(): void
    {
        $ctx = smokeContext();
        check('档位 · 不给参数时按宽推（320 ⇒ Compact）', $ctx->windowSize() === WindowSize::Compact);

        check(
            '档位 · withTheme 不动档位也不动宽高',
            ($dark = $ctx->withTheme(ThemeData::dark()))->windowSize() === $ctx->windowSize()
                && $dark->width === $ctx->width
                && $dark->height === $ctx->height,
        );

        $wide = $ctx->withWindow(1200.0, 800.0, WindowSize::fromWidth(1200.0));
        check(
            '档位 · withWindow 同时换尺寸与档位',
            $wide->windowSize() === WindowSize::Expanded && $wide->width === 1200.0 && $wide->height === 800.0,
        );

        $this->flushChecks();
    }

    public function testRebuildOnlyOnClassChange(): void
    {
        $app = new ResponsiveApplication();
        $surface = new SurfaceProbe();
        $app->attach($surface, BoxConstraints::loose(new Size(1200.0, 600.0)));
        $app->frame(16.0, BoxConstraints::loose(new Size(1200.0, 600.0)));

        check(
            '档位 · 挂载后 build 看到 Expanded（1200 宽）',
            $app->builds === 1 && $app->lastSize === WindowSize::Expanded && $app->lastWidth === 1200.0,
            'builds=' . $app->builds . ' size=' . ($app->lastSize?->name ?? 'null'),
        );

        // 1200（Expanded）⇒ 940（Medium）：跨一次档
        $app->repaintForResize(BoxConstraints::tight(new Size(940.0, 600.0)));
        check(
            '档位 · Expanded ⇒ Medium 跨档 ⇒ build 重跑、拿到新档与新宽',
            $app->builds === 2 && $app->lastSize === WindowSize::Medium && $app->lastWidth === 940.0,
            'builds=' . $app->builds . ' size=' . ($app->lastSize?->name ?? 'null') . ' w=' . $app->lastWidth,
        );

        // 940 ⇒ 700：同档（Medium）内 resize
        $app->repaintForResize(BoxConstraints::tight(new Size(700.0, 600.0)));
        check(
            '档位 · Medium 内 resize ⇒ 不重建（宽度停在跨档那次的 940）',
            $app->builds === 2 && $app->lastWidth === 940.0,
            'builds=' . $app->builds . ' w=' . $app->lastWidth,
        );

        $app->repaintForResize(BoxConstraints::tight(new Size(500.0, 600.0)));
        check(
            '档位 · Medium ⇒ Compact 跨档 ⇒ 再重跑一次（结构换成单栏）',
            $app->builds === 3 && $app->lastSize === WindowSize::Compact && $app->lastWidth === 500.0,
            'builds=' . $app->builds . ' size=' . ($app->lastSize?->name ?? 'null'),
        );

        $app->repaintForResize(BoxConstraints::tight(new Size(420.0, 600.0)));
        check(
            '档位 · Compact 内 resize ⇒ 不重建（420 与 500 同档）',
            $app->builds === 3 && $app->lastWidth === 500.0,
            'builds=' . $app->builds . ' w=' . $app->lastWidth,
        );

        check(
            '档位 · 跨档后根盒按新约束重排（宽 420）',
            ($app->root()?->effectiveBox()?->size()?->width ?? -1.0) === 420.0,
            'rootW=' . ($app->root()?->effectiveBox()?->size()?->width ?? -1.0),
        );

        $this->flushChecks();
    }

    public function testZeroViewportKeepsClass(): void
    {
        $app = new ResponsiveApplication();
        $surface = new SurfaceProbe();
        $app->attach($surface, BoxConstraints::loose(new Size(1200.0, 600.0)));
        $app->frame(16.0, BoxConstraints::loose(new Size(1200.0, 600.0)));

        $app->repaintForResize(BoxConstraints::tight(new Size(0.0, 0.0)));
        check(
            '档位 · 零视口不改档（最小化不该被当成 Compact）',
            $app->builds === 1 && $app->lastSize === WindowSize::Expanded,
            'builds=' . $app->builds . ' size=' . ($app->lastSize?->name ?? 'null'),
        );

        $this->flushChecks();
    }
}
