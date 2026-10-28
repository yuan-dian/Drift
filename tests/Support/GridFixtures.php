<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Support;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderFlex;
use Drift\Widget\Element;
use Drift\Widget\Row;
use Drift\Widget\Widget;

/**
 * `Grid` / `LazyGrid` 的共享夹具（W8-3）。
 *
 * ★ 下沉理由（MEMORY：夹具的第二个使用者出现就下沉）：`rowBoxes` / `cellWidths`
 *   同时被 `GridTest` 与 `LazyGridTest` 使用；抄两份 = 两份会漂的闭包。
 */
final class GridFixtures
{
    public static function mount(Widget $widget, float $width = 336.0, float $height = 240.0): Element
    {
        $element = $widget->createElement();
        $element->mount(null, smokeContext());
        $element->effectiveBox()?->layout(BoxConstraints::tight(new Size($width, $height)));

        return $element;
    }

    /** 树里所有 `Row` 的渲染盒（文档顺序 = 从上到下） @return list<RenderFlex> */
    public static function rowBoxes(Element $root): array
    {
        $out = [];
        $walk = static function (Element $e) use (&$walk, &$out): void {
            if ($e->widget() instanceof Row) {
                $box = $e->renderObject();
                if ($box instanceof RenderFlex) {
                    $out[] = $box;
                }
            }
            $e->visitChildren($walk);
        };
        $walk($root);

        return $out;
    }

    /** 一行的每个子盒宽度（`Expanded` 的代理盒 ⇒ 就是格宽） @return list<float> */
    public static function cellWidths(RenderFlex $row): array
    {
        $out = [];
        foreach ($row->children() as $kid) {
            $size = $kid->size();
            $out[] = $size === null ? -1.0 : $size->width;
        }

        return $out;
    }
}
