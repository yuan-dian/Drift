<?php
declare(strict_types=1);

namespace App;

use Drift\App\WorkerProcess;

/**
 * @internal lint 夹具：`app-worker-role` 的"该响"一侧 —— 应用自己判了角色。
 *
 * ★ 不要"修好"这个文件：它是反例。开关字面量在 `WorkerProcess` 里，`worker-argv`
 *   对这里全绿；只有这条规则能看出"角色判定被搬回应用层"。
 */
final class WorkerRoleBad
{
    public function role(): bool
    {
        return WorkerProcess::isWorker();
    }
}
