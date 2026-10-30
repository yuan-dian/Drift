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

    /**
     * ★★ 这里**只能**说"正在刷新"，不能说"已刷新"。
     *
     * `load()` 只是**发起**（`AsyncCommand::run()` 立刻返回），结果要等后台回来。
     * 而 `CommandHandler::handle()` 是**同步**的、返回 `bool` 就结束了 —— 它
     * **没有任何办法**知道异步结果。⇒ 在这里接一句 `say('已刷新')` 是**说谎**：
     * 转圈还在转，snackbar 却说完成了（以前 `load()` 手写状态机时就有这个毛病，
     * 换成命令之后它变得更刺眼）。
     *
     * "完成了没有"由 **`AsyncValue` 那四态**回答 —— 页面读
     * `$store->page()->value()` 画 loading / data / error，那才是真的状态。
     * ⇒ 命令层只负责**发起**，"结果怎么样"交给订阅。
     */
    private function refresh(): bool
    {
        $this->app->say('正在刷新…');
        $this->users->load();

        return true;
    }

    private function delete(CommandArg $arg): bool
    {
        $ok = $this->users->remove($arg->i);
        $this->app->say($ok ? '已删除' : '删除失败');

        return true;
    }
}
