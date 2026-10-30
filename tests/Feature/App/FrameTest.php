<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Frame;
use Drift\Dev\Preview\SvgSurface;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Text;

/**
 * `Frame` 的 settle 判据（A 批③）：什么时候**可以整段跳过**。
 *
 * ★ 方向是反的 —— 通常测"该跳过时跳过"；这里测"**不该跳过时不许跳过**"。
 *   因为跳错了的后果是静默的：新盒没人挂、命中测试全落空、画面是旧的、不报错。
 *
 * ★ 三个信号（标脏序号 / 盒换代 / 结构版本）里，这条只走"盒换代"：
 *   帧外直调 `update()` 是 `syncBox()` + `rebuild()`，**不标脏**（没有 setState），
 *   所以只有 `boxEpoch` 能拦住。三个信号少一个，这条就红。
 */
final class FrameTest extends SmokeTestCase
{
    public function testSettleRunsWhenBoxReplacedOutsideFrame(): void
    {
        $surface = new SvgSurface();
        $surface->resize(200, 100, 1.0);
        $el = Text::make('a')->createElement();
        $el->mount(null, smokeContext());
        $frame = new Frame($surface);
        $tight = BoxConstraints::tight(new Size(200.0, 100.0));
        $frame->run($el, $tight, 0.0);

        $runsBefore = Frame::settleRunCount();
        $el->update(Text::make('b'));
        $frame->run($el, $tight, 16.0);
        $runs = Frame::settleRunCount() - $runsBefore;

        check(
            '帧外直调 update() 换盒 ⇒ settle 必须跑（无 setState，只靠盒换代拦）',
            $runs === 1,
            "run={$runs}",
        );

        $this->flushChecks();
    }

    /** 对照组：什么都不变 ⇒ 必须跳过。没有这条，"从不跳过"也能让上面那条绿。 */
    public function testSettleSkipsWhenNothingChanged(): void
    {
        $surface = new SvgSurface();
        $surface->resize(200, 100, 1.0);
        $el = Text::make('a')->createElement();
        $el->mount(null, smokeContext());
        $frame = new Frame($surface);
        $tight = BoxConstraints::tight(new Size(200.0, 100.0));
        $frame->run($el, $tight, 0.0);

        $runsBefore = Frame::settleRunCount();
        $skipsBefore = Frame::settleSkipCount();
        $frame->run($el, $tight, 16.0);
        $runs = Frame::settleRunCount() - $runsBefore;
        $skips = Frame::settleSkipCount() - $skipsBefore;

        check(
            '无任何变化的一帧 ⇒ settle 整段跳过（跑 0 / 跳 1）',
            $runs === 0 && $skips === 1,
            "run={$runs} skip={$skips}",
        );

        $this->flushChecks();
    }

    // ------------------------------------------------------------------
    // N1 · 泵帧分离：paint 的门控（2026-10-30）
    //
    // ★ 这一组的形态与上面两条**相反**：settle 那两条防的是"该跑却跳过"
    //   （后果是静默的旧画面）。paint 这一组两边都要防 ——
    //   ① 什么都没变还画 ⇒ 空闲窗口每秒 60 次整帧 blit（要治的病）；
    //   ② 变了却没画 ⇒ 静默的旧画面（比病更贵）。
    //   所以每条"跳到"的断言都配一条"该画时必须画"的对照组。
    // ------------------------------------------------------------------

    public function testPaintSkipsWhenNothingChanged(): void
    {
        $surface = new SvgSurface();
        $surface->resize(200, 100, 1.0);
        $el = Text::make('a')->createElement();
        $el->mount(null, smokeContext());
        $frame = new Frame($surface);
        $tight = BoxConstraints::tight(new Size(200.0, 100.0));

        $frame->run($el, $tight, 0.0);
        $drawn = $surface->frameCount();

        $runsBefore = Frame::paintRunCount();
        $skipsBefore = Frame::paintSkipCount();
        $frame->run($el, $tight, 16.0);
        $frame->run($el, $tight, 32.0);

        check(
            '无任何变化的两帧 ⇒ paint 整段跳过（跑 0 / 跳 2，表面一帧都没起）',
            Frame::paintRunCount() - $runsBefore === 0
                && Frame::paintSkipCount() - $skipsBefore === 2
                && $surface->frameCount() === $drawn,
            'run=' . (Frame::paintRunCount() - $runsBefore)
                . ' skip=' . (Frame::paintSkipCount() - $skipsBefore)
                . ' drawn=' . ($surface->frameCount() - $drawn),
        );

        $this->flushChecks();
    }

    /**
     * `setCompose()` —— 动画的**主力通道**（rebuild 太贵，动画直接写盒）。
     *
     * ★ 这条是 N1 里最险的一处：它的失败形态与 2026-10-29 那个"值在滑、
     *   屏上是死的"**一模一样**（那次是层缓存命中，这次是整帧被跳过）——
     *   同一个现象两个成因，谁遇上都会先怀疑错地方。
     */
    public function testPaintRunsWhenComposeChanges(): void
    {
        $surface = new SvgSurface();
        $surface->resize(200, 100, 1.0);
        $el = Text::make('a')->createElement();
        $el->mount(null, smokeContext());
        $frame = new Frame($surface);
        $tight = BoxConstraints::tight(new Size(200.0, 100.0));
        $frame->run($el, $tight, 0.0);

        $box = $el->effectiveBox();
        check('夹具前提：根盒拿得到', $box !== null);

        // 对照组：同值写一次 ⇒ 不该画（`applyCompose` 的早退，静止画面不许重录）
        $runsBefore = Frame::paintRunCount();
        $box?->setCompose(1.0);
        $frame->run($el, $tight, 16.0);
        $sameValueRuns = Frame::paintRunCount() - $runsBefore;

        // 真变了 ⇒ 必须画
        $runsBefore = Frame::paintRunCount();
        $box?->setCompose(0.5);
        $painted = $frame->run($el, $tight, 32.0);
        $changedRuns = Frame::paintRunCount() - $runsBefore;

        check(
            'setCompose 写同值 ⇒ 不画；真改 alpha ⇒ 必须画（且返回 true）',
            $sameValueRuns === 0 && $changedRuns === 1 && $painted,
            "same={$sameValueRuns} changed={$changedRuns} painted=" . var_export($painted, true),
        );

        $this->flushChecks();
    }

    /**
     * hover 过渡 —— 动画住在**盒**上（没有 `State`），推进时不 `setState`。
     *
     * ★ 首帧 tick 只记起点、不推进值（`AnimatedValue::tick` 的 `lastTs === null`
     *   那条路）⇒ **值没变就不该标脏**。所以这里连推两次：第一次不画、第二次画。
     *   若判据写成"`tick()` 返回 true 就标"，第一次就会误画（每次 hover 多一帧）。
     */
    public function testPaintRunsWhenHoverTransitionAdvances(): void
    {
        $surface = new SvgSurface();
        $surface->resize(200, 100, 1.0);
        $el = Text::make('a')->createElement();
        $el->mount(null, smokeContext());
        $frame = new Frame($surface);
        $tight = BoxConstraints::tight(new Size(200.0, 100.0));
        $frame->run($el, $tight, 0.0);

        $box = $el->effectiveBox();
        $box?->setHovered(true);

        // 第一次 tick：只记起点（值不变）⇒ 不该画
        $runsBefore = Frame::paintRunCount();
        $box?->tickAnimations(16.0);
        $frame->run($el, $tight, 16.0);
        $firstTickRuns = Frame::paintRunCount() - $runsBefore;

        // 第二次 tick：dt > 0 ⇒ 值真的动了 ⇒ 必须画
        $runsBefore = Frame::paintRunCount();
        $box?->tickAnimations(32.0);
        $frame->run($el, $tight, 32.0);
        $secondTickRuns = Frame::paintRunCount() - $runsBefore;

        check(
            'hover 过渡：起点帧不画、推进帧必须画',
            $firstTickRuns === 0 && $secondTickRuns === 1,
            "first={$firstTickRuns} second={$secondTickRuns}",
        );

        $this->flushChecks();
    }

    /**
     * 应用级叠加（焦点环 / 面板落后）—— **不经过树**，`Frame` 看不见。
     *
     * ★ 这是"树判据"的边界：`paintRequested` 是唯一由外面说进来的信号。
     *   没有它，键盘 Tab 换焦点后环要等下一次鼠标移动（真机现象，
     *   `wantsFrame()` 第七判据的原始动机），而 N1 会把它变成"永远不出现"。
     */
    public function testPaintRequestedForcesPaint(): void
    {
        $surface = new SvgSurface();
        $surface->resize(200, 100, 1.0);
        $el = Text::make('a')->createElement();
        $el->mount(null, smokeContext());
        $frame = new Frame($surface);
        $tight = BoxConstraints::tight(new Size(200.0, 100.0));
        $frame->run($el, $tight, 0.0);

        $drawn = $surface->frameCount();
        $painted = $frame->run($el, $tight, 16.0, [], null, true);

        check(
            'paintRequested ⇒ 树没变也必须画（应用级叠加的唯一入口）',
            $painted && $surface->frameCount() === $drawn + 1,
            'painted=' . var_export($painted, true) . ' drawn=' . ($surface->frameCount() - $drawn),
        );

        $this->flushChecks();
    }
}
