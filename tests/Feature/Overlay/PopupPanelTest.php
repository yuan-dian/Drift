<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Overlay;

use Drift\Dev\Smoke\App\MenuApplication;
use Drift\Dev\Preview\SvgSurface;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderDivider;
use Drift\Layout\RenderPopupPanel;
use Drift\Rendering\Color;
use Drift\Rendering\DividerStyle;
use Drift\Input\ScrollEvent;
use Drift\Widget\Menu\Menu;
use Drift\Widget\Menu\MenuItem;

/**
 * `PopupPanel` 的**盒层**行为：能力方法表态 / 统一宽 / 限高 / 限高带来的滚动。
 *
 * ★ 与 `MenuTest` 分工：那边判"菜单这件东西怎么开、怎么关、落在哪"（Application
 *   与 Widget 层），这边判"面板盒自己长什么样"（Layout 层）。同一个夹具宿主，
 *   判的不是同一层 —— 合成一个文件会让"改面板宽度"要翻一遍菜单语义。
 * ★ 限高的判据必须**三条一起**才算验到：面板高 == 上限、自然高超上限（否则
 *   这条根本没在验限高）、末项落在面板下沿之外（这是限高的**动机**）。
 */
final class PopupPanelTest extends SmokeTestCase
{
    /** 320×900 的高视口：矮视口会把面板夹成视口高，与"被上限夹"分不清 */
    private const TALL = [320.0, 900.0];

    public function testPanelRectIsAStatementMethod(): void
    {
        $plainBox = new RenderDivider(new DividerStyle(Color::fromRgba(0, 0, 0, 255), 1.0), 100.0);
        check('panelRect · 普通盒默认 null（不表态）', $plainBox->panelRect() === null);
        check('panelRect · 面板盒覆写（表态，供落位与模态判定）', (new RenderPopupPanel())->panelRect() !== null);
    }

    public function testPanelWidthIsMaxOfMinWidthAndWidestItem(): void
    {
        $panel = Menu::make('文件')
            ->items([MenuItem::make('刷新'), MenuItem::make('退出')])
            ->minWidth(200.0)
            ->panel();

        // ★ 必须**挂载**后再量：面板的 child 由 Element 层挂接（C1 父管挂接），
        //   光对 createRenderObject 的盒 layout 会拿到"没有孩子"的空盒。
        $el = $panel->createElement();
        $el->mount(null, smokeContext());
        $box = $el->effectiveBox();
        $box?->layout(BoxConstraints::unbounded());

        check(
            'PopupPanel · 宽 = max(minWidth, 最宽项)（面板统一宽 ⇒ 菜单项整行可点）',
            $box?->size() !== null && $box->size()->width >= 200.0,
            sprintf('实际宽 %.6g', $box?->size()?->width ?? -1),
        );
    }

    public function testLongPanelIsCappedAtMaxHeightAndScrolls(): void
    {
        [$w, $h] = self::TALL;
        $surface = new SvgSurface();
        $surface->resize((int) $w, (int) $h, 1.0);
        $tight = BoxConstraints::tight(new Size($w, $h));
        $app = new MenuApplication();
        $app->attach($surface, $tight);

        // ★ 长菜单刻意不进 MenuBar：菜单栏的自然宽有既有断言，加一个标题会顶翻它。
        $app->showMenu($app->longMenu()?->panel());
        $app->frame(16.0, $tight);

        $panel = $app->overlays()->top()?->element?->effectiveBox();
        $sv = $app->panelScrollView();
        check(
            '菜单 · 长菜单（30 项）⇒ 面板高 = PANEL_MAX_HEIGHT（超长才滚）',
            $panel?->size() !== null
                && abs($panel->size()->height - Menu::PANEL_MAX_HEIGHT) < 0.01,
            sprintf('实际高 %.6g（上限 %.6g）', $panel?->size()?->height ?? -1, Menu::PANEL_MAX_HEIGHT),
        );

        // 反例：自然高必须**真的**超过上限，否则这条用例没在验限高
        $content = childBoxes($sv)[0] ?? null;
        $natural = $content instanceof RenderBox ? $content->size()?->height ?? -1 : -1;
        check(
            '菜单 · 反例：自然高（30 项）远超上限 ⇒ 这条用例真的在验限高',
            $natural > Menu::PANEL_MAX_HEIGHT,
            sprintf('自然高 %.6g vs 上限 %.6g', $natural, Menu::PANEL_MAX_HEIGHT),
        );

        check(
            '菜单 · 面板里有滚动视口，且视口比面板矮（内容变成可滚视口）',
            $sv !== null
                && $sv->size() !== null
                && $panel?->size() !== null
                && $sv->size()->height < $panel->size()->height
                && $sv->maxScroll(Axis::Vertical) > 0.0,
            sprintf(
                '视口高 %.6g / 面板高 %.6g / maxScroll=%.6g',
                $sv?->size()?->height ?? -1,
                $panel?->size()?->height ?? -1,
                $sv?->maxScroll(Axis::Vertical) ?? -1,
            ),
        );

        // 推导：30 项里 6 条分隔线（i%5==0）⇒ 24 行 × 行高 + 6 × 分隔线高。
        // 行高分两种（带 / 不带加速键显示），一律从盒子现推，不写死。
        $rows = childBoxes($sv);
        $rowH = 0.0;
        $sepH = 0.0;
        $items = 0;
        $seps = 0;
        foreach ($rows as $r) {
            $rh = $r->size()?->height;
            if ($rh === null) {
                continue;
            }
            if ($rh <= 8.0) {
                $seps++;
                $sepH = $sepH > 0.0 ? $sepH : $rh;
            } else {
                $items++;
                $rowH = $rowH > 0.0 ? $rowH : $rh;
            }
        }
        $view = $sv?->size()?->height ?? -1.0;
        check(
            '菜单 · maxScroll = 自然内容高 − 视口高（推导，数字全从盒子现推）',
            $sv !== null
                && abs($sv->maxScroll(Axis::Vertical) - ($items * $rowH + $seps * $sepH - $view)) < 0.01,
            sprintf(
                '%d 行×%.6g + %d 分隔×%.6g − 视口 %.6g = %.6g，实际 maxScroll=%.6g',
                $items,
                $rowH,
                $seps,
                $sepH,
                $view,
                $items * $rowH + $seps * $sepH - $view,
                $sv?->maxScroll(Axis::Vertical) ?? -1,
            ),
        );

        // 限高的**动机**：最后一项在面板下沿之外 ⇒ 不限高就永远点不到它
        $last = null;
        foreach ($rows as $r) {
            if ($r->size() === null || $r->size()->height <= 8.0) {
                continue;
            }
            $last = $r;
        }
        $lastBottom = -1.0;
        if ($last !== null) {
            [, $lastY] = RenderBox::absoluteOrigin($last);
            $lastBottom = $lastY + ($last->size()?->height ?? 0.0);
        }
        $panelBottom = -1.0;
        if ($panel !== null) {
            [, $panelY] = RenderBox::absoluteOrigin($panel);
            $panelBottom = $panelY + ($panel->size()?->height ?? 0.0);
        }
        check(
            '菜单 · 最后一项在面板下沿之外（不限高就点不到它 —— 限高的动机）',
            $last !== null && $panel !== null && $lastBottom > $panelBottom,
            sprintf('末项底 y=%.6g，面板底 y=%.6g', $lastBottom, $panelBottom),
        );
    }

    public function testWheelScrollsTheCappedPanel(): void
    {
        [$w, $h] = self::TALL;
        $surface = new SvgSurface();
        $surface->resize((int) $w, (int) $h, 1.0);
        $tight = BoxConstraints::tight(new Size($w, $h));
        $app = new MenuApplication();
        $app->attach($surface, $tight);
        $app->showMenu($app->longMenu()?->panel());
        $app->frame(16.0, $tight);

        $panel = $app->overlays()->top()?->element?->effectiveBox();
        [$cx, $cy] = $panel === null ? [0.0, 0.0] : RenderBox::absoluteOrigin($panel);
        $cx += ($panel?->size()?->width ?? 0.0) / 2.0;
        $cy += ($panel?->size()?->height ?? 0.0) / 2.0;

        // ★★ 每滚一次必须**重取** ScrollView：`scrollBy()` → `setState` → `update()`
        //    会换一个新渲染盒，拿旧盒读 `appliedOffset()` 恒为上一帧的值。
        // ★ 常量是 private ⇒ 表达式里写字面量（48 = WHEEL_NOTCH_PX）、名字进消息。
        // ★★ 滚轮是**带动画**的（`glideByAxis` 摊成若干帧）⇒ 拍一帧只走一部分，
        //    必须跑到收口才等于一格；这与"重取盒"是两件不同的事，都要做。
        $ts = 32.0;
        $app->dispatchInput(ScrollEvent::make($cx, $cy, 1.0));
        pumpScroll($app, $tight, $ts);
        check(
            '菜单 · 滚一格 ⇒ appliedOffset = WHEEL_NOTCH_PX（真实滚轮通道）',
            abs(($app->panelScrollView()?->appliedOffset(Axis::Vertical) ?? -1.0) - 48.0) < 0.01,
            sprintf('appliedOffset=%.6g', $app->panelScrollView()?->appliedOffset(Axis::Vertical) ?? -1),
        );

        $app->dispatchInput(ScrollEvent::make($cx, $cy, 1.0));
        pumpScroll($app, $tight, $ts);
        check(
            '菜单 · 滚两格 ⇒ appliedOffset = 2 × WHEEL_NOTCH_PX（量对，不是只动一下）',
            abs(($app->panelScrollView()?->appliedOffset(Axis::Vertical) ?? -1.0) - 96.0) < 0.01,
            sprintf('appliedOffset=%.6g', $app->panelScrollView()?->appliedOffset(Axis::Vertical) ?? -1),
        );
        check(
            '菜单 · 滚完之后面板仍在、高度仍是上限',
            $app->isMenuOpen()
                && $app->overlays()->top()?->element?->effectiveBox()?->size() !== null
                && abs(($app->overlays()->top()?->element?->effectiveBox()?->size()?->height ?? 0.0) - Menu::PANEL_MAX_HEIGHT) < 0.01,
        );
    }

    public function testShortPanelHugsItsContent(): void
    {
        [$w, $h] = self::TALL;
        $surface = new SvgSurface();
        $surface->resize((int) $w, (int) $h, 1.0);
        $tight = BoxConstraints::tight(new Size($w, $h));
        $app = new MenuApplication();
        $app->attach($surface, $tight);
        $app->showMenu($app->fileMenu()?->panel());
        $app->frame(16.0, $tight);

        $panel = $app->overlays()->top()?->element?->effectiveBox();
        check(
            '菜单 · 反例：短菜单（3 项）⇒ 面板高 < 上限（**贴着内容**，不凭空撑高一截空白）',
            $panel?->size() !== null && $panel->size()->height < Menu::PANEL_MAX_HEIGHT,
            sprintf('实际高 %.6g（上限 %.6g）', $panel?->size()?->height ?? -1, Menu::PANEL_MAX_HEIGHT),
        );
        check(
            '菜单 · 反例：短菜单 maxScroll = 0（内容不溢出 ⇒ 不画滚动条，浏览器同行为）',
            $app->panelScrollView()?->maxScroll(Axis::Vertical) === 0.0,
            sprintf('maxScroll=%.6g', $app->panelScrollView()?->maxScroll(Axis::Vertical) ?? -1),
        );
    }
}
