<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke;

use Drift\Layout\RenderBox;
use Drift\Layout\RenderProxy;
use Drift\Widget\BuildContext;
use Drift\Widget\RenderBoxWidget;

/**
 * @internal 冒烟夹具：**产盒**的空壳控件（`createRenderObject()` 给纯转发的
 * `RenderProxy`）—— 用来验"修饰真的落了、又真的没多出第二份存储"。
 *
 * ★ S1（2026-10-28）之前它 `extends BoxWidget`，用于验双轨制（`BoxWidget` 子类写
 *   `BoxStyle`、裸 `Widget` 写 `$mods`）。`BoxWidget` 已删 ⇒ 现在它与裸 `Widget`
 *   走**同一条**路，夹具只需"产盒"这一个属性。
 */
final class BoxProbe extends RenderBoxWidget
{
    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderProxy();
    }
}
