<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Application;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
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
use Drift\Widget\StatefulElement;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * **滚动条的淡出真的改像素** —— 从"滚过"一路断言到画布上那一列的亮度。
 *
 * ★ 为什么需要这个文件（2026-11-01 第二轮）：上一棒在真机上判"滚动条一条都没画"，
 *   追了一整轮，结论是**探针采错了列** —— 它取窗口右缘（页面底色，整列恒 238.9），
 *   而条在列表视口的右缘（x=1370..1375）。同一天里"悬停"那一条也是探针造成的假红。
 *   ⇒ 这条通道当时**没有任何解释器级判据**，只能靠真机截图猜。本文件补上。
 *
 * ★ 判据的形状是"**推**出来的，不是拟合出来的"：条的颜色是 `ColorToken::Muted`
 *   （主题给），淡出档位是 `IDLE_OPACITY = 0.35`（`ScrollBarFade` 给），叠在**白底**
 *   上 ⇒ 期望亮度可以从这两个数算出来。所以主题改色时这里跟着变，不会变成
 *   "把今天的色值抄进测试"。
 *
 * ★ 三条断言互相咬住，缺一条就是一类假绿：
 *   ① 滚过之后**全亮**（够亮）—— 否则"什么都没画"也能满足"后来变淡了"；
 *   ② 900ms 之前**不许淡**（时间窗的下界）—— 否则"一滚就淡"（看不见条）也能绿；
 *   ③ 淡出之后**仍然看得见**（不到白）—— `ScrollBarFade` 明写"淡出不给 0"，
 *      这条挡的是"改成 0"这种看起来更"干净"的退化。
 *   外加一条**反向**的：淡出期间内容区一个像素都不许动（否则整页重画也能满足亮度变化）。
 */
final class ScrollBarFadePixelTest extends SmokeTestCase
{
    private const W = 400.0;

    private const H = 300.0;

    /** 淡出开始前的等待（`ScrollBarFade::IDLE_MS` = 900）—— 取 1000 作为"还没淡"的采样点 */
    private const BEFORE_IDLE_MS = 1000.0;

    /** 淡出收口的采样点（900 之后还有一段指数过渡，3s 足够到稳态） */
    private const AFTER_IDLE_MS = 3000.0;

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
                    // 行高 44：5000 行 = 22 万像素内容，远大于 300 的视口 ⇒
                    // 条恒在、且长度缩到 `MIN_THUMB`（最短档，位置随偏移走）
                    Expanded::make(
                        LazyColumn::make(44.0)
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

    /**
     * 条中心的列号（盒内坐标 ⇒ 客户区坐标；盒贴满客户区）。
     *
     * ★ 从 `ScrollBarGeometry` 的三个常量推，不写死 `394`：条粗或空隙一改，
     *   这里自动跟着走（写死的话改常量时测试还在"正确地"量旁边那一列）。
     */
    private static function barColumn(): int
    {
        $left = self::W - ScrollBarGeometry::THICKNESS - ScrollBarGeometry::MARGIN;

        return (int) \floor($left + ScrollBarGeometry::THICKNESS / 2.0);
    }

    /**
     * 那一列的自下而上读数：**最暗**的亮度 + 暗到"算根条"的行数 + 最暗那一行。
     *
     * ★ 取"最暗"而不是某一行：条的位置随偏移变，钉死 y 等于把"当前偏移"抄进测试。
     * ★ 行数用 `min(整列高度)` 之外的阈值筛，而不是"数非白"—— 白底的 AA 边
     *   会带出一两行噪声。
     *
     * @return array{min: float, rows: int, top: int}
     */
    private static function barReading(SurfaceProbe $probe, int $x, float $threshold = 240.0): array
    {
        $raster = $probe->raster();
        if ($raster === null) {
            return ['min' => -1.0, 'rows' => 0, 'top' => -1];
        }

        $min = 1000.0;
        $rows = 0;
        $top = -1;
        $height = (int) self::H;
        for ($y = 0; $y < $height; $y++) {
            $c = $raster->pixelAt($x, $y);
            $luma = ($c->r + $c->g + $c->b) / 3.0;
            if ($luma < $min) {
                $min = $luma;
                $top = $y;
            }
            if ($luma < $threshold) {
                $rows++;
            }
        }

        return ['min' => $min, 'rows' => $rows, 'top' => $top];
    }

    /** 期望亮度：主题给的条色，按 `$alpha` 叠在**白底**上（`drawRect` 的 alpha 混合） */
    private static function expectedLuma(float $alpha): float
    {
        /** @var Color $thumb */
        $thumb = ThemeData::light()->color(ColorToken::Muted);
        $base = ($thumb->r + $thumb->g + $thumb->b) / 3.0;

        return $base * $alpha + 255.0 * (1.0 - $alpha);
    }

    /** 推进到绝对时刻 `$to`（16ms 一帧，与真机 60Hz 同量级） */
    private static function advance(Application $app, float &$now, float $to): void
    {
        while ($now < $to) {
            $now += 16.0;
            $app->frame($now, self::constraints());
        }
    }

    /**
     * 全流程：滚 → 0.05s 全亮 → 1.0s 还不淡 → 3.0s 淡到 0.35 → 期间内容不动。
     */
    public function testFadeTimelineChangesTheBarColumn(): void
    {
        [$app, $probe, $state] = $this->open();
        $x = self::barColumn();

        // 滚轮（走 `glideByAxis`：真机那条路，不是直接改偏移）
        $t = 0.0;
        $state->glideByAxis(Axis::Vertical, 200.0);
        self::advance($app, $t, 60.0);

        $full = self::barReading($probe, $x);
        $wantFull = self::expectedLuma(1.0);
        check(
            '滚动条 · 滚过之后**全亮**（该列最暗 == Muted 叠白底）',
            \abs($full['min'] - $wantFull) <= 3.0,
            \sprintf('实测 %.1f 期望 %.1f', $full['min'], $wantFull),
        );
        check(
            '滚动条 · 全亮时条长 == 最短档 24px（内容 22 万像素 ⇒ 条缩到下限）',
            $full['rows'] === (int) ScrollBarGeometry::MIN_THUMB,
            (string) $full['rows'],
        );

        self::advance($app, $t, self::BEFORE_IDLE_MS);
        $beforeIdle = self::barReading($probe, $x);
        check(
            '滚动条 · 900ms 之内**不许淡**（时间窗的下界：一滚就淡 = 看不见条）',
            \abs($beforeIdle['min'] - $full['min']) <= 3.0,
            \sprintf('0.06s %.1f ｜ 1.0s %.1f', $full['min'], $beforeIdle['min']),
        );

        $contentBefore = self::contentPixels($probe);

        self::advance($app, $t, self::AFTER_IDLE_MS);
        $idle = self::barReading($probe, $x);
        $wantIdle = self::expectedLuma(0.35);
        check(
            '滚动条 · 停手后淡到 0.35（期望值由 `Muted × 0.35` 叠白底推出）',
            \abs($idle['min'] - $wantIdle) <= 3.0,
            \sprintf('实测 %.1f 期望 %.1f', $idle['min'], $wantIdle),
        );
        check(
            '滚动条 · 淡出**不给 0**（淡完仍看得见，否则用户不知道这块能滚）',
            $idle['min'] < 240.0 && $idle['rows'] > 0,
            \sprintf('最暗 %.1f ｜ 可见行数 %d', $idle['min'], $idle['rows']),
        );

        $contentAfter = self::contentPixels($probe);
        $moved = self::diffPixels($contentBefore, $contentAfter);
        check(
            '滚动条 · 淡出那一帧**内容区一个像素都不动**（否则整页重画也能满足亮度变化）',
            $moved === 0,
            '变化点=' . $moved,
        );

        $this->flushChecks();
    }

    /**
     * 内容区采样（**避开条那 6 列**）。
     *
     * @return list<string>
     */
    private static function contentPixels(SurfaceProbe $probe): array
    {
        $raster = $probe->raster();
        if ($raster === null) {
            return [];
        }

        $barLeft = (int) (self::W - ScrollBarGeometry::THICKNESS - ScrollBarGeometry::MARGIN);
        $pixels = [];
        for ($y = 0; $y < (int) self::H; $y += 2) {
            for ($x = 0; $x < $barLeft; $x += 2) {
                $c = $raster->pixelAt($x, $y);
                $pixels[] = $c->r . '/' . $c->g . '/' . $c->b;
            }
        }

        return $pixels;
    }

    /** @param list<string> $a @param list<string> $b */
    private static function diffPixels(array $a, array $b): int
    {
        if ($a === [] || \count($a) !== \count($b)) {
            return -1;
        }

        $changed = 0;
        foreach ($a as $i => $v) {
            if ($v !== $b[$i]) {
                $changed++;
            }
        }

        return $changed;
    }
}
