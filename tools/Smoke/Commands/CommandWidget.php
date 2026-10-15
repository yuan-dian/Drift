<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Commands;

use Drift\Commands\CommandArg;
use Drift\Commands\CommandHandler;
use Drift\Widget\BuildContext;
use Drift\Dev\Smoke\LeafProbe;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：把"收到过什么命令"记下来的 handler。
 *
 * 具名类是**有意的** —— §11.4.3 / O-15 方案 E 要求 handler 必须是具名类的实例
 * （可枚举 / 可单测 / 静态可达），夹具自己就得站得住这条。
 */
final class CommandWidget extends StatefulWidget
{
    public function createState(): State
    {
        return new CommandState();
    }
}

/** @internal 冒烟夹具 */
