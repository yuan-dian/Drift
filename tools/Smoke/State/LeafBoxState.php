<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\State;

use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\Widget;

/**
 * @internal 只为数 `build()` 调用次数而存在（`LeafBoxWidget::$buildCalls`）。
 *
 * 被 `LeafBoxWidget`（覆写了产盒钩子）与 `LeafBoxFallbackWidget`（没覆写）共用 ——
 * 同一个 State 走两条路，差别只在 Widget 侧，这样比对才是同量具。
 */
final class LeafBoxState extends State
{
    #[\Override]
    public function build(BuildContext $ctx): Widget
    {
        LeafBoxWidget::$buildCalls++;

        return \Drift\Widget\SizedBox::zero();
    }
}
