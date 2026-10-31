<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\Completion;
use Drift\App\CompletionQueue;
use Drift\App\ThreadTaskHost;
use Drift\Async\Future;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * `ThreadTaskHost` 的**积压**（2026-10-31：`$backlog` 换成 `CompletionQueue` 之后）。
 *
 * ## 为什么要单独测这一段
 *
 * 本后端要 C++ 原生池才跑得起来（`drift_io_*`），所以它的交付路径一直是
 * "注释 + smoke 之外没人验"。而它有一个**只有它才有**的形状：
 * `drift_io_poll()` 是**取走**语义 —— 一批结果取回来、预算不够交不完，剩下的
 * 只能由本类保管。那条"保管"路径写错就是**死锁**（界面停在半截），不是慢：
 *
 * | 写错的形态 | 症状 |
 * |---|---|
 * | 积压不计入 `pending()` | 帧时钟判"没活了" ⇒ 不再跑帧 ⇒ 积压永远交不出去 |
 * | 积压不反映到 `needsPolling()` | 后端"能叫醒"⇒ `waitEvents(-1)` ⇒ 睡到用户动鼠标 |
 * | 积压交付顺序排到新批次之后 | 上一批的行比这一批晚显示（分页错位） |
 *
 * ## 为什么不用 `submit()`
 *
 * `submit()` 会调 `drift_io_submit()`（本机只有 AOT/原生构建里才有）。这里直接用
 * 反射把**已经取回来的一批结果**塞进 `$backlog`，等价于"上一帧取回来了、没交完"
 * 那个时刻。验证的是**残留载体**的三条性质，不是原生池本身。
 *
 * `$started` 保持 `false` ⇒ `drain()` 交完积压就返回，**不会去问原生池**
 * （`drift_io_poll()` 一条都不调）。
 */
final class ThreadHostBacklogTest extends SmokeTestCase
{
    /** 反射塞进 `$backlog`：模拟"上一帧取回来了、预算不够没交完" */
    private static function backlogOf(ThreadTaskHost $host): CompletionQueue
    {
        $property = new \ReflectionProperty(ThreadTaskHost::class, 'backlog');

        /** @var CompletionQueue $queue */
        $queue = $property->getValue($host);

        return $queue;
    }

    private static function completion(int $value): Completion
    {
        return new Completion($value, null, Future::pending());
    }

    public function testBacklogCountsAsPendingWork(): void
    {
        $host = new ThreadTaskHost();

        self::backlogOf($host)->push(self::completion(1));
        self::backlogOf($host)->push(self::completion(2));

        // ★ 这条是死锁判据：积压不算进 pending()，帧时钟就会睡死
        check('积压算进 pending()（否则帧循环停 ⇒ 死锁）', $host->pending() === 2, (string) $host->pending());

        // ★ 这条是"叫不醒"判据：后端本身能叫醒，但积压要靠帧节奏续交
        check('有积压 ⇒ needsPolling() 为真', $host->needsPolling());
    }

    public function testDrainDeliversBacklogInOrder(): void
    {
        $host = new ThreadTaskHost();

        $queue = self::backlogOf($host);
        $queue->push(self::completion(1));
        $queue->push(self::completion(2));
        $queue->push(self::completion(3));

        $seen = [];
        $host->drain(static function (Completion $completion) use (&$seen): void {
            $seen[] = $completion->value;
        }, null);

        check('积压按完成顺序交付', $seen === [1, 2, 3], \json_encode($seen));
        check('交完之后 pending() 归零', $host->pending() === 0, (string) $host->pending());
        check('交完之后 needsPolling() 为假', !$host->needsPolling());
    }

    public function testBudgetLeavesRemainderAndKeepsClockAwake(): void
    {
        $host = new ThreadTaskHost();

        $queue = self::backlogOf($host);
        $queue->push(self::completion(1));
        $queue->push(self::completion(2));
        $queue->push(self::completion(3));

        $seen = [];
        // 每条 5ms ⇒ 1ms 预算只够第一条（契约①：首条无条件放行）
        $host->drain(static function (Completion $completion) use (&$seen): void {
            $seen[] = $completion->value;
            \usleep(5000);
        }, 1);

        check('预算用完只交一条（契约①）', $seen === [1], \json_encode($seen));
        check('剩余仍在 pending() 里（否则帧时钟停）', $host->pending() === 2, (string) $host->pending());
        check('剩余仍要求轮询（否则睡到用户动鼠标）', $host->needsPolling());

        // 下一帧：预算给足 ⇒ 剩下两条按顺序交完
        $rest = [];
        $host->drain(static function (Completion $completion) use (&$rest): void {
            $rest[] = $completion->value;
        }, null);

        check('下一帧交完剩余且顺序不乱', $rest === [2, 3], \json_encode($rest));
        check('全部交完之后队列为空', $host->pending() === 0, (string) $host->pending());
    }
}
