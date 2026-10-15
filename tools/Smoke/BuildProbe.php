<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke;

use Drift\Widget\BuildContext;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Widget;

/** @internal 冒烟夹具：输出只由入参决定的 `StatelessWidget`。 */
final class BuildProbe extends StatelessWidget
{
    public function build(BuildContext $ctx): Widget
    {
        return new LeafProbe($ctx->width);
    }
}
