<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Preview\Renderer;
use Drift\Dev\Preview\SvgSurface;
use Drift\Dev\Smoke\App\LazyApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\LazyFixtures;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Size;
use Drift\Input\ScrollEvent;
use Drift\Layout\RenderBox;
use Drift\Theme\ThemeData;
use Drift\Widget\Column;
use Drift\Widget\Each;
use Drift\Widget\Element;
use Drift\Widget\LazyColumn;
use Drift\Widget\LazyList;
use Drift\Widget\LazyListState;
use Drift\Widget\LazyRow;
use Drift\Widget\Row;
use Drift\Widget\SizedBox;
use Drift\Widget\Scrollable;
use Drift\Widget\ScrollView;
use Drift\Widget\ScrollViewState;
use Drift\Widget\StatefulElement;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * `LazyColumn` / `LazyRow` 的**虚拟化**判据（`Widget/Lazy*`）。
 *
 * 虚拟化的定义是"**没看见的行不存在**"：没有 Widget、没有 Element、没有盒。
 * 三线判据 ——
 * ① **窗口只覆盖视口**：5000 / 20000 行都只造 5 行（视口 240 ÷ 行高 56）。
 *    全量列表随行数**线性**变慢（`_build/bench-list3.php` 实测：20000 行
 *    mount+layout 2.8s），虚拟列表与行数无关。
 * ② **坐标链**：窗在完整内容里的起点（`sliceOrigin`）进子盒 offset ⇒
 *    "画在哪 = 点在哪"（与 padding / 滚动偏移同一条纪律）。
 * ③ **绘制透明**：同一偏移下 SVG 与全量列表**逐字节一致** —— 虚拟化不许改观感。
 *
 * ★ 反例自检三发：① 窗口改成"全部行" ⇒ 前两条红（行数变 5000）；
 *   ② `sliceOrigin` 恒置 0 ⇒ "滚一格 ⇒ 子盒 offsetY = −8" 红（会得 −48）；
 *   ③ `maxScroll` 按窗长算 ⇒ 可滚上限红（280 而不是 1119760）。
 */
final class LazyListTest extends SmokeTestCase
{
    public function testWindowCoversOnlyTheViewport(): void
    {
        // ① 视口 240 / 行高 56 ⇒ 5 行（ceil(240/56) = 5）
        $small = LazyFixtures::mount(LazyFixtures::column(5000, 56.0));
        check(
            'Lazy · 窗口只造视口覆盖的行（5000 行 ⇒ 5 行，不是 5000）',
            LazyFixtures::childCount($small, Column::class) === 5,
            (string) LazyFixtures::childCount($small, Column::class),
        );

        // ② 与行数无关：20000 行仍然 5 行（这是虚拟化与全量列表的分界判据）
        $big = LazyFixtures::mount(LazyFixtures::column(20000, 56.0));
        check(
            'Lazy · 窗口行数与总行数无关（20000 行 ⇒ 仍 5 行）',
            LazyFixtures::childCount($big, Column::class) === 5,
            (string) LazyFixtures::childCount($big, Column::class),
        );
    }

    public function testMaxScrollUsesFullContentNotWindow(): void
    {
        // ③ 20000×56 − 240 = 1119760（按窗长算会得到 280 —— 滚动条会假装"只有一屏"）
        $big = LazyFixtures::mount(LazyFixtures::column(20000, 56.0));
        $max = $big->effectiveBox()?->maxScroll(Axis::Vertical) ?? -1.0;
        check(
            'Lazy · maxScroll = 完整内容 − 视口（20000×56−240 = 1119760，不是窗长）',
            $max === 1119760.0,
            (string) $max,
        );
    }

    public function testSliceOriginEntersTheCoordinateChain(): void
    {
        $big = LazyFixtures::mount(LazyFixtures::column(20000, 56.0));

        // ④ 滚到 400：第 7 行是窗的第一行（400 ÷ 56 = 7.14）⇒ 窗起点 392
        //    ⇒ 子盒 offsetY = −(400 − 392) = −8（★ sliceOrigin 的正负号判据）
        $state = LazyFixtures::stateOf($big);
        $state?->scrollBy(400.0);
        LazyFixtures::settle($big);
        $column = LazyFixtures::childBoxOf($big, Column::class);
        check(
            'Lazy · 滚动 400 ⇒ 窗起点 392、子盒 offsetY = −8（窗在完整内容里的位置进坐标链）',
            $column?->offsetY() === -8.0 && $state?->offset() === 400.0,
            'offsetY=' . ($column?->offsetY() ?? -1.0) . ' offset=' . ($state?->offset() ?? -1.0),
        );

        // ⑤ 命中跟随窗：视口 y=10 落在第 7 行（−8..48）⇒ 命中盒的绝对 y 必须也是 −8
        $hit = $big->effectiveBox()?->hitTest(100.0, 10.0);
        $hitY = $hit === null ? null : boxOrigin($hit)[1];
        check(
            'Lazy · 命中跟随窗：视口 (100,10) 命中第 7 行，绝对 y = −8（画在哪 = 点在哪）',
            $hitY === -8.0,
            $hitY === null ? 'miss' : (string) $hitY,
        );
    }

    public function testLeadingPaddingIsPartOfContent(): void
    {
        // ⑥ offset 0 / pad.top 24 ⇒ 第一行画在 y = 24
        $padded = LazyFixtures::mount(LazyFixtures::column(100, 56.0, EdgeInsets::only(0.0, 24.0, 0.0, 0.0)));
        check(
            'Lazy · 上内边距是内容的一部分（pad.top 24 ⇒ 子盒下移 +24）',
            LazyFixtures::childBoxOf($padded, Column::class)?->offsetY() === 24.0,
            (string) (LazyFixtures::childBoxOf($padded, Column::class)?->offsetY() ?? -1.0),
        );
    }

    public function testPaintIsTransparentVersusFullList(): void
    {
        // ⑦ 同一偏移下 SVG 与全量列表逐字节一致
        //    ★ 先跑一发预热：`DeterministicFontManager` 的字形表是**懒加载**的，第一次
        //      排版会顺带把它建起来（建表前后的度量能差几像素）⇒ 不预热的话"第一份
        //      SVG 与第二份不同"会伪装成虚拟化的缺陷。
        $this->svgAt(0.0, true);

        $same = true;
        $sizes = [];
        foreach ([0.0, 400.0] as $offset) {
            $each = $this->svgAt($offset, false);
            $virtual = $this->svgAt($offset, true);
            $same = $same && $each === $virtual;
            $sizes[] = sprintf('%s: %d/%d', $offset, strlen($each), strlen($virtual));
        }
        check(
            'Lazy · 绘制透明：offset 0 与 400 下与全量列表的 SVG 逐字节一致',
            $same,
            implode(' · ', $sizes),
        );
    }

    public function testWheelRoutesToLazyListState(): void
    {
        // ⑧ 滚轮经 `Scrollable` 路由到 `LazyListState`（接口的第一个真实消费者）
        $app = new LazyApplication();
        $c = BoxConstraints::loose(new Size(300.0, 200.0));
        $ts = 16.0;
        $app->attach(new SurfaceProbe(), $c);
        $app->dispatchInput(ScrollEvent::make(150.0, 100.0, 1.0));   // 一格 = 48px
        pumpScroll($app, $c, $ts);   // 滚轮是动画（`glideByAxis`）⇒ 跑到收口
        check(
            'Lazy · 滚轮一格 ⇒ offset 48（`Scrollable` 接口路由到 LazyListState）',
            $app->lazyState()?->offset() === 48.0,
            (string) ($app->lazyState()?->offset() ?? -1.0),
        );

        // 行高 20 ⇒ 窗起点 floor(48/20)×20 = 40 ⇒ 子盒 offsetY = −8
        check(
            'Lazy · 滚轮后窗滑两行（起点 40 ⇒ 子盒 offsetY = −8）',
            $app->scrollBox()?->maxScroll(Axis::Vertical) === 9800.0
                && findElement(
                    $app->root(),
                    static fn(Element $e): bool => $e->widget() instanceof Column,
                )?->renderObject()?->offsetY() === -8.0,
            'maxScroll=' . ($app->scrollBox()?->maxScroll(Axis::Vertical) ?? -1.0),
        );
    }

    public function testHorizontalWindowSizesByWidth(): void
    {
        // ⑨–⑬ 横向（`LazyRow`）：轴是**一个参数**，不是一份拷贝。
        //   ★ 判据选"确定会变"的量：视口 320 **宽** / 格 56 ⇒ 6 列（ceil(320/56)）；
        //     纵向同规格是 5 行（240/56）—— 两个数字不同本身就是"取的是 width 不是
        //     height"的证据（若误用纵向公式，这里会得 5）。
        $big = LazyFixtures::mount(LazyFixtures::row(20000, 56.0));
        check(
            'LazyRow · 窗口只造视口覆盖的列（20000 项 ⇒ 6 列，按 width 算不是 height）',
            LazyFixtures::childCount($big, Row::class) === 6,
            (string) LazyFixtures::childCount($big, Row::class),
        );

        $max = $big->effectiveBox()?->maxScroll(Axis::Horizontal) ?? -1.0;
        check(
            'LazyRow · maxScroll 按完整内容宽算（20000×56−320 = 1119680）',
            $max === 1119680.0,
            (string) $max,
        );
    }

    public function testHorizontalScrollAndHitFollowTheWindow(): void
    {
        $big = LazyFixtures::mount(LazyFixtures::row(20000, 56.0));

        $state = LazyFixtures::stateOf($big);
        $state?->scrollBy(400.0);
        LazyFixtures::settle($big);
        $cell = LazyFixtures::childBoxOf($big, Row::class);
        check(
            'LazyRow · 滚动 400 ⇒ 窗起点 392、子盒 offsetX = −8 且 offsetY = 0（横向不许动 y）',
            $cell?->offsetX() === -8.0 && $cell?->offsetY() === 0.0,
            'offsetX=' . ($cell?->offsetX() ?? -1.0) . ' offsetY=' . ($cell?->offsetY() ?? -1.0),
        );

        $hit = $big->effectiveBox()?->hitTest(10.0, 100.0);
        $hitX = $hit === null ? null : boxOrigin($hit)[0];
        check(
            'LazyRow · 命中跟随窗：视口 (10,100) 命中第 7 列，绝对 x = −8（画在哪 = 点在哪）',
            $hitX === -8.0,
            $hitX === null ? 'miss' : (string) $hitX,
        );
    }

    public function testHorizontalPaddingAndVerticalWheelFallback(): void
    {
        // 横向的"主轴起始"是**左**（不是上）：pad.left 进 sliceOrigin
        $padded = LazyFixtures::mount(LazyFixtures::row(100, 56.0, EdgeInsets::only(24.0, 0.0, 0.0, 0.0)));
        check(
            'LazyRow · 左内边距是内容的一部分（pad.left 24 ⇒ 子盒右移 +24）',
            LazyFixtures::childBoxOf($padded, Row::class)?->offsetX() === 24.0,
            (string) (LazyFixtures::childBoxOf($padded, Row::class)?->offsetX() ?? -1.0),
        );

        // 竖轮是**默认手势**：横向容器只有一根轴 ⇒ 回落到那根（阶段 5b 明确保留，
        // 与 Windows 横向 ListView 同款）。不回落的话 `LazyRow` 的滚动就只剩
        // "代码看起来对"（F8 那类"缺的是驱动方"的同族）。
        $app = new LazyApplication(false);
        $c = BoxConstraints::loose(new Size(300.0, 200.0));
        $ts = 16.0;
        $app->attach(new SurfaceProbe(), $c);
        $app->dispatchInput(ScrollEvent::make(150.0, 100.0, 1.0));
        pumpScroll($app, $c, $ts);   // 滚轮是动画（`glideByAxis`）⇒ 跑到收口
        check(
            'LazyRow · 竖轮一格 ⇒ offset 48（竖向回落到容器唯一那根轴，不由调用方猜）',
            $app->lazyState()?->offset() === 48.0,
            (string) ($app->lazyState()?->offset() ?? -1.0),
        );
    }

    public function testCellExtentIsWidthNotHeight(): void
    {
        // ⑭ 格宽真的是 extent。★ 这条补的是"窗口算得对、排出来却是竖的"那种半对：
        //   若 `sizedCell()` 横向也写成 `->height($extent)`，上面几条**全都还是绿的**
        //   （窗口只看 extent 这个数，不看子盒实际尺寸）⇒ 必须有一条量几何。
        $big = LazyFixtures::mount(LazyFixtures::row(20000, 56.0));
        $width = findElement(
            $big,
            static fn(Element $e): bool => $e->widget() instanceof SizedBox,
        )?->renderObject()?->size()?->width;
        check(
            'LazyRow · 每格宽 = itemExtent 56（定的是 width，不是 height）',
            $width === 56.0,
            (string) ($width ?? -1.0),
        );
    }

    public function testOverscanDecidesWhetherScrollingRebuilds(): void
    {
        // 缓冲带由 `overscan()` 显式给（**默认 0**，与 `Table::cacheExtent` 默认 200
        // 不同 —— 那边是数据面、行高 40+ 所以 200px 只是 4~5 行；这边格高可能很小，
        // 按 px 给固定值会把 6 格的列表撑成 16 格）。两档都断言，把默认行为写实：
        //   ① 默认 overscan 0 ⇒ 滚一行就换窗（1 次重建）—— 不是缺陷，是没配缓冲
        //   ② overscan 200 ⇒ 缓冲带内 0 重建（与表格同一条机制）
        [$delta0, $offset0] = $this->rebuildsOf(LazyFixtures::keyed(LazyFixtures::range(0, 60), false), 40.0);
        check(
            'LazyColumn · 默认（overscan 0）滚一行 ⇒ 重建 1 次（没配缓冲，行为写实）',
            $delta0 === 1 && $offset0 === 40.0,
            'rebuilt=' . $delta0 . ' / offset=' . $offset0,
        );

        [$delta200, $offset200] = $this->rebuildsOf(
            LazyFixtures::keyed(LazyFixtures::range(0, 60), false)->overscan(200.0),
            40.0,
        );
        check(
            'LazyColumn · overscan 200 ⇒ 缓冲带内滚一行重建 0 次（与表格同一机制）',
            $delta200 === 0 && $offset200 === 40.0,
            'rebuilt=' . $delta200 . ' / offset=' . $offset200,
        );
    }

    private function svgAt(float $offset, bool $virtual): string
    {
        $rows = LazyFixtures::range(0, 300);
        $rowOf = static fn(int $i): Widget => SizedBox::make(Text::make('行 ' . $i))->height(56.0);

        $root = $virtual
            ? LazyColumn::make(56.0)->items($rows, $rowOf)
            : ScrollView::make(
                Column::make()->children([Each::make(static fn(int $i): int => $i)->items($rows, $rowOf)])
            );

        return (new Renderer(new SvgSurface(), smokeContext()->metrics(), ThemeData::light()))->render(
            $root,
            320.0,
            240.0,
            1.0,
            static function (Element $el) use ($offset, $virtual): void {
                if ($virtual) {
                    $st = findElement(
                        $el,
                        static fn(Element $e): bool => $e instanceof StatefulElement && $e->state() instanceof LazyListState,
                    )?->state();
                } else {
                    $st = findElement(
                        $el,
                        static fn(Element $e): bool => $e instanceof StatefulElement && $e->state() instanceof ScrollViewState,
                    )?->state();
                }
                if ($st instanceof Scrollable) {
                    $st->scrollBy($offset);
                }
            },
        );
    }

    /** ① 默认 overscan 0 ⇒ 滚一行就换窗；② overscan 200 ⇒ 缓冲带内 0 重建 */
    private function rebuildsOf(LazyColumn $widget, float $delta): array
    {
        $root = $widget->createElement();
        $root->mount(null, smokeContext());
        $root->settle();
        $root->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));

        $state = LazyFixtures::stateOf($root);
        $before = \Drift\Widget\State::rebuiltCount();
        $state?->scrollBy($delta);
        $root->settle();
        $root->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));

        return [
            \Drift\Widget\State::rebuiltCount() - $before,
            $root->effectiveBoxOf(\Drift\Layout\RenderScrollView::class)?->appliedOffset(Axis::Vertical) ?? -1.0,
        ];
    }
}
