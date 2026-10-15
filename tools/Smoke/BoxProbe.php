<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke;

use Drift\Geometry\EdgeInsets;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderPadding;
use Drift\Widget\BoxWidget;
use Drift\Widget\BuildContext;

/**
 * @internal 冒烟夹具：`BoxWidget` 是抽象的，要一个具体子类才能实例化。
 *
 * `createRenderObject()` 给一个零内边距的 `RenderPadding` —— 空壳但**真**产盒，
 * 于是"BoxWidget 的子类确实走 RenderBoxWidget 那条路"也被它兜住了。
 */
final class BoxProbe extends BoxWidget
{
    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderPadding(EdgeInsets::zero());
    }
}
