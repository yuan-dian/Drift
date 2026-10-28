<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Rendering;

use Drift\Dev\Smoke\Layout\SolidBox;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderObject;
use Drift\Layout\RenderScrollView;
use Drift\Rendering\Color;
use Drift\Rendering\DisplayList;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RecordingCanvas;

/**
 * `RenderScrollView` 这个**盒**的三件事：视口怎么定、偏移落在哪、滚动条画在哪。
 *
 * ## 为什么单独成文件（与 `Widget/ScrollViewScrollTest` 分层）
 *
 * `Widget/ScrollViewScrollTest` 测的是 **State**（滚动该不该重建、回夹取值）；
 * 这里测的是 **RenderObject** —— 直接 `layout()` 一个盒，不经过 Widget 树。
 * 两者差一层，坏法完全不同：State 层的错是"该重建没重建"（看不见），
 * 盒层的错是"偏移没进子盒 `offset`、跑到 paint 期 `translate` 里"（画在哪与
 * 命中哪分叉，2026-10-17 那族 padding 缺陷）。
 *
 * ## 被钉住的核心不变量
 *
 * ★★ **偏移住在子盒的 `offset` 里，不是 paint 期 translate**。判据只能是
 *   `child->offsetY() === -offset` —— 一旦挪进 paint，`offsetY()` 恒 0，
 *   而画面看起来**完全正常**（因为 translate 也算对了）。
 *   同族的坑：命中测试用 `offset`、绘制用 translate ⇒ 点得到但画不对。
 *
 * ★ 滚动条判据走**像素**：thumb 是绘制物，"结构上有 thumbRect"不代表画出来了，
 *   更不代表画在 `thumbRect` 那个位置（曾经被内容 translate 带走整条）。
 */
final class ScrollViewBoxTest extends SmokeTestCase
{
    /** 洋红：`SolidBox` 画黑 ⇒ 用洋红当 thumb 色，像素断言能区分"thumb"与"内容" */
    private static function ink(): Color
    {
        return Color::fromRgb(0xFF, 0x00, 0xFF);
    }

    /** 视口 100×300、内容 100×1000 的那个标准盒（maxScroll = 700） */
    private static function tall(float $offset): RenderScrollView
    {
        $box = new RenderScrollView($offset, self::ink(), true);
        $box->setChild(new SolidBox(new Size(100.0, 1000.0), 100.0, 1000.0));
        $box->layout(new BoxConstraints(0.0, 100.0, 0.0, 300.0));

        return $box;
    }

    private static function childOf(?RenderScrollView $box): ?RenderBox
    {
        if ($box === null) {
            return null;
        }
        $kid = null;
        $box->visitChildren(function (RenderObject $child) use (&$kid): void {
            $kid = $child;
        });

        return $kid instanceof RenderBox ? $kid : null;
    }

    private static function raster(?RenderBox $box, int $w, int $h): RasterCanvas
    {
        $list = new DisplayList();
        $box?->paint(new RecordingCanvas($list));
        $canvas = new RasterCanvas($w, $h, Color::white(), Scale::of(1.0));
        $list->replay($canvas);

        return $canvas;
    }

    /** 区域里有多少个 thumb 色像素 */
    private static function inkIn(RasterCanvas $c, int $x0, int $y0, int $x1, int $y1): int
    {
        $n = 0;
        for ($y = $y0; $y < $y1; $y++) {
            for ($x = $x0; $x < $x1; $x++) {
                if ($c->pixelAt($x, $y)->equals(self::ink())) {
                    $n++;
                }
            }
        }

        return $n;
    }

    /**
     * 视口 = 约束上限（不是内容尺寸），maxScroll = 内容主轴 − 视口主轴。
     *
     * ★ 这两条一起看才有意义：单看"盒 100×300"分不清是"按约束"还是"按内容裁的"；
     *   单看 maxScroll=700 也分不清 300 是从哪来的。
     */
    public function testViewportIsTheConstraintAndMaxScrollIsTheDifference(): void
    {
        $box = self::tall(0.0);
        $size = $box->size();

        check(
            'Scroll · 视口 = 约束上限（内容 1000 高 ⇒ 盒停在 100×300，不撑成内容高）',
            $size !== null && $size->width === 100.0 && $size->height === 300.0,
            $size === null ? 'size 为 null' : \sprintf('%.1f×%.1f', $size->width, $size->height)
        );
        check(
            'Scroll · maxScroll = 内容主轴 − 视口主轴（1000−300 = 700）',
            $box->maxScroll(Axis::Vertical) === 700.0,
            (string) $box->maxScroll(Axis::Vertical)
        );
    }

    /**
     * ★★ 偏移落在**子盒 `offset`** 上 —— 这个文件的头号判据。
     *
     * ★ 两条一起钉：`offset 0 ⇒ 0`（基线：没有额外平移）与 `offset 300 ⇒ −300`。
     *   只钉前者是恒真陷阱（"从不 setOffset"也满足），只钉后者分不清符号。
     */
    public function testOffsetLivesInTheChildsOffsetNotInPaintTranslate(): void
    {
        $zero = self::tall(0.0);
        check(
            'Scroll · 偏移 0 ⇒ 子盒 offsetY = 0（基线：没有额外平移）',
            self::childOf($zero)?->offsetY() === 0.0,
            (string) (self::childOf($zero)?->offsetY() ?? -1.0)
        );

        $shifted = self::tall(300.0);
        check(
            'Scroll · 偏移 300 ⇒ 子盒 offsetY = −300（★ 进子盒 offset，不是 paint 期 translate）',
            self::childOf($shifted)?->offsetY() === -300.0
                && $shifted->appliedOffset(Axis::Vertical) === 300.0,
            'offsetY=' . (self::childOf($shifted)?->offsetY() ?? -1.0)
                . ' applied=' . $shifted->appliedOffset(Axis::Vertical)
        );
    }

    /**
     * 命中按**视口**判定，且滚动后跟随偏移。
     *
     * ★ "视口外点不到"是结构保证（裁掉的部分不该能点）——它与"滚了之后还能点"
     *   必须同时成立：只做前者可以靠"整棵子树不参与命中"通过，只做后者可以靠
     *   "不做视口裁剪"通过。
     */
    public function testHitTestUsesViewportAndFollowsOffset(): void
    {
        $box = self::tall(0.0);
        $kid = self::childOf($box);
        check(
            'Scroll · 命中按视口判定：视口内的点进子树（内容超长仍命中）',
            $box->hitTest(50.0, 250.0) === $kid,
            $box->hitTest(50.0, 250.0) === $kid ? 'hit' : 'miss'
        );
        check(
            'Scroll · 视口外的点进不了子树（"裁掉的部分点不到"是结构保证）',
            $box->hitTest(50.0, 350.0) === null,
            $box->hitTest(50.0, 350.0) === null ? 'null' : 'hit'
        );

        $shifted = self::tall(300.0);
        $shiftedKid = self::childOf($shifted);
        check(
            'Scroll · 滚动后命中跟随偏移：视口 (50,250) 命中内容第 550 行（局部坐标跟着偏移走）',
            $shifted->hitTest(50.0, 250.0) === $shiftedKid,
            $shifted->hitTest(50.0, 250.0) === $shiftedKid ? 'hit' : 'miss'
        );
    }

    /** 越界偏移被 `maxScroll` 夹住（"内容飞走"的静默形态 ⇒ 结构上不可能） */
    public function testOffsetBeyondMaxScrollIsClamped(): void
    {
        $box = self::tall(9999.0);
        check(
            'Scroll · 越界偏移被 maxScroll 夹住（9999 ⇒ 700 ⇒ offsetY = −700）',
            $box->appliedOffset(Axis::Vertical) === 700.0
                && self::childOf($box)?->offsetY() === -700.0,
            'applied=' . $box->appliedOffset(Axis::Vertical)
                . ' child=' . (self::childOf($box)?->offsetY() ?? -1.0)
        );
    }

    /**
     * 父无界 ⇒ 视口跟着内容走、不滚。
     *
     * ★ 这条不是"顺便"：无界主轴下把视口定成内容高是唯一正确解，
     *   否则 `ScrollView` 放进 `Column`（主轴无界）会拿到一个 0 高视口。
     */
    public function testUnboundedParentDoesNotScroll(): void
    {
        $box = new RenderScrollView(9999.0, self::ink(), true);
        $box->setChild(new SolidBox(new Size(100.0, 1000.0), 100.0, 1000.0));
        $box->layout(BoxConstraints::unbounded());
        check(
            'Scroll · 父无界 ⇒ 视口跟着内容走（不滚：maxScroll = 0，偏移归 0）',
            $box->maxScroll(Axis::Vertical) === 0.0
                && $box->appliedOffset(Axis::Vertical) === 0.0
                && self::childOf($box)?->offsetY() === 0.0,
            'maxScroll=' . $box->maxScroll(Axis::Vertical)
                . ' applied=' . $box->appliedOffset(Axis::Vertical)
        );
    }

    /** 横向：偏移进 `offsetX`，maxScroll 按宽度算（不是"永远按高"） */
    public function testHorizontalAxis(): void
    {
        $box = new RenderScrollView(120.0, self::ink(), false);
        $box->setChild(new SolidBox(new Size(800.0, 50.0), 800.0, 50.0));
        $box->layout(new BoxConstraints(0.0, 400.0, 0.0, 50.0));
        check(
            'Scroll · 横向滚动：偏移进 offsetX（内容 800 宽，视口 400 ⇒ maxScroll = 400）',
            $box->maxScroll(Axis::Horizontal) === 400.0
                && self::childOf($box)?->offsetX() === -120.0,
            'maxScroll=' . $box->maxScroll(Axis::Horizontal)
                . ' offsetX=' . (self::childOf($box)?->offsetX() ?? -1.0)
        );
    }

    /**
     * 滚动条是**绘制物**：位置、长度、以及"不溢出就不画"。
     *
     * 规格（视口 100×300 / 内容 1000 / maxScroll 700 / 边距 3 / 条宽 6）：
     *   thumbLen = 300 × 300/1000 = 90
     *   travel   = 300 − 6 − 90 = 204
     *   thumbPos = 3 + offset/700 × 204
     * 这些数是**推**出来的，不是量出来凑的。
     */
    public function testScrollBarPaintFollowsOffset(): void
    {
        // offset 0 ⇒ pos = 3 ⇒ thumb y 3..93
        $top = self::raster(self::tall(0.0), 100, 300);
        check(
            'Scroll · 滚动条：offset 0 ⇒ thumb 贴顶（上边距恰好 3px：y 0..2 无墨、y 3 有墨）',
            self::inkIn($top, 92, 3, 95, 4) > 0 && self::inkIn($top, 91, 0, 97, 3) === 0,
            \sprintf('y3=%d margin=%d', self::inkIn($top, 92, 3, 95, 4), self::inkIn($top, 91, 0, 97, 3))
        );
        check(
            'Scroll · 滚动条：offset 0 ⇒ thumb 长度 90（y 3..92 满、y 93..96 无墨）',
            self::inkIn($top, 92, 3, 95, 93) === 3 * 90 && self::inkIn($top, 92, 93, 95, 97) === 0,
            \sprintf('body=%d below=%d', self::inkIn($top, 92, 3, 95, 93), self::inkIn($top, 92, 93, 95, 97))
        );

        // offset = maxScroll ⇒ pos = 3 + 204 = 207 ⇒ thumb y 207..297
        $bottom = self::raster(self::tall(9999.0), 100, 300);
        check(
            'Scroll · 滚动条：offset = maxScroll ⇒ thumb 贴底（y 290..296 有墨、顶端无墨）',
            self::inkIn($bottom, 92, 291, 95, 296) > 0 && self::inkIn($bottom, 91, 0, 97, 3) === 0,
            \sprintf('bottom=%d margin=%d', self::inkIn($bottom, 92, 291, 95, 296), self::inkIn($bottom, 91, 0, 97, 3))
        );

        // 中段：pos = 3 + 300/700×204 = 90.4 ⇒ thumb y 90..181
        // ★ 这条是 2026-10-17 那个真 bug 的判据：thumb 若画在 translate 之后 ⇒
        //   被带到 y −210..−120 ⇒ 整条飞出视口。
        $mid = self::raster(self::tall(300.0), 100, 300);
        check(
            'Scroll · 滚动条：中段偏移 ⇒ thumb 不随内容平移（y 95..178 有墨、顶端边距无墨）',
            self::inkIn($mid, 92, 95, 95, 178) > 0 && self::inkIn($mid, 91, 0, 97, 3) === 0,
            \sprintf('body=%d margin=%d', self::inkIn($mid, 92, 95, 95, 178), self::inkIn($mid, 91, 0, 97, 3))
        );

        // 不溢出 ⇒ **不画**（画一条满轨 thumb 是撒谎：暗示"可以滚"）
        $flat = new RenderScrollView(9999.0, self::ink(), true);
        $flat->setChild(new SolidBox(new Size(100.0, 1000.0), 100.0, 1000.0));
        $flat->layout(BoxConstraints::unbounded());
        check(
            'Scroll · 滚动条：内容不溢出（maxScroll=0）⇒ 不画（整条右带无 thumb 色）',
            self::inkIn(self::raster($flat, 100, 1000), 91, 0, 97, 1000) === 0,
            (string) self::inkIn(self::raster($flat, 100, 1000), 91, 0, 97, 1000)
        );

        // 横向：轨道 400 / 内容 800 ⇒ thumbLen = 200；pos = 3 + 120/400×194 = 61.2
        $horizontal = new RenderScrollView(120.0, self::ink(), false);
        $horizontal->setChild(new SolidBox(new Size(800.0, 50.0), 800.0, 50.0));
        $horizontal->layout(new BoxConstraints(0.0, 400.0, 0.0, 50.0));
        $hRaster = self::raster($horizontal, 400, 50);
        check(
            'Scroll · 滚动条：横向 ⇒ thumb 贴底侧（x 70..90 有墨、x 5..55 的 thumb 左缘外无墨）',
            self::inkIn($hRaster, 70, 42, 90, 45) > 0 && self::inkIn($hRaster, 5, 42, 55, 45) === 0,
            \sprintf('body=%d left=%d', self::inkIn($hRaster, 70, 42, 90, 45), self::inkIn($hRaster, 5, 42, 55, 45))
        );
    }
}
