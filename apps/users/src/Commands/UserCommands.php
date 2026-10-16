<?php
declare(strict_types=1);

namespace App\Users\Commands;

use Drift\Commands\CommandArg;
use Drift\Commands\CommandHandler;
use App\Users\Store\AppStore;
use App\Users\Store\UserStore;

/** 用户列表命令（§A.2.10）：刷新 / 删除 */
final class UserCommands implements CommandHandler
{
    public function __construct(
        private UserStore $users,
        private AppStore $app,
    ) {
    }

    public function handle(string $name, CommandArg $arg): bool
    {
        return match ($name) {
            'users.refresh' => $this->refresh(),
            'user.del' => $this->delete($arg),
            default => false, // ★ 继续冒泡
        };
    }

    private function refresh(): bool
    {
        $this->app->say('正在刷新…');
        $this->users->load();
        $this->app->say('已刷新');

        return true;
    }

    private function delete(CommandArg $arg): bool
    {
        $ok = $this->users->remove($arg->i);
        $this->app->say($ok ? '已删除' : '删除失败');

        return true;
    }
}
