<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\Completion;
use Drift\App\ProcessTaskHost;
use Drift\Async\Future;
use Drift\Dev\Smoke\App\NamedSumJob;
use Drift\Dev\Smoke\App\SmokeJobRegistry;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;

/**
 * `ProcessTaskHost::prewarm()` —— **把 worker 的启动成本挪到应用启动期**（2026-10-31）。
 *
 * ## 它修的是哪一段等待
 *
 * `spawn` 单价 **275ms**（`probes/RESULTS.md` §12 ⑧）。第一条任务必须等这段
 * 进程启动 ⇒ "进页面就取数"的路径上，用户看到的是"转圈出现了、任务却还没开始跑"。
 * 预热把这 275ms 挪到建窗 / 首帧那段时间（另一个进程在启动，不占 UI 线程）。
 *
 * ## 四条判据
 *
 * | # | 判据 | 漏了的表现 |
 * |---|---|---|
 * | 1 | `prewarm()` 之后**没有提交任务**，池里已经有 worker | 预热等于没做（还是懒启动） |
 * | 2 | 第一条任务被发到**那个** worker 上（没有起第二个） | 预热的进程白起，首条任务照旧付 275ms |
 * | 3 | `prewarm($n)` 被 `maxWorkers` 夹住 | 一个 worker = 一整套 AOT 运行时 ⇒ 无界起进程 = 内存炸弹 |
 * | 4 | `prewarm(0)` / 负数什么都不做 | 关掉预热的唯一办法变成"别调它"，而组合根通常无条件调 |
 *
 * ★ #2 的判据用**进程数不增**：`workerCount()` 从 1 涨到 2 就说明首条任务没被
 *   预热的那条接走。比"首条任务快了多少毫秒"稳得多 —— 后者依赖机器负载。
 *
 * ★★ 每条提交**都必须挂 `catch()`**：`Future` 结算时没人接会走 Fatal 序列（G2），
 *   整个测试进程会直接消失（`JobTimeoutTest` 的类注释记着同一个坑）。
 */
final class PrewarmTest extends SmokeTestCase
{
    private static function host(int $maxWorkers = 1): ProcessTaskHost
    {
        return new ProcessTaskHost(
            [\PHP_BINARY, __DIR__ . '/../../../tools/Smoke/App/worker.php'],
            SmokeJobRegistry::make(),
            $maxWorkers,
        );
    }

    /** 把结果从后端取回来并结算（与 `PumpHost::complete()` 同一条路） */
    private static function pump(ProcessTaskHost $host): void
    {
        $host->drain(static function (Completion $c): void {
            if ($c->error !== null) {
                $c->future->reject($c->error);

                return;
            }

            $c->future->resolve($c->value);
        });
    }

    /** 反复取，直到 `$done()` 或超时（真进程要时间） */
    private static function pumpUntil(ProcessTaskHost $host, callable $done): bool
    {
        $deadline = \microtime(true) + 10.0;

        while (\microtime(true) < $deadline) {
            self::pump($host);

            if ($done()) {
                return true;
            }

            \usleep(10_000);
        }

        return false;
    }

    /**
     * #1：`prewarm()` 之后**一条任务都没提交**，池里已经有 worker。
     *
     * ★ 判据是"池里有活着的 worker"而不是"`workerCount() === 1`"：`spawnWorker()`
     *   只负责 `proc_open`，进程可能因为命令行写错而立刻退出 —— 那样预热是假的。
     */
    public function testPrewarmStartsWorkerBeforeAnySubmit(): void
    {
        $host = self::host();

        check('prewarm 之前池是空的（懒启动的基线）', $host->workerCount() === 0);

        $host->prewarm();

        check(
            'prewarm() ⇒ 没提交任何任务，worker 就已经起好（启动成本挪到应用启动期）',
            $host->workerCount() === 1 && $host->isRunning(),
            'workers=' . $host->workerCount() . ' running=' . ($host->isRunning() ? 'yes' : 'no'),
        );
        check('prewarm() 不制造"在途任务"（帧时钟照旧可以睡）', $host->pending() === 0,
            'pending=' . $host->pending());

        $host->shutdown();
    }

    /**
     * #2：第一条任务被**预热好的那个** worker 接走 —— 池里始终只有一个进程。
     *
     * ★ 这条才是预热的**实际收益**：预热之后 `flush()` 走 `pickIdle()` 命中它，
     *   而不是再 `spawnWorker()` 一次。若判据只写"任务跑通了"，那么"预热没生效、
     *   首条任务照旧起进程"也会通过 —— 症状（275ms 还在）恰好不可见。
     */
    public function testFirstTaskReusesPrewarmedWorker(): void
    {
        Errors::reset();

        $host = self::host();
        $host->prewarm();

        $values = [];
        $errors = [];
        $future = $host->submit(new NamedSumJob(5))
            ->then(static function (mixed $v) use (&$values): void {
                $values[] = $v;
            })
            ->catch(static function (\Throwable $e) use (&$errors): void {
                $errors[] = $e->getMessage();
            });

        $settled = self::pumpUntil($host, static fn(): bool => $future->isDone());

        check(
            'prewarm 后第一条任务能跑完（预热不破坏正常路径）',
            $settled && $values === [15],
            'values=' . \json_encode($values) . ' errors=' . \json_encode($errors, \JSON_UNESCAPED_UNICODE),
        );
        check(
            '首条任务被**预热好的**那个 worker 接走（池里始终只有 1 个进程）',
            $host->workerCount() === 1,
            'workers=' . $host->workerCount(),
        );
        check('预热路径上没有诊断（没有起不来 / 没有协议错）', Errors::recentDiagnostics() === []);

        $host->shutdown();
    }

    /**
     * #3：`prewarm($n)` 被 `maxWorkers` 夹住。
     *
     * ★ 不夹的话 `prewarm(8)` 会起 8 个 AOT 运行时 —— 预热就成了"无界起进程"的
     *   后门，而它本来的目的只是把启动挪个位置。
     */
    public function testPrewarmIsClampedByMaxWorkers(): void
    {
        $host = self::host(2);
        $host->prewarm(5);

        check(
            'prewarm(5) 在 maxWorkers=2 时只起 2 个（预热不能成为无界起进程的后门）',
            $host->workerCount() === 2,
            'workers=' . $host->workerCount(),
        );

        $host->shutdown();
    }

    /**
     * #4：`prewarm(0)` 什么都不做 —— 组合根可以无条件调它。
     */
    public function testPrewarmZeroIsNoOp(): void
    {
        $host = self::host();
        $host->prewarm(0);

        check('prewarm(0) 不起进程（组合根可以无条件调，开关由参数表达）',
            $host->workerCount() === 0, 'workers=' . $host->workerCount());

        $host->shutdown();
    }
}
