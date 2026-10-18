<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\NamedJob;

/**
 * @internal 冒烟夹具：**真的把 worker 打死**（`smoke.exit`）—— 验崩溃隔离与自动重启。
 *
 * ★ 与 `NamedBoomJob` 的区别：那个是"任务失败"（被 `ProcessWorker` 捕获成 `{ok:false}`），
 *   这个是"进程死亡"（`exit` 连 catch 都拦不住）—— 只有它能验 `reap()` 那条路径。
 */
final class NamedExitJob implements NamedJob
{
    public function name(): string
    {
        return 'smoke.exit';
    }

    public function args(): array
    {
        return [];
    }

    public function run(): mixed
    {
        exit(9);
    }
}
