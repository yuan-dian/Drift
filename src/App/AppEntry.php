<?php

declare(strict_types=1);

namespace Drift\App;

/**
 * 应用入口基类：**进程角色由框架判**，应用只声明"有哪些后台任务"和"UI 起来做什么"。
 *
 * 三层分工：`main()`（tpc 入口）→ `AppEntry::run()`（角色分流）→ `Application`（每窗 UI）。
 * 应用看不见 `--drift-worker`，也不需要知道"同一个 exe 跑两种角色"这件事。
 *
 * ★ 用实例方法而不是静态：PHP 的 `abstract static` 是致命错误（"Static function ...
 *   cannot be abstract"），基类就没法强制子类实现 `start()`。实例抽象方法合法，
 *   AOT 的虚调用天然支持，入口固定 `(new Bootstrap())->run();`。
 *
 * @api 组合根继承它：必写 `start()`；用后台才写 `jobs()`
 */
abstract class AppEntry
{
    /**
     * 后台任务表：worker 角色据此干活，UI 角色拿它装 `AppScope::useBackgroundJobs()`。
     * 空数组 = 本应用不用后台。
     *
     * ★ 一处声明、两处消费 —— 不要在两处各 `JobRegistry::make()` 一遍。
     *
     * @return array<string, callable(array<string, mixed>): Job>
     */
    protected function jobs(): array
    {
        return [];
    }

    /** UI 进程的装配：建窗 / 开槽位 / `WindowSet::run()` */
    abstract protected function start(): void;

    final public function run(): void
    {
        // 顺序只在这里写：角色判定必须在**任何平台构造之前**，所以 `start()` 里不许
        // 再判一次。UI 进程不碰 `jobs()` —— 表留给 `start()` 装配端口时取。
        if (WorkerProcess::isWorker()) {
            WorkerProcess::serve($this->jobs());

            return;   // stdin 关闭 = UI 进程走了 ⇒ 正常收工
        }

        $this->start();
    }
}
