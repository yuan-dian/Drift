<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Async\Future;

/**
 * 旧提交口兼容壳：新代码用 {@see TaskDispatch}，它把策略显式化。
 *
 * `null` 在这里仍按历史约定表示同步执行，不再承担"拒绝"语义。
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
        $dispatch = $host === null ? TaskDispatch::sync() : TaskDispatch::background($host);

        return $dispatch->submit($job);
    }

    /** 显式策略入口，给还没迁完 `TaskDispatch::submit()` 的调用点用。 */
    public static function dispatch(TaskDispatch $dispatch, Job $job): Future
    {
        return $dispatch->submit($job);
    }
}
