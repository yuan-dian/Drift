<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Frame;

use Drift\Widget\BuildContext;
use Drift\Dev\Smoke\LeafProbe;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Widget;

/** @internal 冒烟夹具：`build()` 的产物**随 State 变** —— 端到端可观测。 */
final class TickerState extends State
{
    public int $step = 0;
    public int $builds = 0;

    public function tick(): void
    {
        $this->setState(function (): void {
            $this->step++;
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        $this->builds++;

        return new LeafProbe((float) $this->step); // 产物跟着 State 走
    }
}

/** @internal 冒烟夹具：**永远不脏**的 State —— 用来断"flush 有脏边界" */
