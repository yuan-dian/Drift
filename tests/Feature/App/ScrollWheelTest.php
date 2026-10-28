<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\Dev\Smoke\App\ScrollApplication;
use Drift\Dev\Smoke\App\TableScrollApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Feature\Table\Fixtures;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\ScrollEvent;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderObject;
use Drift\Layout\RenderScrollView;
use Drift\Layout\RenderTable;
use Drift\Widget\Element;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use Drift\Widget\TableColumnWidth;
use Drift\Widget\TableState;

/**
 * 滚轮的**应用级全链**：`ScrollEvent → dispatchInput → scrollAt → scrollBy →
 * 帧末重建 → 盒偏移`，以及"轴由事件决定"。
 *
 * ## 为什么要跑整条链（而不是直接调 `scrollBy`）
 *
 * 链上每一环都可能静默断掉：事件被吞（格→像素没换算）、命中没找到视口
 * （`scrollAt` 返回 null）、找到了但没标脏（帧末不重建）、重建了但偏移没进盒。
 * 四种断法的**现象完全一样**——滚轮不动，且不报错。所以判据只能落在
 * **帧后的子盒 `offsetY`**：它同时是"链通了"和"偏移到位"的证据。
 *
 * ## 为什么"带轴"用 `Table` 当宿主
 *
 * `Table` 是**双轴**容器（两轴都 `scrollAxes()` 都返回支持），所以"轴"与
 * "容器有没有那根轴"能分开：换轴就该换轴动，跟容器能力无关。用单轴
 * `ScrollView` 当宿主时，"竖向没动"既可能是轴判对了、也可能是它本来就不支持。
 */
final class ScrollWheelTest extends SmokeTestCase
{
    private const W = 300.0;

    private const H = 200.0;

    private static function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(self::W, self::H));
    }

    /** 开一个滚动夹具应用并推首帧（视口 300×200，内容远超视口） */
    private static function open(): ScrollApplication
    {
        $app = new ScrollApplication();
        $app->attach(new SurfaceProbe(), self::constraints());
        $app->frame(16.0, self::constraints());

        return $app;
    }

    private static function childOffset(?RenderScrollView $box): ?float
    {
        if ($box === null) {
            return null;
        }
        $kid = null;
        $box->visitChildren(function (RenderObject $child) use (&$kid): void {
            $kid = $child;
        });

        return $kid instanceof RenderBox ? $kid->offsetY() : null;
    }

    private static function tableBoxOf(?Element $root): ?RenderTable
    {
        $box = $root?->effectiveBoxOf(RenderTable::class);

        return $box instanceof RenderTable ? $box : null;
    }

    /**
     * 全链：视口就绪 → 未滚为 0 → 一格 48 → 第二格累加 → 上下界夹取 → 视口外不滚。
     *
     * ★ 48 是 `WHEEL_NOTCH_PX`（"滚轮不跟手"那次的根因）：判据必须钉住**每格**
     *   的量，而不是"偏移变了" —— 后者在"每格 1px"下也绿。
     * ★ 视口外的点用的是 `y = 250 > 视口高 200`：命中链若退化成"命中整个盒"，
     *   这条会红。
     */
    public function testWheelChainFromEventToChildOffset(): void
    {
        $app = self::open();
        $box = $app->scrollBox();
        $max = $box?->maxScroll(Axis::Vertical) ?? 0.0;
        check(
            'Scroll · 应用：视口盒存在且内容超长（maxScroll > 100 ⇒ 真能滚）',
            $box !== null && $max > 100.0,
            'maxScroll=' . $max
        );
        check(
            'Scroll · 应用：未滚时子盒 offsetY = 0',
            self::childOffset($box) === 0.0,
            (string) (self::childOffset($box) ?? -1.0)
        );

        $app->dispatchInput(ScrollEvent::make(150.0, 100.0, 1.0));
        $app->frame(17.0, self::constraints());
        check(
            'Scroll · 应用：滚轮一格（delta=1.0）⇒ 48px（WHEEL_NOTCH_PX：不跟手的根因修复）',
            self::childOffset($app->scrollBox()) === -48.0,
            'offsetY=' . (self::childOffset($app->scrollBox()) ?? -1.0)
        );

        // ★ 格是相对量 ⇒ 再滚一格累加（96 = 2×48），证明换算的是"每格"而不是一次性
        $app->dispatchInput(ScrollEvent::make(150.0, 100.0, 1.0));
        $app->frame(18.0, self::constraints());
        check(
            'Scroll · 应用：第二格累加（offsetY = −96：每格 48 不是一次 48）',
            self::childOffset($app->scrollBox()) === -96.0,
            'offsetY=' . (self::childOffset($app->scrollBox()) ?? -1.0)
        );

        // ★ `scrollBy` 是**相对**滚动（Δ 累加）⇒ 夹取要用远超当前偏移的量
        $app->dispatchInput(ScrollEvent::make(150.0, 100.0, 999999.0));
        $app->frame(19.0, self::constraints());
        check(
            'Scroll · 应用：滚出上界 ⇒ 夹在 maxScroll（内容不飞出视口）',
            self::childOffset($app->scrollBox()) === -$max,
            'offsetY=' . (self::childOffset($app->scrollBox()) ?? -1.0) . ' max=' . $max
        );

        $app->dispatchInput(ScrollEvent::make(150.0, 100.0, -9999999.0));
        $app->frame(20.0, self::constraints());
        check(
            'Scroll · 应用：滚出下界 ⇒ 夹在 0（scrollBy 的下界夹取生效）',
            self::childOffset($app->scrollBox()) === 0.0,
            'offsetY=' . (self::childOffset($app->scrollBox()) ?? -1.0)
        );

        // 视口外（y=250 > 高 200）⇒ 命不中 ⇒ 偏移纹丝不动
        $app->dispatchInput(ScrollEvent::make(150.0, 100.0, 1.0));
        $app->frame(21.0, self::constraints());
        $before = self::childOffset($app->scrollBox());
        $app->dispatchInput(ScrollEvent::make(150.0, 250.0, 1.0));
        $app->frame(22.0, self::constraints());
        check(
            'Scroll · 应用：轮落在视口外 ⇒ 命不中不滚（偏移不变）',
            self::childOffset($app->scrollBox()) === $before,
            'before=' . ($before ?? -1.0) . ' after=' . (self::childOffset($app->scrollBox()) ?? -1.0)
        );
    }

    /**
     * ★ **轴来自事件，不由容器猜**：同一张双轴表，换轴就换轴动，且互不串味。
     *
     * ★ 第二条（接着发竖轴）是本方法的关键：只发横轴时 `offsetY = 0` 也可能是
     *   "竖轴永远不动"，加一条竖轴之后 `offsetX` 仍须停在 48 才能排除
     *   "后一次覆盖前一次"。
     */
    public function testAxisComesFromTheEvent(): void
    {
        $app = new TableScrollApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
        $app->frame(16.0, BoxConstraints::tight(new Size(320.0, 240.0)));
        $app->dispatchInput(ScrollEvent::make(100.0, 100.0, 1.0, Axis::Horizontal));
        $app->frame(17.0, BoxConstraints::tight(new Size(320.0, 240.0)));
        $box = self::tableBoxOf($app->root());
        check(
            'Table · 滚轮：横轴一格 ⇒ offsetX = 48、offsetY = 0（轴来自事件，不由容器猜）',
            $box?->appliedOffset(Axis::Horizontal) === 48.0
                && $box->appliedOffset(Axis::Vertical) === 0.0,
            'x=' . \sprintf('%.1f', $box?->appliedOffset(Axis::Horizontal) ?? -1.0)
                . ' y=' . \sprintf('%.1f', $box?->appliedOffset(Axis::Vertical) ?? -1.0)
        );

        $app->dispatchInput(ScrollEvent::make(100.0, 100.0, 1.0));
        $app->frame(18.0, BoxConstraints::tight(new Size(320.0, 240.0)));
        $box = self::tableBoxOf($app->root());
        check(
            'Table · 滚轮：接着竖轴一格 ⇒ offsetY = 48 且 offsetX 仍是 48（两轴互不串味）',
            $box?->appliedOffset(Axis::Vertical) === 48.0
                && $box->appliedOffset(Axis::Horizontal) === 48.0,
            'x=' . \sprintf('%.1f', $box?->appliedOffset(Axis::Horizontal) ?? -1.0)
                . ' y=' . \sprintf('%.1f', $box?->appliedOffset(Axis::Vertical) ?? -1.0)
        );
    }

    /**
     * 横向**装得下**时，横轴滚轮一格 ⇒ **不标脏**。
     *
     * ★ 失效形态是"触控板每秒几十次白重建"：不报错、画面也对，只是帧率掉一半。
     *   判据必须落在 `needsBuild()` 上 —— 落在"偏移没变"上是恒真的
     *   （装得下的轴偏移本来就夹在 0）。
     */
    public function testWheelOnNonOverflowingAxisDoesNotDirty(): void
    {
        $narrow = Fixtures::mount(Table::make(
            [
                TableColumn::make('id', 'ID')->field('id')->width(TableColumnWidth::fixed(60.0)),
                TableColumn::make('name', '姓名')->field('name')->width(TableColumnWidth::fixed(60.0)),
            ],
            [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']],
        )->keyOf(static fn(array $row): int => (int) $row['id'])
            ->rowHeight(40.0)->headerHeight(32.0));

        $state = Fixtures::stateOf($narrow);
        $clean = $state?->needsBuild() === false;
        $state?->scrollByAxis(Axis::Horizontal, 48.0);
        check(
            'Table · 滚轮：横向装得下 ⇒ 横轴滚轮不标脏（`contentWInBuild` 早退）',
            $clean && $state?->needsBuild() === false,
            'clean=' . ($clean ? 'yes' : 'no')
                . ' dirty=' . ($state?->needsBuild() === true ? 'yes' : 'no')
        );
    }

    /**
     * ★ **回落只发生在单轴容器**：双轴容器上"竖向没得滚"就是没得滚，
     *   不能因为横向有溢出就横着跑。
     *
     * ★ 也看 State 的**未夹值**：回落一旦发生，`offsetY` 会累加上去
     *   （`appliedOffset` 仍被夹回 0，看不出来），等行数变多会突然跳一下 ——
     *   那是最难查的一类（今天没症状）。
     */
    public function testVerticalWheelDoesNotFallBackOnDualAxis(): void
    {
        $app = new TableScrollApplication(3);   // 3 行 ⇒ 纵向装得下、横向仍溢出
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
        $app->frame(16.0, BoxConstraints::tight(new Size(320.0, 240.0)));
        $app->dispatchInput(ScrollEvent::make(100.0, 100.0, 1.0));
        $app->frame(17.0, BoxConstraints::tight(new Size(320.0, 240.0)));

        $box = self::tableBoxOf($app->root());
        $state = Fixtures::stateOf($app->root());
        check(
            'Table · 滚轮：竖轮落在"纵向装得下、横向溢出"的表上 ⇒ 两轴都不动（双轴不回落）',
            $box?->maxScroll(Axis::Vertical) === 0.0
                && $box->maxScroll(Axis::Horizontal) > 0.0
                && $box->appliedOffset(Axis::Horizontal) === 0.0
                && $box->appliedOffset(Axis::Vertical) === 0.0
                && $state?->offsetY() === 0.0
                && $state->offsetX() === 0.0,
            'maxV=' . \sprintf('%.1f', $box?->maxScroll(Axis::Vertical) ?? -1.0)
                . ' maxH=' . \sprintf('%.1f', $box?->maxScroll(Axis::Horizontal) ?? -1.0)
                . ' raw=(x=' . \sprintf('%.1f', $state?->offsetX() ?? -1.0)
                . ',y=' . \sprintf('%.1f', $state?->offsetY() ?? -1.0) . ')'
        );
    }

    /** 横划不许把页面上下带走：单轴纵向视口收到 Horizontal ⇒ 不动 */
    public function testHorizontalWheelDoesNotMoveSingleAxisVertical(): void
    {
        $app = new ScrollApplication();
        $app->attach(new SurfaceProbe(), self::constraints());
        $app->frame(16.0, self::constraints());
        $app->dispatchInput(ScrollEvent::make(150.0, 100.0, 1.0, Axis::Horizontal));
        $app->frame(17.0, self::constraints());
        check(
            'Scroll · 滚轮：横轴落在纵向视口 ⇒ 不动（只有**竖向**回落到容器唯一那根轴）',
            $app->scrollBox()?->appliedOffset(Axis::Vertical) === 0.0,
            'offsetY=' . \sprintf('%.1f', $app->scrollBox()?->appliedOffset(Axis::Vertical) ?? -1.0)
        );
    }
}
