<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Frame;

use Drift\Widget\BuildContext;
use Drift\Dev\Smoke\LeafProbe;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Widget;

/** @internal 冒烟夹具：`build()` 的产物**随 State 变** —— 端到端可观测。 */
final class QuietState extends State
{
    public int $builds = 0;

    public function build(BuildContext $ctx): Widget
    {
        $this->builds++;

        return new LeafProbe(1.0);
    }
}
