<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Application;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\ScrollBarGeometry;
use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;
use Drift\Theme\ThemeData;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Expanded;
use Drift\Widget\LazyColumn;
use Drift\Widget\LazyListState;
use Drift\Widget\ScrollView;
use Drift\Widget\StatefulElement;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * **拖动滚动条之后的淡出** —— 与 `ScrollBarFadePixelTest` 互补的那条路径。
 *
 * ★ 为什么需要单独一条（2026-11-03）：`ScrollBarFadePixelTest` 只驱动
 *   `glideByAxis()`（**滚轮**）。两条路的起飞方式不同：
 *   · 滚轮 → `ScrollLane::glide()` → `ScrollMotion::glideBy()` → `AnimatedValue::toward()`
 *     ⇒ 目标真的变了 ⇒ `requestAnimationScan()` ⇒ 下一帧全扫发现我在动 ⇒ 进活跃表。
 *   · 拖条 / 键盘翻页 / `ensureVisible()` → `ScrollLane::move()` ⇒ 只 `noteScroll()`，
 *     而 `fade->toward(1.0)` 在"本来就全亮"时**早退**（同值不动）⇒ 没有人报告"我在动"。
 *   ⇒ 拖完条之后 State 不在**动效活跃表**，`tickFrame()` 一次都不来，
 *     `idleMs` 永远停在 0 ⇒ 条**永远全亮**。滚轮会淡、拖条不会淡 —— 用户能看见的不一致。
 */
final class ScrollBarDragFadeTest extends SmokeTestCase
{
    private const W = 400.0;

    private const H = 300.0;

    private static function constraints(): BoxConstraints
    {
        return BoxConstraints::tight(new Size(self::W, self::H));
    }

    /** @return array{0: Application, 1: SurfaceProbe, 2: LazyListState} */
    private function open(): array
    {
        $app = new class extends Application {
            public function buildRoot(): Widget
            {
                return Column::make()->children([
                    Expanded::make(
                        // ★ overscan 402 > 一行：窗口**盖得住**任意一次小步滚动
                        //   ⇒ `windowCovers()` 为真 ⇒ 不 `markDirty()` ⇒ 这条判据
                        //   才只测"淡出要不要帧"（默认 overscan = 0，一滚就重建，
                        //   重建顺手把 State 带进活跃表 ⇒ 判据碰巧恒真）。
                        LazyColumn::make(44.0)
                            ->overscan(402.0)
                            ->items(range(1, 5000), static fn(int $i): Widget => Text::make('行 ' . $i))
                    ),
                ]);
            }
        };
        $probe = new SurfaceProbe();
        $probe->resize((int) self::W, (int) self::H, 1.0);
        $app->attach($probe, self::constraints());
        $app->frame(0.0, self::constraints());

        $state = null;
        $walk = static function (Element $el) use (&$walk, &$state): void {
            if ($state !== null) {
                return;
            }
            if ($el instanceof StatefulElement && $el->state() instanceof LazyListState) {
                $state = $el->state();

                return;
            }
            $el->visitChildren($walk);
        };
        $root = $app->root();
        if ($root !== null) {
            $walk($root);
        }

        return [$app, $probe, $state ?? throw new \LogicException('LazyListState 没找到')];
    }

    private static function barColumn(): int
    {
        $left = self::W - ScrollBarGeometry::THICKNESS - ScrollBarGeometry::MARGIN;

        return (int) \floor($left + ScrollBarGeometry::THICKNESS / 2.0);
    }

    /** @return array{min: float, rows: int} */
    private static function barReading(SurfaceProbe $probe, int $x, float $threshold = 240.0): array
    {
        $raster = $probe->raster();
        if ($raster === null) {
            return ['min' => -1.0, 'rows' => 0];
        }

        $min = 1000.0;
        $rows = 0;
        $height = (int) self::H;
        for ($y = 0; $y < $height; $y++) {
            $c = $raster->pixelAt($x, $y);
            $luma = ($c->r + $c->g + $c->b) / 3.0;
            if ($luma < $min) {
                $min = $luma;
            }
            if ($luma < $threshold) {
                $rows++;
            }
        }

        return ['min' => $min, 'rows' => $rows];
    }

    private static function expectedLuma(float $alpha): float
    {
        /** @var Color $thumb */
        $thumb = ThemeData::light()->color(ColorToken::Muted);
        $base = ($thumb->r + $thumb->g + $thumb->b) / 3.0;

        return $base * $alpha + 255.0 * (1.0 - $alpha);
    }

    private static function advance(Application $app, float &$now, float $to): void
    {
        while ($now < $to) {
            $now += 16.0;
            $app->frame($now, self::constraints());
        }
    }

    /**
     * 全量 `ScrollView` 的情形：`scrollBy()` **从不标脏**（偏移就地推给盒）。
     *
     * @return array{0: Application, 1: SurfaceProbe, 2: \Drift\Widget\ScrollViewState}
     */
    private function openFull(): array
    {
        $app = new class extends Application {
            public function buildRoot(): Widget
            {
                $rows = [];
                for ($i = 1; $i <= 50; $i++) {
                    $rows[] = Text::make('行 ' . $i)->boxSize(null, 44.0);
                }

                return ScrollView::make(Column::make()->children($rows));
            }
        };
        $probe = new SurfaceProbe();
        $probe->resize((int) self::W, (int) self::H, 1.0);
        $app->attach($probe, self::constraints());
        $app->frame(0.0, self::constraints());

        $state = null;
        $walk = static function (Element $el) use (&$walk, &$state): void {
            if ($state !== null) {
                return;
            }
            if ($el instanceof StatefulElement && $el->state() instanceof \Drift\Widget\ScrollViewState) {
                $state = $el->state();

                return;
            }
            $el->visitChildren($walk);
        };
        $root = $app->root();
        if ($root !== null) {
            $walk($root);
        }

        return [$app, $probe, $state ?? throw new \LogicException('ScrollViewState 没找到')];
    }

    /**
     * 全量 `ScrollView`：拖条（`scrollBy`）→ 3s 之后必须淡到 0.35。
     */
    public function testFullScrollViewFadesAfterScrollBy(): void
    {
        [$app, $probe, $state] = $this->openFull();
        $x = self::barColumn();

        $t = 0.0;
        $rebuiltBefore = \Drift\Core\Pipeline::current()->rebuiltCount();
        $state->scrollBy(200.0);
        self::advance($app, $t, 60.0);

        $full = self::barReading($probe, $x);
        check(
            'ScrollView 拖条 · 刚滚过**全亮**',
            \abs($full['min'] - self::expectedLuma(1.0)) <= 3.0,
            \sprintf('实测 %.1f 期望 %.1f', $full['min'], self::expectedLuma(1.0)),
        );

        self::advance($app, $t, 3000.0);
        $idle = self::barReading($probe, $x);
        // ★ 另一半契约（`b7257bf`）：滚动**不该重建**。用 `markDirty()` 也能让条淡下去，
        //   但那会把整棵滚动内容每帧重建一遍 —— 这条挡的就是那种"图省事"的修法。
        $rebuilt = \Drift\Core\Pipeline::current()->rebuiltCount() - $rebuiltBefore;
        check(
            'ScrollView 拖条 · 全程**零重建**（淡出要帧，但不要重建）',
            $rebuilt === 0,
            '重建了 ' . (string) $rebuilt . ' 次',
        );
        check(
            'ScrollView 拖条 · 3s 之后淡到 0.35',
            \abs($idle['min'] - self::expectedLuma(0.35)) <= 4.0,
            \sprintf('实测 %.1f 期望 %.1f（全亮 %.1f）', $idle['min'], self::expectedLuma(0.35), $full['min']),
        );

        $this->flushChecks();
    }

    /**
     * 拖条（`scrollBy`）→ 立刻全亮 → 3s 之后必须淡到 0.35。
     */
    public function testDragThenIdleFadesTheBar(): void
    {
        [$app, $probe, $state] = $this->open();
        $x = self::barColumn();

        $t = 0.0;
        // ★ 只滚一行（44px）：窗口**盖得住**视口 ⇒ `scrollBy()` 不 `markDirty()`
        //   ⇒ 这一条判据才只测"淡出要不要帧"，不会被"重建顺手带进活跃表"救活。
        //   （原先写 200px 时它是**碰巧绿**的：跨过缓冲带触发重建，与淡出无关。）
        $state->scrollBy(44.0);   // 拖条 / 键盘翻页 / ensureVisible 同一条路
        self::advance($app, $t, 60.0);

        $full = self::barReading($probe, $x);
        check(
            '拖条淡出 · 刚滚过**全亮**',
            \abs($full['min'] - self::expectedLuma(1.0)) <= 3.0,
            \sprintf('实测 %.1f 期望 %.1f', $full['min'], self::expectedLuma(1.0)),
        );

        self::advance($app, $t, 3000.0);
        $idle = self::barReading($probe, $x);
        check(
            '拖条淡出 · 3s 之后淡到 0.35（滚轮会淡、拖条不淡 = 用户能看见的不一致）',
            \abs($idle['min'] - self::expectedLuma(0.35)) <= 4.0,
            \sprintf('实测 %.1f 期望 %.1f（全亮 %.1f）', $idle['min'], self::expectedLuma(0.35), $full['min']),
        );

        $this->flushChecks();
    }
}
