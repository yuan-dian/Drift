<?php
declare(strict_types=1);

namespace App\Users\Commands;

use Drift\Commands\CommandArg;
use Drift\Commands\CommandHandler;
use App\Users\Store\AppStore;

/** 导航命令：nav.<page> 压栈，nav.back / nav.forward 走栈。 */
final class NavigationCommands implements CommandHandler
{
    public function __construct(private AppStore $app)
    {
    }

    public function handle(string $name, CommandArg $arg): bool
    {
        if ($name === 'nav.back') {
            $this->app->back();

            return true;
        }
        if ($name === 'nav.forward') {
            $this->app->forward();

            return true;
        }
        if (!str_starts_with($name, 'nav.')) {
            return false;
        }

        $this->app->navigate(substr($name, 4));

        return true;
    }
}