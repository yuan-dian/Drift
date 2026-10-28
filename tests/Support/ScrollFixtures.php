<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Support;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\ScrollViewState;
use Drift\Widget\SizedBox;
use Drift\Widget\StatefulElement;

/**
 * 单轴 `ScrollView` 的夹具：一个纵向、一个横向，内容都**明显超出视口**。
 *
 * ★ 内容必须真的可滚：`scrollByAxis` 的判据是"偏移变了没有"，
 *   装得下的容器里偏移恒 0，错轴与对轴算出来一样 ⇒ 断言验不出东西。
 * ★ 两个方向的规格刻意不同（纵向视口 200 高、横向视口 320 宽），
 *   这样"取的是 width 还是 height"也能分得开。
 */
final class ScrollFixtures
{
    public const ROWS = 20;
    public const ROW_EXTENT = 40.0;

    /** 纵向：20 × 40 = 内容 800 高，视口 320×200 */
    public static function vertical(): Element
    {
        $rows = [];
        for ($i = 0; $i < self::ROWS; $i++) {
            $rows[] = SizedBox::make()->height(self::ROW_EXTENT);
        }

        return self::mount(ScrollView::make(Column::make()->children($rows)), 320.0, 200.0);
    }

    /** 横向：20 × 40 = 内容 800 宽，视口 320×100 */
    public static function horizontal(): Element
    {
        $cells = [];
        for ($i = 0; $i < self::ROWS; $i++) {
            $cells[] = SizedBox::make()->width(self::ROW_EXTENT);
        }

        return self::mount(ScrollView::make(Row::make()->children($cells), false), 320.0, 100.0);
    }

    public static function stateOf(Element $root): ?ScrollViewState
    {
        $hit = findElement(
            $root,
            static fn(Element $e): bool => $e instanceof StatefulElement && $e->state() instanceof ScrollViewState,
        );
        $state = $hit instanceof StatefulElement ? $hit->state() : null;

        return $state instanceof ScrollViewState ? $state : null;
    }

    private static function mount(ScrollView $widget, float $width, float $height): Element
    {
        $element = $widget->createElement();
        $element->mount(null, smokeContext());
        $element->effectiveBox()?->layout(BoxConstraints::tight(new Size($width, $height)));

        return $element;
    }
}
