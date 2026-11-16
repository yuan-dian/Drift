<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Tests\Support\ScrollFixtures;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\ScrollController;
use Drift\Widget\ScrollView;
use Drift\Widget\SizedBox;

/**
 * `ScrollController::animateTo()` 的**消费者视角**判据（P1-4 第一条）。
 *
 * 只走对外句柄 + State 的帧入口，不碰 `ScrollLane` 内部：
 * 「编程式滚动中途取样 offset 单调递增，而不是一步到位」。
 */
final class ScrollControllerAnimateTest extends SmokeTestCase
{
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

    public function testAnimateToSamplesFramesAndNotifiesConsumer(): void
    {
        $ctl = ScrollController::make();
        $root = $this->mount($ctl);
        $state = ScrollFixtures::stateOf($root);

        $seen = [];
        $ctl->onChanged(static function (float $offset, float $_max) use (&$seen): void {
            $seen[] = $offset;
        });

        $ctl->animateTo(300.0, 160.0);   // 上限 600，目标在范围内
        for ($i = 1; $i <= 30; $i++) {
            $state?->tickFrame($i * 16.0);
        }

        $unique = array_values(array_unique($seen));
        $monotonic = true;
        foreach ($seen as $i => $value) {
            if ($i > 0 && $value < $seen[$i - 1]) {
                $monotonic = false;
            }
        }
        check(
            'ScrollController · animateTo 中途取样多帧（消费者看到的不是一步到位）',
            count($unique) > 4,
            'samples=' . count($unique) . ' seen=' . json_encode(array_slice($unique, 0, 8)),
        );
        check(
            'ScrollController · 回调序列单调不回退',
            $monotonic,
            json_encode(array_slice($seen, 0, 8)),
        );
        check(
            'ScrollController · 终点精确落到 300（与 onChanged 读到的同步）',
            $ctl->offset() === 300.0 && end($seen) === 300.0,
            'offset=' . $ctl->offset() . ' last=' . \sprintf('%.6g', (float) end($seen)),
        );

        $root->unmount();
        $this->flushChecks();
    }

    public function testZeroDurationDegradesToJump(): void
    {
        $ctl = ScrollController::make();
        $root = $this->mount($ctl);

        $ctl->animateTo(120.0, 0.0);
        check(
            'ScrollController · duration<=0 等价于 jumpTo（不建动画、当帧到位）',
            $ctl->offset() === 120.0,
            'offset=' . $ctl->offset(),
        );

        $root->unmount();
        $this->flushChecks();
    }
}
