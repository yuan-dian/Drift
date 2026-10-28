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
}
