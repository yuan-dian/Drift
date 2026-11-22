<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Fixtures;

/**
 * @internal lint 夹具：`worker-argv` 的"该响"一侧 —— 应用自己拼了 worker 开关。
 *
 * ★ 不要"修好"这个文件：它是反例。
 */
final class WorkerArgvBad
{
    public function scan(): bool
    {
        // 下面这行是第四份 argv 扫描 —— 应被 worker-argv 判红。
        $switch = '--drift-worker';

        return $switch !== '';
    }
}
