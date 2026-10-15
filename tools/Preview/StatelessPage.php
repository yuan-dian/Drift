<?php
declare(strict_types=1);

namespace Drift\Dev\Preview;

use Drift\Widget\BuildContext;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Widget;

/** @internal 演示页基类：让"页面文件"有一个稳定的构造签名（无参） */
abstract class StatelessPage extends StatelessWidget
{
    abstract public function build(BuildContext $context): Widget;
}
