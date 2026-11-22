<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Fixtures;

/**
 * @internal lint 夹具：`worker-argv` 的"不该响"一侧。
 *
 * 文档注释里可以提 `--drift-worker`（讲清楚为什么用原语），字符串字面量不出现即绿。
 */
final class WorkerArgvGood
{
    /** 走 WorkerProcess::serveIf()，本文件不碰 argv，也不拼 --drift-worker。 */
    public function usePrimitive(): bool
    {
        return \Drift\App\WorkerProcess::isWorker();
    }
}
