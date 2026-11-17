<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Rendering;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Rect;
use Drift\Layout\RenderAppBar;
use Drift\Rendering\BoxChrome;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\Color;
use Drift\Rendering\DashPattern;
use Drift\Rendering\DisplayList;
use Drift\Rendering\PaintStyle;
use Drift\Rendering\RecordingCanvas;

/**
 * 自绘 chrome 的盒必须兑现 `BoxStyle` 的虚线边框。
 *
 * 5 个自绘盒绕过 `Modifier` 自己画底+边，而 `borderDash` 的唯一消费者在 `Modifier`
 * ⇒ 这些盒设虚线会**静默画成实线**。判据只读录制 paint（软光栅会把虚线降级成
 * 实线，像素断言抓不到）。
 */
final class BoxChromeDashTest extends SmokeTestCase
{
    private const DASH_ON = 6.0;
    private const DASH_OFF = 4.0;
    private const DASH_PHASE = 1.5;

    private static function styled(bool $dash = true): BoxStyle
    {
        $s = BoxStyle::none()
            ->withBorder(Color::fromRgb(10, 20, 30), 2.0)
            ->withRadius(6.0);

        return $dash ? $s->withBorderDash(new DashPattern(self::DASH_ON, self::DASH_OFF, self::DASH_PHASE)) : $s;
    }

    /** @return list<\Drift\Rendering\Paint> */
    private static function strokedPaints(DisplayList $list): array
    {
        $out = [];
        for ($i = 0; $i < $list->count(); $i++) {
            if ($list->opAt($i) !== DisplayList::OP_DRAW_RECT) {
                continue;
            }
            $p = $list->argAt($i, 1);
            if ($p instanceof \Drift\Rendering\Paint && $p->style === PaintStyle::Stroke) {
                $out[] = $p;
            }
        }

        return $out;
    }

    private static function paintAppBar(BoxStyle $style, DisplayList $list): void
    {
        $box = new RenderAppBar($style, 4.0, EdgeInsets::zero());
        $box->layout(new BoxConstraints(0.0, 120.0, 0.0, 40.0));
        $box->paint(new RecordingCanvas($list));
    }

    public function testAppBarBorderDashReachesThePaint(): void
    {
        $list = new DisplayList();
        self::paintAppBar(self::styled(), $list);
        $strokes = self::strokedPaints($list);
        $withDash = array_values(array_filter($strokes, static fn($p): bool => $p->dash !== null));

        check(
            'BoxChrome · AppBar 虚线落到 paint（不是静默实线）',
            $withDash !== [],
            'strokes=' . count($strokes) . ' withDash=' . count($withDash),
        );
        if ($withDash !== []) {
            $d = $withDash[0]->dash;
            check(
                'BoxChrome · AppBar 虚线三字段原样传下',
                $d !== null && $d->on === self::DASH_ON && $d->off === self::DASH_OFF && $d->phase === self::DASH_PHASE,
                $d === null ? 'null' : sprintf('on=%s off=%s phase=%s', (string) $d->on, (string) $d->off, (string) $d->phase),
            );
        }
    }

    public function testTextFieldBorderDashReachesThePaint(): void
    {
        $list = new DisplayList();
        BoxChrome::paint(new RecordingCanvas($list), new Rect(0.0, 0.0, 120.0, 32.0), self::styled());

        $strokes = self::strokedPaints($list);
        $withDash = array_filter($strokes, static fn($p): bool => $p->dash !== null);
        check(
            'BoxChrome · 助手本身把虚线落到 paint（5 个自绘盒共用这条）',
            $withDash !== [],
            'strokes=' . count($strokes) . ' withDash=' . count($withDash),
        );
    }

    public function testNoDashStaysSolid(): void
    {
        $list = new DisplayList();
        self::paintAppBar(self::styled(false), $list);
        $strokes = self::strokedPaints($list);
        $anyDash = array_filter($strokes, static fn($p): bool => $p->dash !== null);
        check(
            'BoxChrome · 没设虚线时仍是实线',
            $strokes !== [] && $anyDash === [],
            'strokes=' . count($strokes) . ' withDash=' . count($anyDash),
        );
    }

    /**
     * 结构对账：自绘 chrome 必须走 `BoxChrome`。
     *
     * 同时出现 `backgroundPaint(` 与 `Paint::stroke(` 的渲染盒 = 又手写了一份 chrome
     * ⇒ 大概率漏掉 dash（这正是本类要防的静默失效）。`Paint::stroke` 还用于图标等
     * 非 chrome 描边，故只在**两者同现**时判红。
     */
    public function testNoLayoutBoxHandRollsChrome(): void
    {
        $dir = \dirname(__DIR__, 3) . '/src/Layout';
        $offenders = [];
        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $src = (string) file_get_contents($file);
            if (str_contains($src, 'backgroundPaint(') && str_contains($src, 'Paint::stroke(')) {
                $offenders[] = basename($file);
            }
        }

        check(
            'BoxChrome · 没有渲染盒再手写 chrome（底+边必须走单点）',
            $offenders === [],
            $offenders === [] ? 'clean' : '手写: ' . implode(', ', $offenders),
        );
    }
}
