<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Rendering;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderProxy;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\Color;
use Drift\Rendering\Gradient;
use Drift\Widget\Button;
use Drift\Widget\SizedBox;

/**
 * A1a（2026-10-28）：渐变从"渲染原语"接到"Widget 声明"。
 *
 * ## 这一组钉什么
 *
 * `Gradient` 的渲染链路（`Paint::withGradient` → `RasterCanvas::fillSpanGrad` /
 * `SkiaCanvas::packGradient`）从 2026-10-25 就通了，`golden` 也有 linear/radial 两个
 * **画布级**场景。断的是上面两层：
 *
 * 1. `Modifier` 没有渐变字段 ⇒ `Text::make('x')->gradient(...)` 不存在；
 * 2. `Gradient` 只有**绝对像素**坐标 ⇒ 写 `->gradient()` 的人得先知道自己盒多大。
 *
 * 所以判据必须落在**像素**上，而且必须验"比例坐标被正确换算"—— 只断言
 * `hasGradient()` 为真，等价于"存了但没画"，那正是这一档要修的病。
 */
final class GradientFillTest extends SmokeTestCase
{
    private static function red(): Color
    {
        return Color::fromRgb(255, 0, 0);
    }

    private static function blue(): Color
    {
        return Color::fromRgb(0, 0, 255);
    }

    /** 挂一个 100×100 的盒子，画到画布上，返回画布 */
    private static function paintBox(\Drift\Widget\Widget $root, int $w = 100, int $h = 100): \Drift\Rendering\RasterCanvas
    {
        $element = $root->createElement();
        $element->mount(null, smokeContext());
        $box = $element->findBoxOf(RenderProxy::class);
        if ($box === null) {
            throw new \RuntimeException('SizedBox 没有挂出 RenderProxy');
        }
        $box->layout(BoxConstraints::tight(new Size((float) $w, (float) $h)));

        return spinCanvas($box, $w, $h);
    }

    /**
     * 判据 1：竖直渐变**真的画到像素上**，且是按盒高换算的比例。
     *
     * 读数三点：顶（起色）、中（插值）、底（止色）。
     * ★ 只读顶点不够 —— "整块都是起色"（渐变没生效、只用了 base color）也会让顶点对。
     */
    public function testVerticalGradientReachesPixels(): void
    {
        $canvas = self::paintBox(
            SizedBox::make()->width(100.0)->height(100.0)
                ->gradient(Gradient::vertical([self::red(), self::blue()])),
        );

        $top = $canvas->pixelAt(50, 1);
        $mid = $canvas->pixelAt(50, 50);
        $bottom = $canvas->pixelAt(50, 98);

        check(
            '渐变 · 顶部是起色（红）',
            $top->r > 200 && $top->b < 60,
            sprintf('rgb(%d,%d,%d)', $top->r, $top->g, $top->b),
        );
        check(
            '渐变 · 底部是止色（蓝）',
            $bottom->b > 200 && $bottom->r < 60,
            sprintf('rgb(%d,%d,%d)', $bottom->r, $bottom->g, $bottom->b),
        );
        check(
            '渐变 · 中线是插值（红蓝各半 ⇒ 紫，不是纯红也不是纯蓝）',
            $mid->r > 90 && $mid->r < 180 && $mid->b > 90 && $mid->b < 180,
            sprintf('rgb(%d,%d,%d)', $mid->r, $mid->g, $mid->b),
        );

        $this->flushChecks();
    }

    /**
     * 判据 2：渐变**赢过实色**（`->background()` 与 `->gradient()` 并存时画渐变）。
     *
     * ★ 这条防的是"两个字段各画一遍"或"实色盖住渐变"。断言中线是紫而不是绿 ——
     *   绿色在红蓝插值里不可能出现，所以判据不会被"渐变画了一半"蒙混。
     */
    public function testGradientWinsOverSolidBackground(): void
    {
        $canvas = self::paintBox(
            SizedBox::make()->width(100.0)->height(100.0)
                ->background(Color::fromRgb(0, 255, 0))
                ->gradient(Gradient::vertical([self::red(), self::blue()])),
        );

        $mid = $canvas->pixelAt(50, 50);

        check(
            '渐变 · 与实色并存时渐变赢（中线是紫，不是绿）',
            $mid->r > 90 && $mid->b > 90 && $mid->g < 60,
            sprintf('rgb(%d,%d,%d)', $mid->r, $mid->g, $mid->b),
        );

        $this->flushChecks();
    }

    /**
     * 判据 3：`->gradient(null)` 回退到实色（清渐变这条路可用）。
     */
    public function testNullGradientFallsBackToSolid(): void
    {
        $canvas = self::paintBox(
            SizedBox::make()->width(100.0)->height(100.0)
                ->background(Color::fromRgb(0, 255, 0))
                ->gradient(Gradient::vertical([self::red(), self::blue()]))
                ->gradient(null),
        );

        $mid = $canvas->pixelAt(50, 50);

        check(
            '渐变 · ->gradient(null) 回退到实色（中线是绿）',
            $mid->g > 200 && $mid->r < 60 && $mid->b < 60,
            sprintf('rgb(%d,%d,%d)', $mid->r, $mid->g, $mid->b),
        );

        $this->flushChecks();
    }

    /**
     * 判据 4：两条绘制路径的比例换算**结果相同**。
     *
     * `Modifier::paintBehind()` 内联算一份（热路径，不走方法调用），
     * `BoxStyle::backgroundPaint()` 给自绘 chrome 的渲染盒算一份。两处口径必须一致 ——
     * 不一致的现象是"`Text` 上的渐变与 `Button` 上的同一份渐变**方向不同**"，
     * 用户完全无法解释。
     */
    public function testBothPaintPathsResolveTheSameLine(): void
    {
        $gradient = Gradient::vertical([self::red(), self::blue()]);

        $fromStyle = (new BoxStyle(backgroundGradient: $gradient))->backgroundPaint(100.0, 100.0);
        $styleGradient = $fromStyle?->gradient;

        check(
            '渐变 · BoxStyle 路径把竖直渐变换算成 (0,0)→(0,盒高)',
            $styleGradient !== null
                && $styleGradient->x1 === 0.0 && $styleGradient->y1 === 0.0
                && $styleGradient->x2 === 0.0 && $styleGradient->y2 === 100.0,
            $styleGradient === null ? 'paint=null' : sprintf(
                '(%.1f,%.1f)→(%.1f,%.1f)',
                $styleGradient->x1,
                $styleGradient->y1,
                $styleGradient->x2,
                $styleGradient->y2,
            ),
        );

        // Modifier 路径：同一个换算函数（`Gradient::resolvedTo()`），断言它给出同一条线
        $modifier = (new \Drift\Rendering\Modifier())->withGradient($gradient);
        $modGradient = $modifier->gradient()?->resolvedTo(100.0, 100.0);

        check(
            '渐变 · Modifier 路径与 BoxStyle 路径换算一致（同一份渐变不会画成两个方向）',
            $modGradient !== null && $styleGradient !== null
                && $modGradient->x1 === $styleGradient->x1
                && $modGradient->y1 === $styleGradient->y1
                && $modGradient->x2 === $styleGradient->x2
                && $modGradient->y2 === $styleGradient->y2,
        );

        $this->flushChecks();
    }

    /**
     * 判据 5：绝对坐标的渐变**不被缩放**（`resolvedTo()` 原样放行）。
     *
     * ★ 这条防的是"无脑乘宽高"——那会把 `CustomPainter` 里写死的绝对坐标渐变
     *   在 100×100 的盒里缩小成 1/100，而画面只是"渐变很淡"，不像错误。
     */
    public function testAbsoluteGradientIsNotScaled(): void
    {
        $absolute = Gradient::linear(10.0, 20.0, 90.0, 20.0, [self::red(), self::blue()]);

        check(
            '渐变 · 绝对坐标的渐变不被 resolvedTo 缩放',
            $absolute->resolvedTo(100.0, 100.0) === $absolute,
        );

        $relative = Gradient::horizontal([self::red(), self::blue()]);
        $resolved = $relative->resolvedTo(200.0, 50.0);

        check(
            '渐变 · 水平比例渐变换算成 (0,0)→(宽,0)',
            $resolved->x1 === 0.0 && $resolved->y1 === 0.0
                && $resolved->x2 === 200.0 && $resolved->y2 === 0.0,
            sprintf('(%.1f,%.1f)→(%.1f,%.1f)', $resolved->x1, $resolved->y1, $resolved->x2, $resolved->y2),
        );

        $this->flushChecks();
    }

    /**
     * 判据 6：自绘 chrome 的控件（`Button`）也接受渐变，且它进了 `BoxStyle`。
     *
     * ★ `Button` 的底由 `RenderButton::paintOwnChrome()` 画，而 S1 之后交给盒的
     *   `$mods` 是被**剥掉视觉三字段**的 —— 所以渐变必须走 `boxStyle()` 那条派生路，
     *   否则声明了也不画（S1 的"剥"和 A1a 的"接"在这里交汇）。
     */
    public function testButtonCarriesGradientIntoBoxStyle(): void
    {
        $gradient = Gradient::horizontal([self::red(), self::blue()]);
        $box = Button::make('确定')->gradient($gradient)
            ->createRenderObject(smokeContext())->boxStyle();

        check(
            '渐变 · Button 的 ->gradient() 进了 BoxStyle（走派生，不是被 S1 剥掉）',
            $box->backgroundGradient !== null,
            $box->backgroundGradient === null ? 'null' : 'ok',
        );
        check(
            '渐变 · Button 的 BoxStyle 认它算"有底"（isNone 为假）',
            !$box->isNone(),
        );

        $this->flushChecks();
    }
}
