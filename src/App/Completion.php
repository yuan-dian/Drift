<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * 一个后台任务的**完成事件**（§17.4.2）—— 队列里流动的就是它。
 *
 * ★ 为什么要有这个类（而不是队列里放闭包）：后端与 UI 之间要传**三件事** ——
 *   值、异常、两个回调。塞进一个数组就是"用位置记语义"，第六个字段加进来那天
 *   必错；类还能被 `@internal` 收进框架，不让应用侧看见（应用只写
 *   `submit(..., onDone, onError)`）。
 *
 * ★ **值/异常二选一**（`error === null` ⇔ 成功）：不引入"既成功又失败"的第三态，
 *   与 §A.2.9 的三态（`Loading` / `Ready` / `Error`）一一对应。
 *
 * @internal 后端实现（`SyncTaskHost` 等）与 `Application` 之间的事件载体；
 *   应用侧不该 new 它 —— 那是**后端**的事。
 */
final class Completion
{
    /**
     * @param mixed $value 成功时的返回值（`Job::run()` 的产物）
     * @param ?\Throwable $error 失败时的异常（`Job::run()` 抛出来的那个）
     * @param \Closure $onDone 成功回调（在 UI 线程的帧内执行）
     * @param ?\Closure $onError 失败回调（同上；`null` ⇒ 框架走 Fatal 序列）
     */
    public function __construct(
        public readonly mixed $value,
        public readonly ?\Throwable $error,
        public readonly \Closure $onDone,
        public readonly ?\Closure $onError,
    ) {
    }
}
