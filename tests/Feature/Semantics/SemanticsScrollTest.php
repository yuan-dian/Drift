<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Semantics;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\SemanticsRole;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\ScrollController;
use Drift\Widget\ScrollView;
use Drift\Widget\SemanticsBuilder;
use Drift\Widget\SizedBox;
use Drift\Widget\Widget;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 滚动容器的语义数据面 + 动作（UIA `IScrollProvider`）。
 *
 * 数据面：`scrollAxis/scrollOffset/scrollMax/scrollViewport*` 由 `SemanticsBuilder`
 *   从 State 的 `ScrollMetrics` 现读 —— 这组钉住"溢出 / 装得下 / 非滚动容器"三档，
 *   以及滚动后重建能拿到新偏移。
 * 动作：`setSemanticsScroll` 把百分比换算成偏移落到 `ScrollMetrics::scrollTo()`。
 */
final class SemanticsScrollTest extends SmokeTestCase
{
    private function lay(Widget $root): Element
    {
        $el = $root->createElement();
        $el->mount(null, smokeContext());
        $el->settle();
        $el->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));

        return $el;
    }

    /** @return array{axis:int,offset:float,max:float,vw:float,vh:float}|null */
    private function scrollNodeOf(Element $el): ?array
    {
        $tree = SemanticsBuilder::build($el);
        $i = $tree->findByRole(SemanticsRole::Scroll->value);
        if ($i < 0) {
            return null;
        }
        $n = $tree->node($i);

        return [
            'axis' => $n->scrollAxis,
            'offset' => $n->scrollOffset,
            'max' => $n->scrollMax,
            'vw' => $n->scrollViewportW,
            'vh' => $n->scrollViewportH,
        ];
    }

    private function tallContent(int $rows): Column
    {
        $kids = [];
        for ($i = 0; $i < $rows; $i++) {
            $kids[] = SizedBox::make()->width(320.0)->height(60.0);
        }

        return Column::make()->children($kids);
    }

    // ① 内容溢出 ⇒ 滚动容器，主轴垂直，有可滚空间
    public function testOverflowingScrollReportsMax(): void
    {
        $n = $this->scrollNodeOf($this->lay(ScrollView::make($this->tallContent(10))));
        check(
            '滚动语义 · 内容溢出 ⇒ axis=2 / max>0 / 视口高=240',
            $n !== null && $n['axis'] === 2 && $n['max'] > 0.0 && abs($n['vh'] - 240.0) < 1.0,
            json_encode($n),
        );
        $this->flushChecks();
    }

    // ② 内容装得下 ⇒ 仍是滚动容器（axis=2），但 max=0（Scrollable=false 那档）
    public function testFittingContentKeepsAxisWithoutMax(): void
    {
        $n = $this->scrollNodeOf($this->lay(ScrollView::make($this->tallContent(2))));
        check(
            '滚动语义 · 装得下 ⇒ axis=2 / max=0（有概念但不可滚）',
            $n !== null && $n['axis'] === 2 && $n['max'] === 0.0,
            json_encode($n),
        );
        $this->flushChecks();
    }

    // ③ 非滚动控件 ⇒ axis=0（不给 IScrollProvider）
    public function testNonScrollWidgetHasNoAxis(): void
    {
        $n = $this->scrollNodeOf($this->lay(Button::make('保存')));
        check('滚动语义 · 非滚动控件 ⇒ axis=0', $n === null, 'sawScrollNode=' . ($n === null ? 'no' : 'yes'));
        $this->flushChecks();
    }

    // ④ 滚动后重建 ⇒ offset 反映新位置（构建期现读，不吃旧缓存）
    public function testOffsetReflectsAfterScroll(): void
    {
        $ctrl = ScrollController::make();
        $el = $this->lay(ScrollView::make($this->tallContent(10))->controller($ctrl));
        $before = $this->scrollNodeOf($el);
        $ctrl->jumpTo(120.0);
        $after = $this->scrollNodeOf($el);

        check(
            '滚动语义 · 滚动后重建 ⇒ offset 跟随（120）',
            $before !== null && $after !== null
                && $before['offset'] === 0.0 && abs($after['offset'] - 120.0) < 1.0,
            json_encode(['before' => $before, 'after' => $after]),
        );
        $this->flushChecks();
    }

    // ⑤ 动作：纵向 50% ⇒ 偏移落到 max/2；横向请求落到纵向容器 ⇒ 拒绝
    public function testApplicationAppliesScrollPercent(): void
    {
        $ctrl = ScrollController::make();
        $app = new class extends \Drift\App\Application {
            private Widget $testRoot;

            public function setRoot(Widget $widget): void
            {
                $this->testRoot = $widget;
            }

            public function buildRoot(): Widget
            {
                return $this->testRoot;
            }
        };
        $app->setRoot(ScrollView::make($this->tallContent(10))->controller($ctrl));
        $tight = BoxConstraints::tight(new Size(320.0, 240.0));
        $probe = new \Drift\Dev\Smoke\Frame\SurfaceProbe();
        $probe->resize(320, 240, 1.0);
        $app->attach($probe, $tight);

        $seen = [];
        $app->setSemanticsSink(static function (array $nodes) use (&$seen): void {
            $seen = $nodes;
        });
        $app->frame(0.0, $tight);

        $idx = -1;
        foreach ($seen as $i => $n) {
            if (($n['role'] ?? '') === SemanticsRole::Scroll->value) {
                $idx = $i;
            }
        }
        check('滚动语义 · 前置：语义树里有 scroll 节点', $idx >= 0, 'idx=' . $idx);
        if ($idx < 0) {
            $this->flushChecks();

            return;
        }

        $max = $ctrl->maxOffset();
        check(
            '滚动语义 · setSemanticsScroll(纵 50%) ⇒ offset=max/2',
            $app->setSemanticsScroll($idx, -1.0, 50.0) === true
                && $max > 0.0 && abs($ctrl->offset() - $max / 2.0) < 1.0,
            'offset=' . $ctrl->offset() . ' max=' . $max,
        );
        check(
            '滚动语义 · 横向请求落到纵向容器 ⇒ false（不假装成功）',
            $app->setSemanticsScroll($idx, 50.0, -1.0) === false,
        );

        $this->flushChecks();
    }
}
