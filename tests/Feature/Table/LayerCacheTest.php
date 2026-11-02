<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Core\Pipeline;
use Drift\App\Frame;
use Drift\Dev\Preview\SvgSurface;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;

/**
 * 行区的层缓存（A 批② `RepaintBoundary`）：**滚动帧命中、换窗帧失效**。
 *
 * ★ 又是只能靠计数器：缓存的判据是"等价替换"⇒ 命中与否**逐像素完全相同**，
 *   golden 与 SVG 对它零分辨力（与 `layoutCount` 同一类困境）。
 * ★ 两条一起才有效：只断言"命中"会被"永远命中"骗过（那等于画错），
 *   所以换窗那条必须反过来要求**未命中**。
 *
 * ★ 反例自检：`cacheValid()` 去掉 size 判据 ⇒ 单元级那条红（见
 *   `Feature/Rendering/LayerCacheTest`，"换窗"这条抓不到 —— 新盒缓存本就是空的）；
 *   给行区撤掉 `repaintBoundary` ⇒ 下面命中那条红（paint 数 > 0）。
 */
final class LayerCacheTest extends SmokeTestCase
{
    public function testScrollFrameHitsAndRewindowFrameMisses(): void
    {
        $surface = new SvgSurface();
        $surface->resize(320, 240, 1.0);
        $el = Fixtures::build(10000)->createElement();
        $el->mount(null, smokeContext());
        $frame = new Frame($surface);
        $tight = BoxConstraints::tight(new Size(Fixtures::VIEW_W, Fixtures::VIEW_H));
        $frame->run($el, $tight, 0.0);
        $state = Fixtures::stateOf($el);

        // ① 滚动帧：内容没变，只是位置变了 ⇒ 边界全部命中
        $paintsBefore = Pipeline::current()->paintCount();
        $replaysBefore = Pipeline::current()->replayCount();
        $state?->scrollBy(40.0);
        $frame->run($el, $tight, 16.0);
        $paints = Pipeline::current()->paintCount() - $paintsBefore;
        $replays = Pipeline::current()->replayCount() - $replaysBefore;

        check(
            'Table · 滚动帧 ⇒ 边界全部命中层缓存（真画 0 次，且确实重放了）',
            // ★ 断言"真画 0"而不是"重放 N"：前者才是**不变量**（一处都没重画），
            //   后者会随边界数量变（开几处就写几，改一处断言就得跟着改）。
            $paints === 0 && $replays >= 1,
            "replay={$replays} paint={$paints}",
        );

        // ② 换窗帧：内容真的变了 ⇒ 必须重排 + 必须重录
        $layoutsBefore = Pipeline::current()->layoutCount();
        $paintsBefore = Pipeline::current()->paintCount();
        $state?->scrollBy(400.0);   // 滚出缓冲带 ⇒ 换窗 ⇒ 重建 + 换盒
        $frame->run($el, $tight, 32.0);
        $layouts = Pipeline::current()->layoutCount() - $layoutsBefore;
        $paints = Pipeline::current()->paintCount() - $paintsBefore;

        check(
            'Table · 滚出缓冲带换窗 ⇒ 重排 > 0（新行必须有 size，不许借"不重排"省掉）',
            $layouts > 0,
            'layouts=' . $layouts,
        );
        // ★ 反向守卫：没有这条，"判据恒真 ⇒ 永远命中"会是一张永久的绿卡，而画面是旧的。
        check(
            'Table · 换窗帧 ⇒ 缓存失效（真画 ≥1 次，不许拿旧命令糊上去）',
            $paints >= 1,
            "paint={$paints}",
        );

        $this->flushChecks();
    }
}
