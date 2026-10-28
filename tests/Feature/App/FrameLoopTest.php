<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Frame;
use Drift\Dev\Smoke\Frame\EpochWidget;
use Drift\Dev\Smoke\Frame\QuietWidget;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Smoke\Frame\TickerWidget;
use Drift\Dev\Smoke\LeafProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Column;
use Drift\Widget\Text;

/**
 * 帧循环：flush → layout → paint → present（§5.2）。
 *
 * ## 三条裁决各有一条判据钉住
 *
 * ① **setState 不立即重建**，`Frame::run` 才消费脏标记 —— 否则事件回调中途重建
 *    会让"同一个回调里读两次数值"读到两代树。
 * ② **时间戳是注入的**，不读环境时钟 ⇒ golden 可复现（G3）。
 * ③ **flush 有脏边界**，不是每帧全树 —— 否则 60fps 就是 60 次全量 build。
 *
 * ## 最后一个方法守的是一条真缺陷（2026-10-24）
 *
 * 帧外 `Element::update()` 换盒**不经过 settle** ⇒ settle 的"帧内重建差"为 0 ⇒
 * Frame 判"本帧没变" ⇒ 跳过 layout ⇒ 新盒 `size` 恒 null ⇒ **之后所有命中测试落空**。
 * 而 overlay 计数 / 焦点 / 声明全对，极难定位。必须用**有状态根**才复现：
 * `StatefulElement::rebuild()` 的 `noteRebuilt()` 发生在 settle 快照**之前**。
 */
final class FrameLoopTest extends SmokeTestCase
{
    public function testSetStateDeferredAndTimestampInjected(): void
    {
        $context = smokeContext();
        $surface = new SurfaceProbe();
        $surface->resize(320, 240, 1.0);
        $frame = new Frame($surface);

        // ★ 根必须是**有盒**的节点（Column）：Frame 在 flush 之后才拿 renderObject，
        //   拿不到就直接返回 —— 这是设计如此（结构根无从排起），不是 bug。
        $rootElement = Column::make()->children([new TickerWidget()])->createElement();
        $rootElement->mount(null, $context);
        $tickerElement = $rootElement->childAt(0);
        check('Frame · 挂载即建出子树', $tickerElement !== null && $tickerElement->childCount() === 1);
        $ticker = $tickerElement?->state();
        check('Frame · 挂载时 State 只建一次', $ticker !== null && $ticker->builds === 1);
        $firstChild = $tickerElement?->childAt(0)?->widget();
        check(
            'Frame · 首帧 build 的产物来自 State 初值',
            $firstChild instanceof LeafProbe && $firstChild->width === 0.0
        );

        $ticker?->tick(); // 只标脏
        check(
            'Frame · setState 之后、Frame 之前 ⇒ 还没重建',
            $ticker !== null && $ticker->builds === 1 && $ticker->needsBuild()
        );

        $frame->run($rootElement, BoxConstraints::tight(new Size(320.0, 240.0)), 16.0);
        check(
            'Frame · 帧末 flush 重建了脏节点',
            $ticker !== null && $ticker->builds === 2 && !$ticker->needsBuild()
        );
        $secondChild = $tickerElement?->childAt(0)?->widget();
        check(
            'Frame · 重建后子树换成新产物（setState → flush → 新 widget）',
            $secondChild instanceof LeafProbe && $secondChild->width === 1.0,
            (string) ($secondChild instanceof LeafProbe ? $secondChild->width : -1.0)
        );

        // 时间戳是**注入**的，不读环境时钟（§5.2 裁决 2 ⇒ G3 的 golden 基础）
        check('Frame · beginFrame 收到注入的时间戳', $surface->lastTimestamp === 16.0);
        check('Frame · beginFrame / endFrame 各一次（不重不漏）', $surface->frames === 1 && $surface->ends === 1);

        $this->flushChecks();
    }

    /** 脏边界：没脏的节点不该被重建 */
    public function testFlushRespectsDirtyBoundary(): void
    {
        $context = smokeContext();
        $surface = new SurfaceProbe();
        $surface->resize(320, 240, 1.0);
        $frame = new Frame($surface);

        $quietElement = (new QuietWidget())->createElement();
        $quietElement->mount(null, $context);
        $quiet = $quietElement->state();
        $frame->run($quietElement, BoxConstraints::tight(new Size(320.0, 240.0)), 32.0);
        check('Frame · 没脏的节点不重建（flush 有脏边界，不是每帧全树）', $quiet->builds === 1);

        $this->flushChecks();
    }

    /** layout + paint 真的跑了：一棵 Column[Text] 有墨上图 */
    public function testLayoutPaintPresentReachSurface(): void
    {
        $context = smokeContext();
        $columnElement = Column::make()->children([Text::make('Hi')])->createElement();
        $columnElement->mount(null, $context);
        $surface2 = new SurfaceProbe();
        $surface2->resize(320, 240, 1.0);
        $frame2 = new Frame($surface2);
        $frame2->run($columnElement, BoxConstraints::tight(new Size(320.0, 240.0)), 48.0);
        check(
            'Frame · layout → paint → present 一路通到表面（画布上有墨）',
            $surface2->raster() !== null && inkCount($surface2->raster()) > 0,
            '墨点数 ' . (string) ($surface2->raster() !== null ? inkCount($surface2->raster()) : -1)
        );

        $this->flushChecks();
    }

    /**
     * 回归（2026-10-24）：帧外换盒必须触发重排（`State::$boxEpoch`）。
     *
     * ★★ **量具边界（2026-10-28 实测确认）**：本方法只验**根盒**的 `hasSize()`，
     *   因此只钉得住 `Frame::run()` 里 layout 段的 `$boxEpoch` 判据；
     *   `needsSettle()` 里的 `$boxEpoch` 判据**拆掉它照样全绿** —— settle 跳过之后
     *   layout 段仍会重排，根盒当然有尺寸。要抓后者必须换成"根 = Column，帧外
     *   `update()` 子节点，再验祖先持有的**子盒**是新的"。源码注释里"拆 ② ⇒ 那条红"
     *   的承诺在本判据下**不成立**，按 S3 的纯搬迁纪律先记录、留 S4 补强。
     */
    public function testOutOfFrameBoxSwapStillLaysOut(): void
    {        $context = smokeContext();
        $epSurface = new SurfaceProbe();
        $epSurface->resize(320, 240, 1.0);
        $epFrame = new Frame($epSurface);
        $epRoot = (new EpochWidget())->createElement();
        $epRoot->mount(null, $context);
        $epFrame->run($epRoot, BoxConstraints::tight(new Size(320.0, 240.0)), 0.0);
        check('Frame · 首帧排过（根盒有尺寸）', $epRoot->effectiveBox()?->hasSize() === true);

        $epRoot->update(new EpochWidget());   // 帧外换盒：不经过 settle
        $epFrame->run($epRoot, BoxConstraints::tight(new Size(320.0, 240.0)), 16.0);
        $epBox = $epRoot->effectiveBox();
        check(
            'Frame · 帧外换盒（update 直调）⇒ 新盒照样排过（不跳过 layout）',
            $epBox !== null && $epBox->hasSize(),
            $epBox === null ? 'effectiveBox=null' : ($epBox->hasSize() ? 'ok' : 'size=null')
        );

        $this->flushChecks();
    }
}
