<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Support;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Element;
use Drift\Widget\Widget;

/**
 * 语义树共享夹具：挂载 + 布局一棵 Widget 树，返回已布局的 Element。
 *
 * ★ 几何来自渲染树 ⇒ 必须先布局再建树。这是 `tools/smoke.php` 里 `$semLay`
 *   闭包的同款逻辑，抽到这里让所有控件语义测试共用一份（smoke 仍保留自有
 *   `$semLay`，因为下方 Toggle/Switch/PageIndicator/Icon/SegmentedControl/Slider
 *   的语义断言还在 smoke 里，删了会崩）。
 */
final class Semantics
{
    public static function element(Widget $root): Element
    {
        $el = $root->createElement();
        $el->mount(null, \smokeContext());
        $el->settle();
        $el->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));

        return $el;
    }
}
