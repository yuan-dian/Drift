<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Application;
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
 *
 * ★ 失败消息里的数字一律 `%.6g`（**不是** `%.1f`）：断言是 `=== 48.0`，差 0.01
 *   也红，而 `%.1f` 会把 `47.9896` 打成 `48.0` ⇒ 消息与结论自相矛盾
 *   （"显示 48.0 却判红"）。这是变异自检时真踩到的。
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

        $ts = 16.0;
        $app->dispatchInput(ScrollEvent::make(150.0, 100.0, 1.0));
        // ★ 两帧：`AnimatedValue` 首帧只记起点（不推进），第二帧才开始走
        $app->frame(17.0, self::constraints());
        $app->frame(18.0, self::constraints());
        $ts = 18.0;
        $part = self::childOffset($app->scrollBox());
        check(
            'Scroll · 应用：滚轮第二帧**只走一部分**（0 < |Δ| < 48 ⇒ 是滑过去，不是闪现）',
            $part !== null && $part < 0.0 && $part > -48.0,
            'part=' . ($part ?? 0.0)
        );

        pumpScroll($app, self::constraints(), $ts);
        check(
            'Scroll · 应用：滚轮一格（delta=1.0）⇒ 收口后 48px（WHEEL_NOTCH_PX：不跟手的根因修复）',
            self::childOffset($app->scrollBox()) === -48.0,
            'offsetY=' . (self::childOffset($app->scrollBox()) ?? -1.0)
        );

        // ★ 格是相对量 ⇒ 再滚一格累加（96 = 2×48），证明换算的是"每格"而不是一次性
        $app->dispatchInput(ScrollEvent::make(150.0, 100.0, 1.0));
        pumpScroll($app, self::constraints(), $ts);
        check(
            'Scroll · 应用：第二格累加（offsetY = −96：每格 48 不是一次 48）',
            self::childOffset($app->scrollBox()) === -96.0,
            'offsetY=' . (self::childOffset($app->scrollBox()) ?? -1.0)
        );

        // ★ `scrollBy` 是**相对**滚动（Δ 累加）⇒ 夹取要用远超当前偏移的量
        $app->dispatchInput(ScrollEvent::make(150.0, 100.0, 999999.0));
        pumpScroll($app, self::constraints(), $ts);
        check(
            'Scroll · 应用：滚出上界 ⇒ 夹在 maxScroll（内容不飞出视口）',
            self::childOffset($app->scrollBox()) === -$max,
            'offsetY=' . (self::childOffset($app->scrollBox()) ?? -1.0) . ' max=' . $max
        );

        $app->dispatchInput(ScrollEvent::make(150.0, 100.0, -9999999.0));
        pumpScroll($app, self::constraints(), $ts);
        check(
            'Scroll · 应用：滚出下界 ⇒ 夹在 0（scrollBy 的下界夹取生效）',
            self::childOffset($app->scrollBox()) === 0.0,
            'offsetY=' . (self::childOffset($app->scrollBox()) ?? -1.0)
        );

        // 视口外（y=250 > 高 200）⇒ 命不中 ⇒ 偏移纹丝不动
        $app->dispatchInput(ScrollEvent::make(150.0, 100.0, 1.0));
        pumpScroll($app, self::constraints(), $ts);
        $before = self::childOffset($app->scrollBox());
        $app->dispatchInput(ScrollEvent::make(150.0, 250.0, 1.0));
        pumpScroll($app, self::constraints(), $ts);
        check(
            'Scroll · 应用：轮落在视口外 ⇒ 命不中不滚（偏移不变）',
            self::childOffset($app->scrollBox()) === $before,
            'before=' . ($before ?? -1.0) . ' after=' . (self::childOffset($app->scrollBox()) ?? -1.0)
        );
    }

    /**
     * ★ **连续滚轮是"累加目标"，不是"重启动画"**。
     *
     * `glideByAxis()` 在动画**在跑时**刻意**不** `snapTo($offset)`：那一对齐会把
     * 第一格还没走完的距离丢掉，改成"从当前位置再走一格" ⇒ 两格只走了"一格多一点"。
     * 现象是"快滚时越滚越慢"，不报错、不崩、手感不对，而且**单格测试全绿**。
     *
     * ★ 判据落在**总位移 = 96**（两格之和）。落在"还在动"上是恒真的
     * （重启动画也在动），落在"第二格也动了"上也分不出两种实现。
     */
    public function testRapidWheelNotchesAccumulateWithoutRestart(): void
    {
        $app = self::open();
        $c = self::constraints();
        $ts = 16.0;

        $app->dispatchInput(ScrollEvent::make(150.0, 100.0, 1.0));
        // 只推 4 帧：让第一格**跑到一半**（半衰期 55ms ⇒ 48ms 时约走 45%）
        for ($i = 0; $i < 4; ++$i) {
            $ts += 16.0;
            $app->frame($ts, $c);
        }
        $mid = self::childOffset($app->scrollBox());
        check(
            'Scroll · 动画：第一格跑到一半（−48 < offsetY < 0）⇒ 下面"累加"才有意义',
            $mid !== null && $mid < 0.0 && $mid > -48.0,
            'mid=' . ($mid ?? 0.0)
        );

        // 半路再来一格：目标必须从 48 累加到 96，而不是从"当前位置"重新起跑
        $app->dispatchInput(ScrollEvent::make(150.0, 100.0, 1.0));
        pumpScroll($app, $c, $ts);
        check(
            'Scroll · 动画：动画中再滚一格 ⇒ 目标累加（收口 −96，不是"从半路再走 48"）',
            self::childOffset($app->scrollBox()) === -96.0,
            'offsetY=' . (self::childOffset($app->scrollBox()) ?? -1.0)
        );
    }

    /**
     * ★★ **滚到边界就收口**：否则 motion 每帧都朝一个**到不了**的目标收敛。
     *
     * ★ 这里必须说清一件容易想当然的事：**帧时钟最终会停**，不会真的永动 ——
     *   `AnimatedValue` 是指数衰减，再远的目标也会在两百帧内落进 `EPSILON`。
     *   所以"永不停摆"是**错的**描述，真实代价是**多转一百多帧**：
     *   每帧白跑一次 `tickFrame` + 一次全量 paint，滚动条也跟着多亮一百多帧。
     *   ⇒ 断言只能落在"**多久**落闸"，落在"会不会落闸"上恒真。
     *
     * ★ 帧预算的来历（实测，不是凑的）：收口后 81 帧落闸（这一段的时长由
     *   `ScrollBarFade` 决定：900ms 空闲 + 一段衰减）；不收口是 190 帧。
     *   取 120 帧：距 81 有 39 帧余量，距 190 有 70 帧余量，两侧都不擦边。
     */
    public function testHittingBoundaryStopsTheFrameClock(): void
    {
        $app = self::open();
        $c = self::constraints();
        $ts = 16.0;
        $max = $app->scrollBox()?->maxScroll(Axis::Vertical) ?? 0.0;

        // 一格远超可滚距离 ⇒ 目标落在 maxScroll 之外（必然"推不动"）
        $app->dispatchInput(ScrollEvent::make(150.0, 100.0, 1.0e6));
        pumpScroll($app, $c, $ts, 120);

        check(
            'Scroll · 动画：滚出上界 ⇒ 停在 maxScroll（夹取仍由 scrollBy 统一做）',
            self::childOffset($app->scrollBox()) === -$max,
            'offsetY=' . (self::childOffset($app->scrollBox()) ?? -1.0) . ' max=' . $max
        );
        check(
            'Scroll · 动画：到边界后**收口** ⇒ 120 帧内帧时钟落闸（不收口要 190 帧）',
            $app->wantsFrame() === false,
            'wantsFrame=' . ($app->wantsFrame() ? 'true' : 'false')
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
        $c = BoxConstraints::tight(new Size(320.0, 240.0));
        $ts = 16.0;
        $app->dispatchInput(ScrollEvent::make(100.0, 100.0, 1.0, Axis::Horizontal));
        pumpScroll($app, $c, $ts);
        $box = self::tableBoxOf($app->root());
        check(
            'Table · 滚轮：横轴一格 ⇒ offsetX = 48、offsetY = 0（轴来自事件，不由容器猜）',
            $box?->appliedOffset(Axis::Horizontal) === 48.0
                && $box->appliedOffset(Axis::Vertical) === 0.0,
            'x=' . \sprintf('%.6g', $box?->appliedOffset(Axis::Horizontal) ?? -1.0)
                . ' y=' . \sprintf('%.6g', $box?->appliedOffset(Axis::Vertical) ?? -1.0)
        );

        $app->dispatchInput(ScrollEvent::make(100.0, 100.0, 1.0));
        pumpScroll($app, $c, $ts);
        $box = self::tableBoxOf($app->root());
        check(
            'Table · 滚轮：接着竖轴一格 ⇒ offsetY = 48 且 offsetX 仍是 48（两轴互不串味）',
            $box?->appliedOffset(Axis::Vertical) === 48.0
                && $box->appliedOffset(Axis::Horizontal) === 48.0,
            'x=' . \sprintf('%.6g', $box?->appliedOffset(Axis::Horizontal) ?? -1.0)
                . ' y=' . \sprintf('%.6g', $box?->appliedOffset(Axis::Vertical) ?? -1.0)
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
        $c = BoxConstraints::tight(new Size(320.0, 240.0));
        $ts = 16.0;
        $app->attach(new SurfaceProbe(), $c);
        $app->frame(16.0, $c);
        $app->dispatchInput(ScrollEvent::make(100.0, 100.0, 1.0));
        pumpScroll($app, $c, $ts);

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
            'maxV=' . \sprintf('%.6g', $box?->maxScroll(Axis::Vertical) ?? -1.0)
                . ' maxH=' . \sprintf('%.6g', $box?->maxScroll(Axis::Horizontal) ?? -1.0)
                . ' raw=(x=' . \sprintf('%.6g', $state?->offsetX() ?? -1.0)
                . ',y=' . \sprintf('%.6g', $state?->offsetY() ?? -1.0) . ')'
        );
    }

    /** 横划不许把页面上下带走：单轴纵向视口收到 Horizontal ⇒ 不动 */
    public function testHorizontalWheelDoesNotMoveSingleAxisVertical(): void
    {
        $app = new ScrollApplication();
        $c = self::constraints();
        $ts = 16.0;
        $app->attach(new SurfaceProbe(), $c);
        $app->frame(16.0, $c);
        $app->dispatchInput(ScrollEvent::make(150.0, 100.0, 1.0, Axis::Horizontal));
        pumpScroll($app, $c, $ts);
        check(
            'Scroll · 滚轮：横轴落在纵向视口 ⇒ 不动（只有**竖向**回落到容器唯一那根轴）',
            $app->scrollBox()?->appliedOffset(Axis::Vertical) === 0.0,
            'offsetY=' . \sprintf('%.6g', $app->scrollBox()?->appliedOffset(Axis::Vertical) ?? -1.0)
        );
    }
}
