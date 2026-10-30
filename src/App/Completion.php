<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Async\Future;

/**
 * 一个后台任务的**完成事件**（§17.4.2）—— 队列里流动的就是它。
 *
 * ★ 为什么要有这个类（而不是队列里放闭包）：后端与 UI 之间要传**三件事** ——
 *   值、异常、**哪个 Future 该结算**。塞进一个数组就是"用位置记语义"，
 *   第四个字段加进来那天必错；类还能被 `@internal` 收进框架。
 *
 * ★ **值/异常二选一**（`error === null` ⇔ 成功）：不引入"既成功又失败"的第三态，
 *   与 §A.2.9 的三态（`Loading` / `Ready` / `Error`）一一对应。
 *
 * ★★ **2026-10-30（N3）改形**：原来是「值 + 异常 + 两个闭包」。闭包搬进了
 *   `Future`（`then()` / `catch()`），这里只留**信封**：谁、成没成、值是什么。
 *   理由与 §7.2 删 `pump()` 同一条：**能一套就一套** —— 两套回调通道
 *   （`onDone` 与 `then`）会让"到底谁先跑"变成要靠读实现才能回答的问题。
 *
 * @internal 后端实现（`SyncTaskHost` / `ProcessTaskHost`）与 `PumpHost` 之间的事件
 *   载体；应用侧不该 new 它 —— 那是**后端**的事。
 */
final class Completion
{
    /**
     * @param mixed $value 成功时的返回值（`Job::run()` 的产物）
     * @param ?\Throwable $error 失败时的异常（`Job::run()` 抛出来的那个）
     * @param Future $future 要结算的 Future（`submit()` 返回的那个）
     */
    public function __construct(
        public readonly mixed $value,
        public readonly ?\Throwable $error,
        public readonly Future $future,
    ) {
    }
}
