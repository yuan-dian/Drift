<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Dev\Smoke\App\TableScrollApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderTable;
use Drift\Rendering\ColorToken;
use Drift\Theme\ThemeData;

/**
 * 表格的**滚动条**（横向 / 双轴 / 真实路由拖动）。
 *
 * ★ 为什么从"列宽拖拽"那节分出来：它们测的是 `ScrollBarHost` 的几何与命中，
 *   与"列宽怎么分配"是两件事。改滚动条不该跑 clamp/分割线，反之亦然。
 *
 * ★ 两轴的**上限来自不同的量**（横向 = 列宽之和 − 视口，纵向 = 行数×行高 − 行区高），
 *   只验横向证明不了"没串味" ⇒ 每条都同时断言另一个轴。
 *
 * ★ 反例自检：横条方向参数写反 ⇒ 像素断言红（条画到右缘）；
 *   `scrollByAxis` 里两轴都标脏 ⇒ "只改 offsetX"红；角落命中按正序 ⇒ ⑰ 红。
 */
final class ScrollBarTest extends SmokeTestCase
{
    public function testMaxScrollOnEachAxis(): void
    {
        $box = Fixtures::boxOf(Fixtures::mount(Fixtures::wide(3)));

        check(
            'Table · 横向：内容 1200 > 视口 320 ⇒ maxScroll(Horizontal) = 880，纵向仍 0',
            $box?->maxScroll(Axis::Horizontal) === 880.0
                && $box->maxScroll(Axis::Vertical) === 0.0,
            'x=' . (string) ($box?->maxScroll(Axis::Horizontal) ?? -1.0)
                . ' y=' . (string) ($box?->maxScroll(Axis::Vertical) ?? -1.0),
        );

        $this->flushChecks();
    }

    public function testScrollByAxisDoesNotLeakAcrossAxes(): void
    {
        $tablePump = Fixtures::pump(...);

        $root = Fixtures::mount(Fixtures::wide(3));
        $state = Fixtures::stateOf($root);
        $state?->scrollByAxis(Axis::Horizontal, 120.0);
        $tablePump($root);

        // ★ 重建会换新盒（Widget 不可变）⇒ 必须重新取，拿旧盒读子偏移恒是上一帧的值
        $box = Fixtures::boxOf($root);
        $kids = Fixtures::kidsOf($box);

        check(
            'Table · 横向：scrollByAxis(Horizontal) 只改 offsetX（120 ⇒ 表头与行区都平移 −120）',
            $state?->offsetX() === 120.0
                && $state->offsetY() === 0.0
                && ($kids[0] ?? null)?->offsetX() === -120.0
                && ($kids[1] ?? null)?->offsetX() === -120.0,
            'x=' . (string) ($state?->offsetX() ?? -1.0)
                . ' header=' . (string) (($kids[0] ?? null)?->offsetX() ?? -9.0)
                . ' body=' . (string) (($kids[1] ?? null)?->offsetX() ?? -9.0),
        );
        check(
            'Table · 横向：纵向偏移不受影响（scrollByAxis 不串轴）',
            $state?->offsetY() === 0.0 && ($kids[1] ?? null)?->offsetY() === Fixtures::HEADER_H,
            'offsetY=' . (string) ($state?->offsetY() ?? -1.0),
        );

        $this->flushChecks();
    }

    public function testHorizontalBarGeometryAndInk(): void
    {
        $tablePump = Fixtures::pump(...);

        $root = Fixtures::mount(Fixtures::wide(3));
        $state = Fixtures::stateOf($root);
        $state?->scrollByAxis(Axis::Horizontal, 120.0);
        $tablePump($root);
        $box = Fixtures::boxOf($root);

        // ⑭ 横条几何：贴底缘（H − 条粗 − 空隙），纵向不溢出 ⇒ 没有竖条
        $hbar = $box?->thumbRect(Axis::Horizontal);
        $vbar = $box?->thumbRect(Axis::Vertical);
        check(
            'Table · 横条贴底缘（240 − 6 − 3 = 231），宽度按视口占比；纵向不溢出 ⇒ 无竖条',
            $hbar !== null
                && abs($hbar->top - 231.0) < 0.01
                && abs($hbar->height - 6.0) < 0.01
                && $hbar->width > 24.0
                && $vbar === null,
            $hbar === null ? 'no-hbar' : \sprintf('top=%.2f w=%.2f', $hbar->top, $hbar->width),
        );

        // ⑭b 横条**真的画出来了**：几何对了不等于画对（方向参数写反会画到右缘去）
        //    offsetX=120 / maxX=880 ⇒ 条起点 3 + (120/880)×143.33 ≈ 22.55，够长到 x=100。
        $shot = Fixtures::rasterOf($box, 320, 240);
        $muted = ThemeData::light()->color(ColorToken::Muted);
        check(
            'Table · 横条：底部 y=234 处有 `Muted` 像素（条画在底缘，不是右缘）',
            $shot->pixelAt(100, 234)->equals($muted)
                && !$shot->pixelAt(100, 16)->equals($muted),
            'y=234 ' . Fixtures::pxOf($shot, 100, 234) . ' / y=16 ' . Fixtures::pxOf($shot, 100, 16),
        );

        $this->flushChecks();
    }

    public function testBothBarsCoexist(): void
    {
        $box = Fixtures::boxOf(Fixtures::mount(Fixtures::wide(100)));
        $axes = $box?->scrollAxes() ?? [];
        $h = $box?->thumbRect(Axis::Horizontal);
        $v = $box?->thumbRect(Axis::Vertical);

        check(
            'Table · 双轴：scrollAxes = [Horizontal, Vertical]（先横后竖 = 绘制顺序）',
            \count($axes) === 2 && $axes[0] === Axis::Horizontal && $axes[1] === Axis::Vertical,
            \count($axes) !== 2
                ? 'n=' . \count($axes)
                : ($axes[0] === Axis::Horizontal ? 'H,V' : 'V,H'),
        );
        check(
            'Table · 双轴：内容宽高都溢出 ⇒ 两根条同时存在（竖条贴右缘、横条贴底缘，互不覆盖）',
            $h !== null && $v !== null && $v->left > $h->left,
            'h=' . ($h === null ? 'null' : 'ok') . ' v=' . ($v === null ? 'null' : 'ok'),
        );

        $this->flushChecks();
    }

    /** 拖横条只改横向偏移（唯一"直接调 dragThumb 验不到"的点） */
    public function testRealRouteDragHorizontalThumb(): void
    {
        $app = new TableScrollApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(Fixtures::VIEW_W, Fixtures::VIEW_H)));

        $box = $app->root()->effectiveBoxOf(RenderTable::class);
        $box = $box instanceof RenderTable ? $box : null;
        $state = Fixtures::stateOf($app->root());
        $hbar = $box?->thumbRect(Axis::Horizontal);
        [$ox, $oy] = $box === null ? [0.0, 0.0] : RenderBox::absoluteOrigin($box);
        $hx = $ox + ($hbar?->left ?? 0.0) + ($hbar?->width ?? 0.0) / 2.0;
        $hy = $oy + ($hbar?->top ?? 0.0) + ($hbar?->height ?? 0.0) / 2.0;
        $travel = $box?->thumbTravel(Axis::Horizontal) ?? 0.0;
        $maxX = $box?->maxScroll(Axis::Horizontal) ?? 0.0;
        $expect = $travel > 0.0 ? 20.0 * $maxX / $travel : -1.0;

        $app->dispatchInput(PointerEvent::down($hx, $hy));
        $app->dispatchInput(PointerEvent::move($hx + 20.0, $hy));
        $app->dispatchInput(PointerEvent::up($hx + 20.0, $hy));
        $app->frame(16.0, BoxConstraints::tight(new Size(Fixtures::VIEW_W, Fixtures::VIEW_H)));

        check(
            'Table · 真实路由：拖横条 ⇒ offsetX 按 max/travel 放大（20px ⇒ 约 39px），offsetY 不动',
            $state !== null
                && $expect > 0.0
                && abs($state->offsetX() - $expect) < 0.51
                && $state->offsetY() === 0.0,
            'x=' . (string) ($state?->offsetX() ?? -1.0)
                . ' expect=' . \sprintf('%.2f', $expect)
                . ' y=' . (string) ($state?->offsetY() ?? -1.0),
        );

        $this->flushChecks();
    }

    /**
     * 角落（两轴交叠 6×6）归**竖条**（命中按反序 = 后画的在上层）。
     *
     * ★ 两根条只在**各自滚到末端**时才在右下角交叠（thumb 从 MARGIN 起步），
     *   所以先把两轴都推到头，否则"角落"那块其实什么都没有。
     */
    public function testCornerBelongsToVerticalThumb(): void
    {
        $app = new TableScrollApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(Fixtures::VIEW_W, Fixtures::VIEW_H)));
        $state = Fixtures::stateOf($app->root());
        $state?->scrollByAxis(Axis::Vertical, 99999.0);
        $state?->scrollByAxis(Axis::Horizontal, 99999.0);
        $app->frame(16.0, BoxConstraints::tight(new Size(Fixtures::VIEW_W, Fixtures::VIEW_H)));

        $box = $app->root()->effectiveBoxOf(RenderTable::class);
        $box = $box instanceof RenderTable ? $box : null;
        $vbar = $box?->thumbRect(Axis::Vertical);
        $hbar = $box?->thumbRect(Axis::Horizontal);
        [$ox, $oy] = $box === null ? [0.0, 0.0] : RenderBox::absoluteOrigin($box);
        // 竖条中心 x + 横条中心 y = 交叠区中心（两条都贴在末端时那一块就是右下角）
        $cx = $ox + ($vbar?->left ?? 0.0) + ($vbar?->width ?? 0.0) / 2.0;
        $cy = $oy + ($hbar?->top ?? 0.0) + ($hbar?->height ?? 0.0) / 2.0;
        $hit = $app->dispatchInput(PointerEvent::down($cx, $cy));
        $app->dispatchInput(PointerEvent::move($cx, $cy - 20.0));
        $app->dispatchInput(PointerEvent::up($cx, $cy - 20.0));
        $app->frame(17.0, BoxConstraints::tight(new Size(Fixtures::VIEW_W, Fixtures::VIEW_H)));

        // 拖动后再取一次盒（拖一次就重建一次，旧盒的 appliedOffset 恒是上一帧的值）
        $after = $app->root()->effectiveBoxOf(RenderTable::class);
        $after = $after instanceof RenderTable ? $after : null;
        $afterY = $after?->appliedOffset(Axis::Vertical) ?? -1.0;
        $afterX = $after?->appliedOffset(Axis::Horizontal) ?? -1.0;
        $maxY = $after?->maxScroll(Axis::Vertical) ?? 0.0;
        $maxX = $after?->maxScroll(Axis::Horizontal) ?? 0.0;

        check(
            'Table · 真实路由：角落按下 ⇒ 归竖条（拖竖条不会把表横向拽走）：纵向回退，横向仍在末端',
            $vbar !== null && $hbar !== null
                && $afterY < $maxY
                && $afterX === $maxX,
            'y=' . \sprintf('%.1f', $afterY) . ' x=' . \sprintf('%.1f', $afterX)
                . ' corner=' . \sprintf('(%.1f,%.1f)', $cx, $cy)
                . ' hit=' . ($hit === null ? 'null' : 'ok'),
        );

        $this->flushChecks();
    }
}
