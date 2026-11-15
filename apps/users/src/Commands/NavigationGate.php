<?php
declare(strict_types=1);

namespace App\Users\Commands;

use App\Users\Store\AppStore;
use Drift\Commands\CommandArg;
use Drift\Commands\CommandGate;

/** 导航门控：栈底时不许"返回"，否则 Alt+Left 与菜单项都静默无反应。 */
final class NavigationGate implements CommandGate
{
    public function __construct(private AppStore $app)
    {
    }

    public function allows(string $name, CommandArg $arg): bool
    {
        return match ($name) {
            'nav.back' => $this->app->nav()->canPop(),
            default => true,
        };
    }
}
