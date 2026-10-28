<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Layout;

use Drift\Dev\Smoke\Layout\SolidBox;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\Overflow;
use Drift\Layout\RenderDock;
use Drift\Layout\RenderProxy;
use Drift\Rendering\Modifier;
use Drift\Widget\AppBar;
use Drift\Widget\Column;
use Drift\Widget\Dock;
use Drift\Widget\NavButton;
use Drift\Widget\Padding;
use Drift\Widget\Text;

/**
 * 多页外壳的**布局**半边：`RenderDock` 五槽几何 + 无界主轴的自然高。
 *
 * ## 为什么用裸 `RenderDock` 而不是搭一棵树
 *
 * 五槽几何是**纯算术**（"谁先占、剩下多少"），用 `SolidBox` 直接摆五个已知尺寸
 * 的槽，判据就是五个确定的矩形 —— 树里任何一层（`Dock` / `AppBar` / `Column`）
 * 出问题都不会污染它。元素层的挂接另有方法（`testElementMountAndSlots`）。
 *
 * ## `expectBox()` 四个数一起断
 *
 * 原来的写法只断 `offsetX/Y` + 部分尺寸 —— left 槽的 x 和宽、right 槽的宽都没人管，
 * 而"整体偏一个 padding"正是这类断言最容易漏的错（偏了之后相对偏移全对）。
 *
 * ## 量具自检
 *
 * 五个 `expectBox()` 全绿只说明"它们都对"，**不说明"助手会红"**。恒绿的助手会让
 * 所有依赖它的断言一起假绿，且永远查不出来 ⇒ `testExpectBoxFailsOnWrongExpectation`
 * 故意喂三种错，把"会红"也变成断言。
 */
final class DockTest extends SmokeTestCase
{
    /** 五槽各占已知尺寸的 dock（top 20 / bottom 15 / left 30 / right 25 / center 45） */
    private static function fiveSlotDock(): RenderDock
    {
        $dock = new RenderDock(Overflow::Report);
        $dock->setSlot('top', new SolidBox(new Size(100.0, 20.0), 100.0, 20.0));
        $dock->setSlot('bottom', new SolidBox(new Size(100.0, 15.0), 100.0, 15.0));
        $dock->setSlot('left', new SolidBox(new Size(30.0, 65.0), 30.0, 65.0));
        $dock->setSlot('right', new SolidBox(new Size(25.0, 65.0), 25.0, 65.0));
        $dock->setSlot('center', new SolidBox(new Size(45.0, 65.0), 45.0, 65.0));
        $dock->layout(BoxConstraints::tight(new Size(100.0, 100.0)));

        return $dock;
    }

    /**
     * 五槽几何：上/下全宽贴边、左右中带全高贴边、中心填满剩余矩形。
     *
     * ★ 五个矩形的数值是**推**出来的，不是拟合：top 先占 0..20；bottom 占 85..100；
     *   left 占 x 0..30、y 20..85（中带高 65）；right 占 x 75..100；center 拿
     *   剩下的 30..75 × 20..85。
     */
    public function testFiveSlots(): void
    {
        $dock = self::fiveSlotDock();

        expectBox($dock->slot('top'), 0.0, 0.0, 100.0, 20.0, 'Dock · top 槽：全宽贴顶、拿自然高');
        expectBox($dock->slot('bottom'), 0.0, 85.0, 100.0, 15.0, 'Dock · bottom 槽：全宽贴底（100−15）');
        expectBox($dock->slot('left'), 0.0, 20.0, 30.0, 65.0, 'Dock · left 轨：中带全高、贴左');
        expectBox($dock->slot('right'), 75.0, 20.0, 25.0, 65.0, 'Dock · right 轨：贴右（100−25）');
        expectBox($dock->slot('center'), 30.0, 20.0, 45.0, 65.0, 'Dock · center 槽：tight 填满剩余矩形（45×65 @ 30,20）');
    }

    /**
     * 量具自检：`expectBox()` 喂错期望时**真的会红**（三条覆盖三种失败路径）。
     *
     * ★ 三种路径必须各一条：原点不符（算术错）/ 盒为 null（没挂上）/ 盒没 layout。
     *   只验第一种的话，"null 时静默放行"这种实现照样绿。
     * ★ 用 `takeFailures()` 读回而不是跑子进程：`check()` 只记录不抛，所以故意
     *   制造的失败能当场取走，不会污染本方法末尾的 `flushChecks()`。
     */
    public function testExpectBoxFailsOnWrongExpectation(): void
    {
        $probe = new SolidBox(new Size(10.0, 6.0), 10.0, 6.0);
        $host = new RenderProxy();
        $host->setMods((new Modifier())->withPadding(4.0, 2.0, 4.0, 2.0));
        $host->setChild($probe);
        $host->layout(BoxConstraints::loose(new Size(60.0, 60.0)));

        // 真实矩形是 (4, 2) 10×6 —— 这里故意把 x 写成 5
        expectBox($probe, 5.0, 2.0, 10.0, 6.0, '量具反例 · 原点差 1px');
        expectBox(null, 0.0, 0.0, 10.0, 6.0, '量具反例 · 盒为 null');
        expectBox(new SolidBox(new Size(10.0, 6.0), 10.0, 6.0), 0.0, 0.0, 10.0, 6.0, '量具反例 · 没 layout');

        $failures = $this->takeFailures();
        $joined = \implode("\n", $failures);

        check(
            '量具自检 · expectBox 给错期望时会红（三种失败路径各一条，消息带期望与实际）',
            \count($failures) === 3
                && \str_contains($joined, '期望 (5, 2) 10×6，实际 (4, 2) 10×6')
                && \str_contains($joined, '盒为 null（没挂上 / 没 layout）')
                && \str_contains($joined, '盒 size() 为 null（layout 没跑）'),
            \count($failures) . ' 条：' . \str_replace("\n", ' ⏎ ', $joined)
        );
    }

    /**
     * 无界主轴：Dock 自身高取**内容自然高**，不是 INF。
     *
     * 触发形状 = `ScrollView` 给内容的约束（主轴无界，"先量有多长"）。
     * `SolidBox` 的 intrinsic 与 box 尺寸**故意不同**（center: box 50 / intrinsic 77）
     * ⇒ 这条夹具同时钉住"midH 真的来自 `intrinsicHeight`"。
     *
     * 四条缺一不可：
     * ① 自身高有限且 = 20 + max(40,77,10) + 15 = 112（不是 INF）；
     * ② bottom 的 offsetY = topH + midH = 97（**旧行为正是 INF**）；
     * ③ 中带三槽铺满 midH=77（不是各自自然高、也不是 INF）；
     * ④ 有界行为不动（tight 100×100 的数值原样）。
     */
    public function testUnboundedMainAxis(): void
    {
        $dock = new RenderDock(Overflow::Report);
        $dock->setSlot('top', new SolidBox(new Size(100.0, 20.0), 100.0, 20.0));
        $dock->setSlot('bottom', new SolidBox(new Size(100.0, 15.0), 100.0, 15.0));
        $dock->setSlot('left', new SolidBox(new Size(30.0, 40.0), 30.0, 40.0));
        // intrinsic 77 > 左 40 / 右 10 ⇒ max 取 77（而它的 box 高只有 50）
        $dock->setSlot('center', new SolidBox(new Size(45.0, 50.0), 45.0, 77.0));
        $dock->setSlot('right', new SolidBox(new Size(25.0, 10.0), 25.0, 10.0));
        $size = $dock->layout(new BoxConstraints(0.0, 100.0, 0.0, INF));

        check(
            'Dock · 无界主轴：自身高 = 内容自然高 112（20+max(40,77,10)+15），不是 INF',
            $size->height === 112.0,
            \var_export($size->height, true)
        );
        check(
            'Dock · 无界主轴：bottom offsetY = 97（topH+midH，不是 INF）',
            $dock->slot('bottom')?->offsetY() === 97.0,
            \var_export($dock->slot('bottom')?->offsetY(), true)
        );
        check(
            'Dock · 无界主轴：中带三槽铺满 midH=77（来自 center 的 intrinsicHeight）',
            $dock->slot('left')?->size()?->height === 77.0
                && $dock->slot('center')?->size()?->height === 77.0
                && $dock->slot('right')?->size()?->height === 77.0
        );

        // ④ 有界行为不动 —— 用**新** dock 复跑，不蹭上面那个的残留
        $bounded = self::fiveSlotDock();
        check(
            'Dock · 无界主轴：有界行为不动（复跑 tight 100x100 五槽数值不变）',
            $bounded->slot('bottom')?->offsetY() === 85.0 && $bounded->slot('center')?->offsetY() === 20.0
        );
    }

    /**
     * 元素层：`Dock::make()` 挂载即建出 `RenderDock`、槽盒按声明挂接、全树封存。
     *
     * ★ "未声明的槽为 null"这条不能省：若实现给没声明的槽也造一个空盒，
     *   布局会凭空多占 0 宽/高的轨道 —— 几何上看不出来，但 `slot('left')` 的
     *   消费者（滚动条 / 侧栏）会以为"有左轨"。
     */
    public function testElementMountAndSlots(): void
    {
        $root = Dock::make()
            ->top(AppBar::make()->title('外壳')->children([NavButton::make('列表')->command('nav.users')]))
            ->center(Column::make()->children([Text::make('内容')]))
            ->createElement();
        $root->mount(null, smokeContext());
        $box = $root->effectiveBox();

        check('Dock · 挂载即建出 RenderDock', $box instanceof RenderDock);
        check(
            'Dock · 槽盒按声明挂接（top/center 有盒，left/right/bottom 为空）',
            $box instanceof RenderDock
                && $box->slot('top') !== null && $box->slot('center') !== null
                && $box->slot('left') === null && $box->slot('right') === null && $box->slot('bottom') === null
        );
        check('Dock · 挂载后全树封存', $root->widget()->isSealed());
    }

    /**
     * 条的宽拉满 / 轨的高铺满（§12.1 第 3 条"全宽横条 / 中带全高竖轨"）。
     *
     * ★ 两条一起才钉住"轨道是条带"而不是"普通流式子项"：只验宽度的话，
     *   实现把 top 当普通 flex 子项也能过（宽度可能凑巧一样），但 left 轨的
     *   高度会退化成内容高。
     */
    public function testBarsStretchToFill(): void
    {
        $root = Dock::make()
            ->top(AppBar::make()->title('条')->children([NavButton::make('a')->command('x')]))
            ->left(Padding::make(Text::make('轨'))->all(4.0))
            ->center(Text::make('内容'))
            ->createElement();
        $root->mount(null, smokeContext());
        $box = $root->effectiveBox();
        $box?->layout(BoxConstraints::tight(new Size(300.0, 200.0)));

        check(
            'Dock · 横条拉满全宽（AppBar 300 宽，不是自然宽）',
            $box instanceof RenderDock && $box->slot('top')?->size()?->width === 300.0,
            (string) ($box instanceof RenderDock ? $box->slot('top')?->size()?->width : -1.0)
        );
        check(
            'Dock · 竖轨铺满中带高（轨高 = 200 − 条高）',
            $box instanceof RenderDock
                && $box->slot('left')?->size()?->height === 200.0 - ($box->slot('top')?->size()?->height ?? 0.0)
        );
    }
}
