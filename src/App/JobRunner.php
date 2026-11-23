<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Async\Future;

/**
 * 统一任务选路：有 TaskHost 就提交，没有就在当前栈执行。
 *
 * 应用侧不再复刻 `Job::run()` 的同步分支；两条路的返回值形状一致，失败统一落成
 * `rejected`。`null` 在这里表示同步执行，与 `Application::submit()` 的"声明不用后台"
 * 语义不同，不能混用。
 *
 * @api
 */
final class JobRunner
{
    /**
     * 有后台端口就提交，没有就在当前栈跑；异常统一转成 rejected Future。
     *
     * @param null|TaskHost $host null = 同步（解释器 / 单测 / 无后台宿主）
     * @param Job $job 任务本体，`run()` 是唯一实现
     * @return Future 成功值或 rejected；失败不会射进事件循环
     *
     * @api
     */
    public static function exec(?TaskHost $host, Job $job): Future
    {
        if ($host !== null) {
            return $host->submit($job);
        }

        // 同步路径必须接住异常并落成 rejected，与 TaskHost 的 error 出口一致。
        try {
            return Future::resolved($job->run());
        } catch (\Throwable $error) {
            return Future::rejected($error);
        }
    }
}
