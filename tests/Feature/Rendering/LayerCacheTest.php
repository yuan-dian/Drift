<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Rendering;

use Drift\Dev\Preview\SvgSurface;
use Drift\Dev\Smoke\Layout\SolidBox;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;

/**
 * 层缓存的**失效判据**（`Rendering\LayerCache`），单元级。
 *
 * ★ 为什么必须在这一层测：`Table` 上那两条（滚动命中 / 换窗失效）**测不到判据**：
 *   ① "换窗"是**换盒** —— 新盒的缓存本来就是空的，判据怎么写都绿；
 *   ② "视口变窄"也测不到 —— `Table` 行区的 size 是**列宽之和**、与视口无关
 *      ⇒ 视口变了缓存仍命中，那是对的（且正是收益）。
 *   ⇒ 只能直接拿**同一棵盒**改 size：epoch 不动，只有尺寸判据能拦住。
 *
 * ★ 反例自检：`cacheValid()` 去掉 `w/h` 比较 ⇒ 第三条红（paint 0）；
 *   去掉 `epoch` 比较 ⇒ 本用例仍绿（epoch 本来就没动）—— 那条由"内容变 ⇒ 重录"
 *   的端到端断言兜（`Feature/Table/LayerCacheTest` 第二条）。
 */
final class LayerCacheTest extends SmokeTestCase
{
    public function testRecordThenHitThenInvalidateOnResize(): void
    {
        $surface = new SvgSurface();
        $surface->resize(200, 200, 1.0);
        $canvas = $surface->beginFrame(0.0);

        $box = new SolidBox(new Size(50.0, 50.0), 50.0, 50.0);
        $box->setRepaintBoundary(true);
        $box->layout(BoxConstraints::tight(new Size(50.0, 50.0)));

        $before = RenderBox::paintCount();
        $box->paintComposed($canvas);
        $painted1 = RenderBox::paintCount() - $before;

        $before = RenderBox::replayCount();
        $box->paintComposed($canvas);   // 尺寸没变 ⇒ 应当命中
        $replayed = RenderBox::replayCount() - $before;

        $box->layout(BoxConstraints::tight(new Size(80.0, 50.0)));   // 同一棵盒，只有 size 变
        $before = RenderBox::paintCount();
        $box->paintComposed($canvas);
        $painted2 = RenderBox::paintCount() - $before;

        check('层缓存 · 首帧录制（paint 1 / replay 0）', $painted1 === 1, "paint={$painted1}");
        check('层缓存 · 尺寸没变 ⇒ 命中（replay 1，同一棵盒不重画）', $replayed === 1, "replay={$replayed}");
        check('层缓存 · 同一棵盒 size 变 ⇒ 失效（paint 1，不许拿旧命令糊上去）', $painted2 === 1, "paint={$painted2}");

        $this->flushChecks();
    }
}
