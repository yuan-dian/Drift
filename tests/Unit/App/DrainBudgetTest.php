<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\Completion;
use Drift\App\Job;
use Drift\App\SyncTaskHost;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * `TaskHost::drain($onEach, $budgetMs)` —— 一帧交付多少条的**上限**（2026-10-30 P1）。
 *
 * ## 为什么这一条要用"慢回调"来测
 *
 * 预算是**时间**，不是条数。用"条数"测就只能测出"循环写了几次"，测不出
 * 「一条回调太慢时会不会饿死队列」—— 而那正是预算引入的**新风险**：
 *
 * | 写错的形态 | 症状 |
 * |---|---|
 * | 每条都查预算、一条都不放行 | **活锁**：每帧进去、发现超预算、一条没交、返回 |
 * | 超预算就 `return`，剩余丢掉 | 结果是**永久丢失**，`Future` 永不结算 ⇒ 页面永远转圈 |
 * | 剩余放回**队尾** | 顺序乱：后完成的先交付 ⇒ `Table` 分页错位 |
 * | 剩余不放回、也不改 `pending()` | 帧时钟判"没活了"就睡死（`needsPolling() === true` 时尤其） |
 *
 * 四个用例各钉一条。`SyncTaskHost` 是纯 PHP、无原生依赖，契约的"算法部分"
 * 全在这里验证；`ProcessTaskHost` / `ThreadTaskHost` 的**残留载体**不同
 * （管道 buffer / 本地 backlog），那部分靠它们各自的实现注释与 smoke 覆盖。
 */
final class DrainBudgetTest extends SmokeTestCase
{
    /** 一条"立刻返回给定值"的任务（`run()` 不能睡 —— 预算是回调的时间，不是任务的时间） */
    private static function job(int $value): Job
    {
        return new class ($value) implements Job {
            public function __construct(private readonly int $value)
            {
            }

            public function run(): int
            {
                return $this->value;
            }
        };
    }

    private static function hostWith(int $count): SyncTaskHost
    {
        $host = new SyncTaskHost();

        for ($i = 1; $i <= $count; $i++) {
            $host->submit(self::job($i));
        }

        return $host;
    }

    /**
     * 一个"交付一条就睡 `$ms` 毫秒"的收集器 —— 用来把预算烧掉。
     *
     * @param list<int> $seen
     */
    private static function sleepyCollector(array &$seen, int $ms): \Closure
    {
        return static function (Completion $completion) use (&$seen, $ms): void {
            $seen[] = $completion->value;
            \usleep($ms * 1000);
        };
    }

    /** @param list<int> $seen */
    private static function collector(array &$seen): \Closure
    {
        return static function (Completion $completion) use (&$seen): void {
            $seen[] = $completion->value;
        };
    }

    /** 用例 ①：`null` = 不限 —— 一次取空（这是**测试与最后冲刷**才用的路径） */
    public function testUnlimitedDeliversEverythingInOneDrain(): void
    {
        $host = self::hostWith(5);
        $seen = [];

        $host->drain(self::sleepyCollector($seen, 2));

        check('预算 · null ⇒ 一次取空（5 条全交付）', \count($seen) === 5);
        check('预算 · null ⇒ 队列清空', $host->pending() === 0);

        $this->flushChecks();
    }

    /** 用例 ②：超预算就收手，**剩余留在后端**（`pending()` 仍 > 0） */
    public function testBudgetStopsEarlyAndLeavesTheRestPending(): void
    {
        $host = self::hostWith(5);
        $seen = [];

        // 每条睡 2ms，预算 1ms ⇒ 交完第 1 条就已超预算
        $host->drain(self::sleepyCollector($seen, 2), 1);

        check('预算 · 超预算就收手（只交了 1 条）', \count($seen) === 1);
        check('预算 · 剩余留在后端（pending 仍 > 0 ⇒ 帧时钟不会睡死）', $host->pending() === 4);

        $this->flushChecks();
    }

    /**
     * 用例 ③：**唯一一条再慢也要交出去** —— 预算不能变成"整体跳过"。
     *
     * ★ 为什么单拎出来：用例 ②（5 条慢回调）断言的是"交了一部分"，
     *   如果有人把实现写成"这一帧总耗时超预算就整个放弃"（`return` 在循环外），
     *   用例 ② 可能仍然通过（它只要求"交了 1～4 条"），而这条会红。
     *
     * ★ 诚实边界：它**抓不到** `$i > 0` 这个分支的变异 —— `$deadline` 在本方法
     *   开头计算，首条检查时恒在未来。那条分支的保护对象是 `ThreadTaskHost`
     *   的跨段场景（积压段先烧光预算，新批次开场即过期），不是这里。
     *   写清楚是为了避免"这条过了所以至少一条被证明了"的错觉。
     */
    public function testSingleSlowCompletionIsStillDelivered(): void
    {
        $host = self::hostWith(1);
        $seen = [];

        // 唯一的一条就要睡 30ms，预算是 1ms —— 仍然必须交出去
        $host->drain(self::sleepyCollector($seen, 30), 1);

        check('预算 · 唯一一条再慢也交付（预算不是"整体跳过"的借口）', \count($seen) === 1);
        check('预算 · 单条交付后队列为空', $host->pending() === 0);

        $this->flushChecks();
    }

    /** 用例 ④：跨帧继续交，**顺序与提交顺序一致**，一条不漏 */
    public function testRemainingIsDeliveredNextFrameInOrder(): void
    {
        $host = self::hostWith(5);
        $seen = [];
        $frames = 0;

        while ($host->pending() > 0 && $frames < 50) {
            $host->drain(self::sleepyCollector($seen, 2), 1);
            $frames++;
        }

        check('预算 · 跨帧交付把队列交空（一条不漏）', $host->pending() === 0);
        check('预算 · 交付顺序与提交顺序一致', $seen === [1, 2, 3, 4, 5]);
        check('预算 · 确实分了多帧（预算真的生效，不是摆设）', $frames > 1);

        $this->flushChecks();
    }

    /**
     * 用例 ⑤：回调里再 `submit()` 的新结果，排在**被放回的剩余之后**。
     *
     * ★ 顺序理由：剩余那几条**更早完成**（它们先进队列），新提交的晚于它们。
     *   放到队尾是唯一正确的合并方式 —— 放队头会让"新任务抢在旧任务前面交付"，
     *   在 `Table` 分页里就是"翻到第 2 页却看到第 1 页的数据"。
     */
    public function testNewSubmitDuringDrainGoesAfterTheBacklog(): void
    {
        $host = self::hostWith(2);   // 队列：[1, 2]
        $seen = [];
        $once = false;

        $host->drain(function (Completion $completion) use (&$seen, &$once, $host): void {
            $seen[] = $completion->value;
            \usleep(2_000);

            if (!$once) {
                $once = true;
                $host->submit(self::job(99));   // ★ 回调里再提交
            }
        }, 1);

        check('预算 · 第一帧只交 1 条', $seen === [1]);
        check('预算 · 剩余 1 条 + 新提交的 1 条都还在队列里', $host->pending() === 2);

        $host->drain(self::collector($seen));

        check('预算 · 放回的剩余排在新提交之前（顺序不乱）', $seen === [1, 2, 99]);

        $this->flushChecks();
    }
}
