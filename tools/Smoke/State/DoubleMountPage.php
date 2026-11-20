<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\State;

use Drift\Widget\BuildContext;
use Drift\Widget\Page;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/** `state-double-mount` / `state-remount` 用的最小页面：内容无关，只为拿一个 Page 实例。 */
final class DoubleMountPage extends Page
{
    public function build(BuildContext $ctx): Widget
    {
        return Text::make('double-mount');
    }
}
