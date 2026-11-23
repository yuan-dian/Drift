<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * 后台任务单元：是类而不是闭包，参数由构造器注入，可跨进程重建。
 *
 * `Job` 说做什么，`TaskHost` 说在哪跑、结果怎么回来；换后端不改 Job。
 *
 * @api
 */
interface Job
{
    /**
     * 执行任务本体，返回值经 `TaskHost::submit()` 的 `$onDone` 回贴。
     *
     * 返回类型是 `mixed`：任务形态各异，统一 DTO 只会逼出无信息量的壳。
     * `run()` 里不许碰 Widget / Element / State；改 UI 放到 `$onDone`（UI 线程）。
     *
     * @api
     */
    public function run(): mixed;
}
