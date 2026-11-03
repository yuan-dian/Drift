<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Async\Future;

/**
 * 跑一个 `Job` —— **同步与后台统一选路**（2026-11-03，P0 债的第一条）。
 *
 * ```php
 * // 应用侧：一个动作，一行
 * private function runSign(): Future
 * {
 *     return JobRunner::exec($this->host, new FetchSignJob($this->apiBase));
 * }
 * ```
 *
 * ## 它替掉了什么
 *
 * 在此之前，应用必须在**每个**动作里手写同一条分支：
 *
 * ```php
 * $host = $this->host;
 * if ($host === null) {
 *     try {
 *         return Future::resolved((new ApiClient($base))->sign()->toArray()); // ← 逻辑第二遍
 *     } catch (\Throwable $e) {
 *         return Future::rejected($e);
 *     }
 * }
 * return $host->submit(new FetchSignJob($base));
 * ```
 *
 * 那段同步分支**复刻了 `Job::run()` 的每一个字节** —— 于是同一件事有三份实现
 * （`ApiClient` / `Job::run()` / 同步分支），改一处要记得改三处。`apps/watermark`
 * 五个动作各写了一遍，作者在注释里把它列为"最不想要的重复"。
 *
 * ## 为什么不让 `Application::submit()` 兼这个职
 *
 * `Application::submit()` 的 `null` 语义是**"本应用声明不用后台"** ⇒ 它返回
 * `rejected`（见那里的注释）。而应用侧（尤其是测试）的 `null` 语义是
 * **"没有后台就用当前栈跑"**。两个 `null` 意思相反，混在一起会让"测试里跑不起来"
 * 与"生产里提交不了"变成同一个症状。
 * ⇒ 本类是**显式的选路点**：`null` 永远表示"同步跑"，不表示"拒绝"。
 *
 * ## 为什么是静态工具而不是一个 `Runner` 接口 + 两个实现
 *
 * 两种跑法的差别只有"谁来执行 `$job->run()`"这一件事，`Future` 的契约完全一致。
 * 一个接口 + `SyncRunner` + `HostRunner` 是三个类换同一行 `if`，而调用方还要多
 * 记一个构造参数 —— 与 `Future` 本身是"一种值两个后端"同构，不需要再包一层。
 *
 * ## 契约（三条，少一条就会静默错）
 *
 * 1. **两条路的返回值形状必须一致**：`$job->run()` 的返回值，与 `host->submit()`
 *    结算出来的值，是**同一个** `Job` 的产物 ⇒ 上层 `AsyncCommand` 不必分支。
 * 2. **失败一律落成 `rejected`**：同步抛出（网络 / PDO / `TypeError`）与后台失败
 *    走同一个出口，否则同步路径会把异常射进事件循环（`dispatch()` 无 try/catch）。
 * 3. **不改 `Job`**：`exec()` 只决定"在哪跑"，任务本体仍是 `Job`。换后端
 *    （`SyncTaskHost` → `ProcessTaskHost`）时调用点一字不改。
 *
 * @api
 */
final class JobRunner
{
    /**
     * 跑一个任务：有后台端口就提交，没有就在**当前栈**上跑。
     *
     * @param null|TaskHost $host `null` ⇒ 同步（解释器 / 单测 / 没有后台的宿主）
     * @param Job $job 任务本体（`run()` 是唯一的一份实现）
     *
     * @return Future 两条路返回同一种 `Future`；失败已结算为 `rejected`
     *
     * @api
     */
    public static function exec(?TaskHost $host, Job $job): Future
    {
        if ($host !== null) {
            return $host->submit($job);
        }

        // ★ 同步路径：`run()` 的异常必须在这里接住并落成 `rejected`。
        //   与 `SyncTaskHost::submit()` 的后台路径同一个出口（那边是 `Completion`
        //   的 error 通道）—— 两条路都变成"Future 失败"，而不是"一边 error 态、
        //   一边异常射进事件循环"。这是本方法存在的第二个理由（第一个是去重）。
        try {
            return Future::resolved($job->run());
        } catch (\Throwable $error) {
            return Future::rejected($error);
        }
    }
}
