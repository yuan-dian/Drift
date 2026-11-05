<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Application;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Rendering\Color;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Column;
use Drift\Widget\TextField;
use Drift\Widget\Widget;

/**
 * 焦点环的形状由盒回答（`DEBT §50`）。
 *
 * ★ 三组分头钉住三个会静默退化的地方：
 *   - 输入域：`BoxStyle` 自持在 `RenderTextField`，`mods` 那份是 0 —— 不覆写
 *     `outlineRadius()` 就退回方环（§49.1 探针量过的坑）；
 *   - 方角控件：用 **Flat 变体**（`BoxStyle::none()` ⇒ radius 真的是 0；§51 起
 *     Toggle 自绘本体形状，且 `->radius(0)` 会被 `resolveBoxStyle()` 当成没声明
 *     回落到主题 6.0，两者都不能当「碰巧方角」的代表）；直角不该被外扩削出倒角；
 *   - radius 声明：环得跟着控件的 radius 走，不是硬编码一档。
 * ★ 只写「直角顶点不变」会把「整个角都不画」判绿 ⇒ 每组都配一条正向吃色证据。
 */
final class FocusRingShapeTest extends SmokeTestCase
{
    private const W = 320;

    private const H = 200;

    /**
     * @param list<Widget> $children
     * @return array{0: Application, 1: SurfaceProbe}
     */
    private function open(array $children): array
    {
        $app = new class extends Application {
            private Widget $testRoot;

            public function setRoot(Widget $widget): void
            {
                $this->testRoot = $widget;
            }

            public function buildRoot(): Widget
            {
                return $this->testRoot;
            }

            public function title(): string
            {
                return '焦点环形状';
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

        return [$app, $probe];
    }

    private static function isAccent(SurfaceProbe $probe, int $x, int $y): bool
    {
        $p = $probe->raster()?->pixelAt($x, $y);

        return $p !== null && $p->equals(Color::fromRgb(0x09, 0x69, 0xDA));
    }

    /** 上边 y=19 上第一个 Accent 像素的 x；-1 = 一个都没画。 */
    private static function firstAccentX(SurfaceProbe $probe): int
    {
        for ($x = 0; $x < self::W; $x++) {
            if (self::isAccent($probe, $x, 19)) {
                return $x;
            }
        }

        return -1;
    }

    public function testRoundedInputFieldRingFollowsOwnBoxStyle(): void
    {
        [, $probe] = $this->open([TextField::make()->value('abc')]);

        check(
            '输入域 · 直角顶点 (19,19) 不吃色（环跟随自持 BoxStyle 的圆角，不是方环）',
            !self::isAccent($probe, 19, 19),
            'corner=' . (self::isAccent($probe, 19, 19) ? 'accent' : 'background')
        );
        check(
            '输入域 · 弧线 (23,19) 吃色（圆角处真有描边）',
            self::isAccent($probe, 23, 19)
        );
        check(
            '输入域 · 上边中点 (40,19) 吃色（环真的画出来了，不是整个角都空着）',
            self::isAccent($probe, 40, 19)
        );

        $this->flushChecks();
    }

    public function testSquareControlKeepsSquareRing(): void
    {
        [, $probe] = $this->open([Button::make('a')->variant(ButtonVariant::Flat)]);

        check(
            '方角控件 · Flat 变体（radius 真的是 0）直角顶点 (19,19) 吃色（外扩不该凭空削出倒角）',
            self::isAccent($probe, 19, 19),
            'corner=' . (self::isAccent($probe, 19, 19) ? 'accent' : 'background')
        );
        check(
            '方角控件 · 上边中点 (40,19) 吃色（环真的画出来了）',
            self::isAccent($probe, 40, 19)
        );

        $this->flushChecks();
    }

    public function testRingRadiusFollowsDeclaredRadius(): void
    {
        [, $small] = $this->open([Button::make('a')]);
        [, $large] = $this->open([Button::make('a')->radius(14.0)]);

        $sx = self::firstAccentX($small);
        $lx = self::firstAccentX($large);

        check(
            '半径 · 默认档弧线起点在圆角区（19 是方环，不该在那儿吃色）',
            $sx > 19 && $sx < 40,
            'firstAccentX=' . $sx
        );
        check(
            '半径 · 声明 14 后弧线起点右移（环跟着盒的 radius 走，不是硬编码一档）',
            $lx > $sx,
            'default=' . $sx . ' declared14=' . $lx
        );

        $this->flushChecks();
    }
}
