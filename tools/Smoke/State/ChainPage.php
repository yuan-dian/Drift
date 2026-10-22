<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\State;

use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Widget;

/** @internal 冒烟夹具：外层页面（被后代标脏的那一个） */
final class ChainPage extends StatefulWidget
{
    public static function make(ChainProbe $probe): self
    {
        return new self($probe);
    }

    public function __construct(private ChainProbe $probe)
    {
    }

    public function createState(): State
    {
        return new ChainState($this->probe);
    }
}

/** @internal 冒烟夹具 */
final class ChainState extends State
{
    public function __construct(private ChainProbe $probe)
    {
    }

    public function build(BuildContext $context): Widget
    {
        $this->probe->outerBuilds++;
        $this->probe->outerState = $this;

        return InnerPage::make($this->probe);
    }
}
