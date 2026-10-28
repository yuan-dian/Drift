<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

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

        $tableRebuildsBefore = State::rebuiltCount();
        $tableScrollState?->scrollBy(40.0);   // 滚一行：窗仍盖得住 [40, 248)
        $tableFrame($tableScroll);
        $tableScrollDelta = State::rebuiltCount() - $tableRebuildsBefore;
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

        $tableRebuildsBefore = State::rebuiltCount();
        $tableScrollState?->scrollBy(400.0);   // 滚出缓冲带：[440, 648) 超出 [0, 480)
        $tableFrame($tableScroll);
        $tableScrollDelta = State::rebuiltCount() - $tableRebuildsBefore;
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

            return 'marked=' . State::markedCount() . '/' . $read('lastMarkedCount')
                . ' box=' . State::boxEpoch() . '/' . $read('lastBoxEpoch')
                . ' struct=' . Element::structureVersion() . '/' . $read('lastSettleStructure');
        };

        // 同一帧取全部计数：layouts / settle 都是"这一个滚动帧"的属性。
        // ★ 不要拆成两帧各测一条 —— 第二帧的 settle 判据会被第一帧"已经跳过"
        //   的残留状态影响，红的时候分不清是哪一帧的错。
        $layoutsBefore = RenderBox::layoutCount();
        $runsBefore = Frame::settleRunCount();
        $skipsBefore = Frame::settleSkipCount();
        $state?->scrollBy(40.0);
        $frame->run($el, $tight, 16.0);
        $layouts = RenderBox::layoutCount() - $layoutsBefore;
        $deltaRuns = Frame::settleRunCount() - $runsBefore;
        $deltaSkips = Frame::settleSkipCount() - $skipsBefore;

        check(
            'Table · 滚动一帧 ⇒ 重排 0 次（Frame 跳过 layout，paint 读到新 offset）',
            $layouts === 0,
            'layouts=' . $layouts,
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
}
