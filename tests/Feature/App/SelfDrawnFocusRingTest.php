<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Application;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Layout\RenderBox;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\RenderBoxWidget;
use Drift\Widget\SegmentedControl;
use Drift\Widget\Slider;
use Drift\Widget\Toggle;
use Drift\Widget\Widget;

/**
 * 自绘控件的焦点轮廓由盒回答形状（`DEBT §51`）。
 *
 * ★ 三处各钉一条会静默退化的路径：Toggle 的环不能框标签、Segment 的环沿药丸、
 *   Slider 的环绕整条轨道但跟随轨道圆角。
 * ★ 最后一组成对判据用两个只差一个 `focusShape()` 覆写的自定义盒，证明开发者
 *   写新控件时换形状不必碰画法（绝对原点 / 外扩 / 同心仍由 final 一份负责）。
 * ★ 判据一律「顶点不吃色 + 弧线/边吃色」成对，避免「整个角都不画」假绿。
 */
final class SelfDrawnFocusRingTest extends SmokeTestCase
{
    private const W = 320;

    private const H = 200;

    /**
     * @param list<Widget> $children
     */
    private function open(array $children): SurfaceProbe
    {
        $app = new class extends Application {
            private Widget $probeRoot;

            public function setRoot(Widget $widget): void
            {
                $this->probeRoot = $widget;
            }

            public function buildRoot(): Widget
            {
                return $this->probeRoot;
            }

            public function title(): string
            {
                return '自绘控件焦点环';
            }
        };

        $c = BoxConstraints::tight(new Size((float) self::W, (float) self::H));
        $app->setRoot(Column::make()->paddingAll(20.0)->children($children));
        $probe = new SurfaceProbe();
        $probe->resize(self::W, self::H, 1.0);
        $app->attach($probe, $c);
        $app->frame(0.0, $c);
        $app->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $app->frame(1.0, $c);

        return $probe;
    }

    private static function isAccent(SurfaceProbe $probe, int $x, int $y): bool
    {
        $p = $probe->raster()?->pixelAt($x, $y);

        return $p !== null && $p->equals(Color::fromRgb(0x09, 0x69, 0xDA));
    }

    /** @return array{minX: int, minY: int, maxX: int, maxY: int, count: int} */
    private static function accentBox(SurfaceProbe $probe): array
    {
        $minX = self::W;
        $minY = self::H;
        $maxX = -1;
        $maxY = -1;
        $count = 0;
        for ($y = 0; $y < self::H; $y++) {
            for ($x = 0; $x < self::W; $x++) {
                if (!self::isAccent($probe, $x, $y)) {
                    continue;
                }
                ++$count;
                $minX = min($minX, $x);
                $minY = min($minY, $y);
                $maxX = max($maxX, $x);
                $maxY = max($maxY, $y);
            }
        }

        return ['minX' => $minX, 'minY' => $minY, 'maxX' => $maxX, 'maxY' => $maxY, 'count' => $count];
    }

    private static function rowCount(SurfaceProbe $probe, int $y): int
    {
        $n = 0;
        for ($x = 0; $x < self::W; $x++) {
            if (self::isAccent($probe, $x, $y)) {
                ++$n;
            }
        }

        return $n;
    }

    public function testToggleRingHugsControlNotLabel(): void
    {
        $probe = $this->open([Toggle::make('switch')]);
        $b = self::accentBox($probe);

        check('Toggle · 环真的画出来了', $b['count'] > 0, 'count=' . $b['count']);
        check(
            'Toggle · 环只罩本体，不把标签框进去（改前 86px 宽）',
            $b['maxX'] - $b['minX'] < 45,
            'width=' . ($b['maxX'] - $b['minX'])
        );
        check(
            'Toggle · 直角顶点不吃色（本体是胶囊，环不是方环）',
            !self::isAccent($probe, $b['minX'], $b['minY'])
        );
        check(
            'Toggle · 上边中点吃色（环真的画出来了，不是整个角都空着）',
            self::isAccent($probe, (int) (($b['minX'] + $b['maxX']) / 2), $b['minY'])
        );

        $this->flushChecks();
    }

    public function testSegmentRingFollowsPill(): void
    {
        $probe = $this->open([SegmentedControl::make(['A', 'B'])->selected(0)]);
        $b = self::accentBox($probe);
        $width = $b['maxX'] - $b['minX'] + 1;

        check('分段的环真的画出来了', $b['count'] > 0, 'count=' . $b['count']);
        check(
            '分段 · 形状内缩到药丸（首段左边界由 padding/gap 定，与标签字模无关）',
            $b['minX'] >= 31,
            'minX=' . $b['minX']
        );
        check(
            '分段 · 直角顶点不吃色（环沿药丸圆角，不是套段盒的方环）',
            !self::isAccent($probe, $b['minX'], $b['minY'])
        );
        check(
            '分段 · 弧线吃色（圆角处真有描边）',
            self::isAccent($probe, $b['minX'] + 4, $b['minY'])
        );
        check(
            '分段 · 上边不是整行满格（形状内缩到药丸，不是整盒矩形）',
            self::rowCount($probe, $b['minY']) > 0 && self::rowCount($probe, $b['minY']) < $width,
            'row=' . self::rowCount($probe, $b['minY']) . ' width=' . $width
        );

        $this->flushChecks();
    }

    public function testSliderRingFollowsTrackRadiusButStillWrapsTrack(): void
    {
        $probe = $this->open([Slider::make()->value(0.5)]);
        $b = self::accentBox($probe);

        check('滑杆 · 环真的画出来了', $b['count'] > 0, 'count=' . $b['count']);
        check(
            '滑杆 · 直角顶点不吃色（跟随轨道圆角，不是方环）',
            !self::isAccent($probe, $b['minX'], $b['minY'])
        );
        check(
            '滑杆 · 上边仍吃色（圆角处真有描边）',
            self::isAccent($probe, (int) (($b['minX'] + $b['maxX']) / 2), $b['minY'])
        );
        check(
            '滑杆 · 键盘焦点仍绕整条轨道，不与 hover 拇指形状混同',
            $b['maxX'] - $b['minX'] > 100,
            'width=' . ($b['maxX'] - $b['minX'])
        );

        $this->flushChecks();
    }

    public function testCustomBoxDeclaresShapeWithoutTouchingPaint(): void
    {
        $fullProbe = $this->open([new ShapeProbeWidget(false)]);
        $shrunkProbe = $this->open([new ShapeProbeWidget(true)]);
        $full = self::accentBox($fullProbe);
        $shrunk = self::accentBox($shrunkProbe);

        check('自定义盒 · 两版都真的画了焦点环', $full['count'] > 0 && $shrunk['count'] > 0);
        check(
            '自定义盒 · 覆写 focusShape() 后环左边界右移 8px（画法不用改）',
            $shrunk['minX'] - $full['minX'] >= 6 && $shrunk['minX'] - $full['minX'] <= 10,
            'full=' . $full['minX'] . ' shrunk=' . $shrunk['minX']
        );
        check(
            '自定义盒 · 直角顶点吃色（focusRadius 默认 0 ⇒ 方角不该被削）',
            self::isAccent($shrunkProbe, $shrunk['minX'], $shrunk['minY'])
        );

        $this->flushChecks();
    }
}

/** 开发者自定义盒的最小骨架：只覆写形状，画法继承 final 那一份。 */
final class ShapeProbeBox extends RenderBox
{
    public function __construct(private readonly bool $shrink)
    {
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        return $constraints->constrain(new Size(80.0, 40.0));
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        return 80.0;
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        return 40.0;
    }

    protected function paintContent(Canvas $canvas): void
    {
    }

    public function visitChildren(callable $visitor): void
    {
    }

    #[\Override]
    protected function focusShape(): ?Rect
    {
        if (!$this->shrink) {
            return parent::focusShape();
        }

        $size = $this->size();
        if ($size === null) {
            return null;
        }

        return new Rect(8.0, 8.0, $size->width - 16.0, $size->height - 16.0);
    }
}

/** 自定义 Widget：不调 markFocusable() 就进不了 Tab 链。 */
final class ShapeProbeWidget extends RenderBoxWidget
{
    public function __construct(private readonly bool $shrink)
    {
        parent::__construct();
        $this->markFocusable();
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new ShapeProbeBox($this->shrink);
    }
}
