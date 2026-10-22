<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Layout;

use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Widget\BuildContext;
use Drift\Widget\RenderBoxWidget;

/** @internal 冒烟夹具：把 `LayoutCountBox` 包成可挂载的控件。 */
final class LayoutCountWidget extends RenderBoxWidget
{
    public function __construct(private readonly Size $fixed)
    {
        parent::__construct();
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new LayoutCountBox($this->fixed);
    }
}
