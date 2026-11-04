<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Tests\Support\ScrollFixtures;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderScrollView;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\ScrollController;
use Drift\Widget\ScrollView;
use Drift\Widget\SizedBox;

/**
 * `ScrollController`：读偏移、命令滚动、位置回调、解绑。
 *
 * 内容与视口取 `ScrollFixtures` 的规格：20 × 40 = 800 高，视口 200 ⇒ 上限 600。
 */
final class ScrollControllerTest extends SmokeTestCase
{
    private const MAX = 600.0;

    /** 挂一棵带控制器的纵向滚动视口，并跑一次布局 */
    private function mount(ScrollController $ctl): Element
    {
        $rows = [];
        for ($i = 0; $i < ScrollFixtures::ROWS; $i++) {
            $rows[] = SizedBox::make()->height(ScrollFixtures::ROW_EXTENT);
        }

        $widget = ScrollView::make(Column::make()->children($rows))->controller($ctl);
        $root = $widget->createElement();
        $root->mount(null, smokeContext());
        $root->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 200.0)));

        return $root;
    }

    public function testReadsAndCommands(): void
    {
        $ctl = ScrollController::make();
        $root = $this->mount($ctl);

        check('ScrollController · 绑定后读得到上限（800 内容 − 200 视口）', $ctl->maxOffset() === self::MAX, (string) $ctl->maxOffset());
        check('ScrollController · 初始偏移为 0', $ctl->offset() === 0.0, (string) $ctl->offset());

        $ctl->jumpToBottom();
        check('ScrollController · jumpToBottom 落到上限', $ctl->offset() === self::MAX, (string) $ctl->offset());

        // 命令要真的作用到盒：只改 State 字段而没推给盒 = 界面不动
        $root->settle();
        $root->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 200.0)));
        check(
            'ScrollController · 盒子真的滚到底（appliedOffset = 上限）',
            $root->effectiveBoxOf(RenderScrollView::class)?->appliedOffset(Axis::Vertical) === self::MAX,
            (string) ($root->effectiveBoxOf(RenderScrollView::class)?->appliedOffset(Axis::Vertical) ?? -1.0),
        );

        $ctl->jumpToTop();
        check('ScrollController · jumpToTop 回到 0', $ctl->offset() === 0.0, (string) $ctl->offset());

        $this->flushChecks();
    }

    public function testJumpIsClampedByBox(): void
    {
        $ctl = ScrollController::make();
        $root = $this->mount($ctl);

        $ctl->jumpTo(1.0e9);
        check('ScrollController · 越界目标被夹到上限（不飞走）', $ctl->offset() === self::MAX, (string) $ctl->offset());

        $ctl->jumpTo(-500.0);
        check('ScrollController · 负目标被夹到 0', $ctl->offset() === 0.0, (string) $ctl->offset());

        $root->settle();

        $this->flushChecks();
    }

    public function testOnChangedDedupes(): void
    {
        $seen = [];
        $ctl = ScrollController::make()->onChanged(static function (float $offset, float $max) use (&$seen): void {
            $seen[] = [$offset, $max];
        });

        $this->mount($ctl);
        check('ScrollController · 绑定时报一次初始位置', $seen === [[0.0, 0.0]], json_encode($seen));

        $ctl->jumpTo(100.0);
        check('ScrollController · 滚动后回调带 (offset, max)', $seen === [[0.0, 0.0], [100.0, self::MAX]], json_encode($seen));

        // ★ 去重：滚动入口会宁可多调 sync()，同值不许重复回调
        $ctl->jumpTo(100.0);
        check('ScrollController · 同值不重复回调', \count($seen) === 2, json_encode($seen));

        $this->flushChecks();
    }

    public function testDetachOnUnmount(): void
    {
        $ctl = ScrollController::make();
        $root = $this->mount($ctl);
        $ctl->jumpTo(300.0);

        $root->unmount();
        check('ScrollController · 容器卸载后解绑（不读死盒）', $ctl->offset() === 0.0, (string) $ctl->offset());

        $this->flushChecks();
    }

    public function testNoControllerIsNoop(): void
    {
        $root = ScrollFixtures::vertical();
        $state = ScrollFixtures::stateOf($root);
        $state?->scrollBy(90.0);
        check('ScrollController · 不传控制器时滚动照旧', $state?->offset() === 90.0, (string) ($state?->offset() ?? -1.0));

        $root->unmount();

        $this->flushChecks();
    }
}
