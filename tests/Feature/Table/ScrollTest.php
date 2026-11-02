<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Core\Pipeline;
use Drift\App\Frame;
use Drift\Dev\Preview\SvgSurface;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Widget\Element;
use Drift\Widget\State;

/**
 * 滚动的三条不变量：**不重建**（A 批第 1 项）、**不重排**、settle **整段跳过**（A 批③）。
 *
 * ★ 这三条全靠计数器，画面上零痕迹 —— 这正是它们能藏那么久的原因。改坏了
 *   golden 与 SVG 都不动，只有这里会红。
 * ★ 与 `Phase1Test` 的区别：那边走 `pump()`（强制 rebuild + layout），
 *   对"该不该重建/重排"**恒为'该'**，测不到；这边必须走 `Frame::run()`。
 *
 * ★ 反例自检：`scrollBy()` 改回 `setState()` ⇒ 重建断言红；把
 *   `applyScrollOffsets()` 从 `setScrollOffset()` 里删掉 ⇒ 偏移断言红（画面不动）；
 *   把 `setScrollOffset()` 改回 `markNeedsLayout()` ⇒ 重排断言红。
 */
final class ScrollTest extends SmokeTestCase
{
    public function testScrollInsideCacheExtentDoesNotRebuild(): void
    {
        // 别名而不是逐个改名：段代码里 `$tableBuild(10000)` 这类调用一个字都不用动。
        $tableBuild = Fixtures::build(...);
        $tableMount = Fixtures::mount(...);
        $tableStateOf = Fixtures::stateOf(...);
        $tableBoxOf = Fixtures::boxOf(...);
        $tableFrame = Fixtures::frame(...);

        $tableScroll = $tableMount($tableBuild(10000));
        $tableScrollState = $tableStateOf($tableScroll);
        $tableFrame($tableScroll);   // 首帧排定：此刻窗 = [0, 12×40 = 480)

        $tableRebuildsBefore = Pipeline::current()->rebuiltCount();
        $tableScrollState?->scrollBy(40.0);   // 滚一行：窗仍盖得住 [40, 248)
        $tableFrame($tableScroll);
        $tableScrollDelta = Pipeline::current()->rebuiltCount() - $tableRebuildsBefore;
        check(
            'Table · 滚动一行（缓冲带内）⇒ 重建 0 次',
            $tableScrollDelta === 0,
            'rebuilt=' . $tableScrollDelta,
        );
        check(
            'Table · 滚动确实生效（盒的 appliedOffset 从 0 变 40，不是"什么都没发生"）',
            $tableBoxOf($tableScroll)?->appliedOffset(Axis::Vertical) === 40.0,
            (string) ($tableBoxOf($tableScroll)?->appliedOffset(Axis::Vertical) ?? -1.0),
        );

        $tableRebuildsBefore = Pipeline::current()->rebuiltCount();
        $tableScrollState?->scrollBy(400.0);   // 滚出缓冲带：[440, 648) 超出 [0, 480)
        $tableFrame($tableScroll);
        $tableScrollDelta = Pipeline::current()->rebuiltCount() - $tableRebuildsBefore;
        check(
            'Table · 滚出缓冲带 ⇒ 恰好重建 1 次（窗口必须换，这不是缺陷）',
            $tableScrollDelta === 1,
            'rebuilt=' . $tableScrollDelta,
        );
        check(
            'Table · 换窗后新偏移仍然生效（440）',
            $tableBoxOf($tableScroll)?->appliedOffset(Axis::Vertical) === 440.0,
            (string) ($tableBoxOf($tableScroll)?->appliedOffset(Axis::Vertical) ?? -1.0),
        );

        $this->flushChecks();
    }

    public function testScrollFrameSkipsLayoutAndSettle(): void
    {
        $tableBuild = Fixtures::build(...);
        $tableStateOf = Fixtures::stateOf(...);
        $tableBoxOf = Fixtures::boxOf(...);

        $surface = new SvgSurface();
        $surface->resize(320, 240, 1.0);
        $el = $tableBuild(10000)->createElement();
        $el->mount(null, smokeContext());
        $frame = new Frame($surface);
        $tight = BoxConstraints::tight(new Size(Fixtures::VIEW_W, Fixtures::VIEW_H));
        $frame->run($el, $tight, 0.0);   // 首帧：约束变了 ⇒ 必排
        $state = $tableStateOf($el);

        /** 诊断：settle 三信号的「当前 / Frame 记下的上一帧」对比（都是私有字段，用反射读） */
        $signals = static function (Frame $f): string {
            // ★ 不调 `setAccessible(true)`：PHP 8.1 起私有属性可直接读，8.5 已把它标废弃。
            $read = static fn(string $name): int => (int) new \ReflectionProperty(Frame::class, $name)->getValue($f);

            return 'marked=' . Pipeline::current()->markedCount() . '/' . $read('lastMarkedCount')
                . ' box=' . Pipeline::current()->layoutEpoch() . '/' . $read('lastBoxEpoch')
                . ' struct=' . Pipeline::current()->structureVersion() . '/' . $read('lastSettleStructure');
        };

        // 同一帧取全部计数：layouts / settle 都是"这一个滚动帧"的属性。
        // ★ 不要拆成两帧各测一条 —— 第二帧的 settle 判据会被第一帧"已经跳过"
        //   的残留状态影响，红的时候分不清是哪一帧的错。
        $layoutsBefore = Pipeline::current()->layoutCount();
        $runsBefore = Pipeline::current()->settleRunCount();
        $skipsBefore = Pipeline::current()->settleSkipCount();
        $paintsBefore = Pipeline::current()->paintRunCount();
        $state?->scrollBy(40.0);
        $frame->run($el, $tight, 16.0);
        $layouts = Pipeline::current()->layoutCount() - $layoutsBefore;
        $deltaRuns = Pipeline::current()->settleRunCount() - $runsBefore;
        $deltaSkips = Pipeline::current()->settleSkipCount() - $skipsBefore;
        $deltaPaints = Pipeline::current()->paintRunCount() - $paintsBefore;

        check(
            'Table · 滚动一帧 ⇒ 重排 0 次（Frame 跳过 layout，paint 读到新 offset）',
            $layouts === 0,
            'layouts=' . $layouts,
        );
        // ★★ **滚动帧必须真画**（2026-10-30，N1）：滚动走的是"不重建不重排"的
        //   快捷通道 ⇒ settle / layout 两条判据都拦不住它。以前靠"paint 无条件跑"
        //   兜底，现在 paint 有门控 ⇒ 全靠 `setOffset()` 标脏。这条断言是那条
        //   依赖的**唯一守卫**：删掉 `setOffset()` 里的 `markNeedsPaint()`，
        //   上面"0 重排"照绿、画面却停在旧位置 —— 就是这个测试存在的理由。
        check(
            'Table · 滚动帧必须真画（N1 门控下，滚动是"变了"的一帧）',
            $deltaPaints === 1,
            'paints=' . $deltaPaints,
        );
        // ★ 滚动帧什么都不变（不重建、不换盒、不增删节点）⇒ settle 整段跳过。
        //   没有这条，上面"0 重排"也可能是"判据恒真、一次没跳过"的假绿。
        check(
            'Table · 同一帧 settle 整段跳过（跑 0 次、跳 1 次）',
            $deltaRuns === 0 && $deltaSkips === 1,
            "run={$deltaRuns} skip={$deltaSkips} " . $signals($frame),
        );
        check(
            'Table · 上面那一帧确实滚了（appliedOffset=40，不是"没滚所以没重排"）',
            $tableBoxOf($el)?->appliedOffset(Axis::Vertical) === 40.0,
            (string) ($tableBoxOf($el)?->appliedOffset(Axis::Vertical) ?? -1.0),
        );

        $this->flushChecks();
    }

    /**
     * 内容装得下时滚 100 ⇒ State 的偏移必须回夹到 0（不许停在 100）。
     *
     * ★ 这条是**弱档**：`Table` 的夹取必然伴随换窗 ⇒ 重建时 build 里那次"夹取写回"
     *   会把变异掩盖掉，所以它证明不了"`setScrollOffset()` 的返回值救了它"。
     *   强档在 `Widget/ScrollViewScrollTest::testOffsetClampsBackWhenContentFits`
     *   （`ScrollView` 没有窗口概念 ⇒ 只有返回值能救）。两档都留：这里守"用户可见
     *   的行为"，那边守"机制"。
     */
    public function testOffsetClampsBackWhenContentFits(): void
    {
        $tableFrame = Fixtures::frame(...);

        $root = Fixtures::mount(Fixtures::build(3));   // 3 × 40 = 120 < 行区 208
        $state = Fixtures::stateOf($root);
        $tableFrame($root);
        $state?->scrollBy(100.0);
        $tableFrame($root);

        check(
            'Table · 内容装得下时滚动 ⇒ State 的偏移回夹到 0（不许停在 100）',
            $state?->offsetY() === 0.0,
            (string) ($state?->offsetY() ?? -1.0),
        );

        $this->flushChecks();
    }
}
