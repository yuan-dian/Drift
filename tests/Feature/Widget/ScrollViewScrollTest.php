<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Core\Pipeline;
use Drift\App\Frame;
use Drift\Dev\Preview\SvgSurface;
use Drift\Dev\Tests\Support\ScrollFixtures;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderScrollView;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\ScrollView;
use Drift\Widget\ScrollViewState;
use Drift\Widget\State;
use Drift\Widget\Text;

/**
 * `ScrollView` 的滚动不变量：**不重建**、**偏移真的应用到子盒**、**回夹取值**。
 *
 * ## 为什么单独成文件
 *
 * 这三条原来是 `Table` 段里的（它借 `ScrollView` 当"最纯的一档"做对照），但被测
 * 语义是 `ScrollView`，不是表格 —— 改滚动容器不该去跑表格的夹具。
 *
 * `ScrollView` 比 `Table` 更"纯"的地方：内容（`Column`）是**外部递进来的同一个
 * Widget 实例** ⇒ 没有"窗口"概念 ⇒ 滚动**永远** 0 重建。而 `Table` 有窗口，
 * 滚出缓冲带时重建 1 次是正常的（那条判据在 `Table/ScrollTest.php`）。
 *
 * ★★ **回夹取值只有 `ScrollView` 这一档守得住**：`Table` 的夹取必然伴随换窗 ⇒
 *   重建时 build 里那次"夹取写回"会把变异掩盖掉。同一个语义换个宿主就测不出来，
 *   所以必须挑能证伪它的那个实现（2026-10-28 记忆里的第 21 批教训）。
 */
final class ScrollViewScrollTest extends SmokeTestCase
{
    /** 推一帧：settle + layout（不强制 rebuild） */
    private static function frame(Element $root): void
    {
        $root->settle();
        $root->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));
    }

    private static function stateOf(Element $root): ?ScrollViewState
    {
        return ScrollFixtures::stateOf($root);
    }

    public function testScrollDoesNotRebuildButApplies(): void
    {
        $root = ScrollFixtures::vertical();
        self::frame($root);
        $state = self::stateOf($root);

        $before = Pipeline::current()->rebuiltCount();
        $state?->scrollBy(120.0);
        self::frame($root);
        $delta = Pipeline::current()->rebuiltCount() - $before;

        check(
            'ScrollView · 滚动 ⇒ 重建 0 次（内容不是 build 的输入，本来就不该重建）',
            $delta === 0,
            'rebuilt=' . $delta,
        );
        check(
            'ScrollView · 滚动确实生效（appliedOffset = 120，不是"没滚所以没重建"）',
            $root->effectiveBoxOf(RenderScrollView::class)?->appliedOffset(Axis::Vertical) === 120.0,
            (string) ($root->effectiveBoxOf(RenderScrollView::class)?->appliedOffset(Axis::Vertical) ?? -1.0),
        );
    }

    public function testNextFrameReallyChanges(): void
    {
        // ★★ **端到端判据**：真跑两帧 `Frame`，第二帧的画面必须与第一帧不同。
        //   这条抓的是"改了偏移但没应用到子盒 offset"——`Frame` 会跳过 layout
        //   （没重建 + 约束没变 + 盒没换代），于是 `performLayout()` 里那段
        //   `setOffset()` 不跑 ⇒ 删掉 `setScrollOffset()` 里的 `applyScrollOffsets()`
        //   时**画面停在旧位置**（且不报错、不写日志）。上面那条属性断言抓不到
        //   这个坑（它每帧都手动重排）。
        $surface = new SvgSurface();
        $surface->resize(320, 240, 1.0);

        $rows = [];
        for ($i = 0; $i < 40; $i++) {
            $rows[] = Text::make('行 ' . $i);
        }
        $root = ScrollView::make(Column::make()->children($rows))->createElement();
        $root->mount(null, smokeContext());

        $frame = new Frame($surface);
        $tight = BoxConstraints::tight(new Size(320.0, 240.0));
        $frame->run($root, $tight, 0.0);
        $before = $surface->svg();

        self::stateOf($root)?->scrollBy(120.0);
        $frame->run($root, $tight, 16.0);
        $after = $surface->svg();

        // ★★ 只比 SVG 会**假绿**：滚动条拇指也会动，画面照样"变了"，而内容可以
        //   纹丝不动（2026-10-28 第 26 批实测：把 `applyScrollOffsets()` 改成空转
        //   后，"SVG 变了"仍为真）。所以必须同时钉住**内容子盒的偏移**：
        //   那才是 `applyScrollOffsets()` 唯一的产物。
        $box = $root->effectiveBoxOf(RenderScrollView::class);
        $kids = childBoxes($box);
        $childY = isset($kids[0]) ? $kids[0]->offsetY() : null;

        check(
            'ScrollView · 滚动后**下一帧画面真的变了**（偏移必须应用到子盒 offset，Frame 会跳过 layout）',
            $before !== $after && $before !== '' && $childY === -120.0,
            'before=' . \strlen($before) . ' / after=' . \strlen($after)
                . ' / childY=' . ($childY === null ? 'null' : (string) $childY),
        );
    }

    public function testOffsetClampsBackWhenContentFits(): void
    {
        // 内容装得下时滚 100 ⇒ State 的偏移必须回 0。
        // 不回写盒夹过的值，State 会停在 100（盒画 0），之后每笔增量都先消耗这段
        // 死区 —— 真机现象是"滚轮转了画面不动"。
        $root = ScrollView::make(Column::make()->children([Text::make('一行')]))->createElement();
        $root->mount(null, smokeContext());
        self::frame($root);

        $state = self::stateOf($root);
        $state?->scrollBy(100.0);

        check(
            'ScrollView · 内容装得下时滚动 ⇒ State 的偏移回夹到 0（不许停在 100）',
            $state instanceof ScrollViewState && $state->offset() === 0.0,
            $state instanceof ScrollViewState ? (string) $state->offset() : '无 State',
        );
    }
}
