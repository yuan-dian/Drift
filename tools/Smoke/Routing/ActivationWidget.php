<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Routing;

use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/** @internal 冒烟夹具：只数"首次可见"次数，验证 Offstage 激活钩子。 */
final class ActivationWidget extends StatefulWidget
{
    public function createState(): State
    {
        return new ActivationState();
    }
}

/** @internal */
final class ActivationState extends State
{
    public int $activations = 0;

    public function onActivate(): void
    {
        ++$this->activations;
    }

    public function build(BuildContext $ctx): Widget
    {
        return Text::make('activation');
    }
}
