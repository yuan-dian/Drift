<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * **可分片**的后台任务（§17.4.3，P1）—— 长计算切成多帧跑，**不占**一帧。
 *
 * ★ 它解决的是一类很常见的需求：**能算完，只是算得慢**（过滤一万行、排序、解析）
 *   —— 不需要并行，只需要"别把一帧卡死"。WPF 文档的说法最直白：
 *   "`await` 不加 `Task.Run` 就是单线程分段计算"（§17.2.7）。
 *
 * ★ **与 `Job` 的分工**：
 *   - `Job::run()` —— 一次跑完，`TaskHost` 决定在哪跑（同步 / 进程池）；
 *   - `SlicedJob::step()` —— **多次**跑完，每次一小段，`Application` 每帧推一次
 *     （**永远在 UI 线程**，不经过 `TaskHost`）。
 *   ★ 分片**不是**后端：它没有第二个线程可谈，"换后端"对它无意义。
 *
 * ★ **`step()` 自己决定"一次干多少"**：框架只给预算（`$budgetMs`），不切片 ——
 *   因为只有任务自己知道"一个条目多贵"（一行字符串 vs 一兆字节）。
 *   按条目数切（每帧 100 条）或按时间切（干到超预算为止）都行。
 *
 * ★ **诚实边界**：`step()` 里若写了不可切分的单次操作（一次 `usort` 十万条），
 *   那一段仍然卡一帧 —— 分片只能切**循环**，切不了单个原子调用。
 *
 * @api
 */
interface SlicedJob
{
    /**
     * 干一部分；返回 `true` = 还没完（下一帧继续），`false` = 完成。
     *
     * ★ 每帧最多被调用一次（`Application::frame()` 的 `pumpSliced()`）——
     *   所以"总帧数"由你自己控制：想 10 帧做完就分 10 份。
     *
     * ★ 抛异常 ⇒ 任务终止，走 `$onError` 通道（与 `Job::run()` 同一条错误通道）。
     *
     * @param int $budgetMs 本帧给这个任务的**时间预算**（毫秒，框架的建议值，
     *   默认 4ms：一帧 16.7ms 里留给布局/绘制/输入 12ms，§13.3）
     *
     * @return bool true = 还有活（保持排队），false = 完成（`result()` 可取）
     *
     * @api
     */
    public function step(int $budgetMs): bool;

    /**
     * 取结果（**只在 `step()` 返回 `false` 之后调用**）。
     *
     * @api
     */
    public function result(): mixed;
}
