<?php
declare(strict_types=1);

namespace App\Users\Commands;

use Drift\Commands\CommandArg;
use Drift\Commands\CommandHandler;
use App\Users\Store\AppStore;

/**
 * 导航命令（§A.2.10）：`nav.<page>` ⇒ `AppStore::navigate()`。
 *
 * ★ 一个 handler 管一族命令（`nav.*`）—— §11.4.3 的"多命令共用一个实例"。
 */
final class NavigationCommands implements CommandHandler
{
    public function __construct(private AppStore $app)
    {
    }

    public function handle(string $name, CommandArg $arg): bool
    {
        if (!str_starts_with($name, 'nav.')) {
            return false; // 不是我管 ⇒ 继续冒泡
        }

        $this->app->navigate(substr($name, 4));

        return true;
    }
}
