<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;

/**
 * 居中盒：把孩子摆在**内容区**正中。
 *
 * ★ 另一轴无界时不居中（无界轴上"中心"不存在）⇒ 偏移 0，不产生 NaN。
 *   这是无界约束下所有居中算法的同一个坑，收在一处。
 *
 * @api
 */
final class RenderCenter extends RenderProxyBox
{
    protected function performLayout(BoxConstraints $constraints): Size
    {
        if ($this->child === null) {
            return $constraints->constrain(new Size(0.0, 0.0));
        }

        $this->child->layout($constraints->loosen());
        $size = $this->child->size();

        $this->child->setOffset(
            $constraints->hasBoundedWidth() ? max(0.0, ($constraints->maxWidth - $size->width) / 2.0) : 0.0,
            $constraints->hasBoundedHeight() ? max(0.0, ($constraints->maxHeight - $size->height) / 2.0) : 0.0,
        );

        return $constraints->constrain($size);
    }
}
