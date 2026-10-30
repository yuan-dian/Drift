<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\Completion;
use Drift\App\NamedJob;
use Drift\App\ProcessTaskHost;
use Drift\Async\Future;
use Drift\Dev\Smoke\App\NamedSleepJob;
use Drift\Dev\Smoke\App\NamedSumJob;
use Drift\Dev\Smoke\App\SmokeJobRegistry;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * `ProcessTaskHost` 的**单条任务墙钟上限**（2026-10-31）—— 形态照抄 PHP-FPM 的
 * `request_terminate_timeout`，实现用本仓自己的 `proc_terminate`。
 *
 * ## 为什么这一组必须**真起进程**
 *
 * 之前 `TaskHost` 的三条契约（预算 / 残留 / 记账）都在 `SyncTaskHost` 上测
 * （`DrainBudgetTest`）—— 那是纯 PHP，测的是算法。超时**不是算法**：它测的是
 * "一个真的卡住的子进程会不会被杀掉"。`SyncTaskHost` 里任务在 UI 线程同步跑完，
 * **根本不存在"卡住的 worker"这个对象** ⇒ 在那里测超时只能测到"时钟比较写对了"，
 * 测不到本轮真正要堵的洞。
 *
 * ## 那个洞长什么样（为什么值得一组测试）
 *
 * worker 卡住 ⇒ `Future` 永不结算 ⇒ `AsyncCommand::isRunning()` 恒为 true
 * ⇒ 翻页 / 重试按钮**全部禁用** ⇒ 用户**没有任何出路**，只能杀掉整个程序。
 * 而且它**不报错**：`pending()` 恒 > 0，池看起来还在跑。
 * 这与 FPM 把 `request_terminate_timeout = 0` 叫作 *the dangerous default*
 * 是同一个故障：有效容量悄悄掉一格，没有任何信号。
 *
 * ## 五条判据各钉什么
 *
 * | # | 判据 | 钉住 |
 * |---|---|---|
 * | 1 | 卡住的任务到期后被判失败 | "卡住 ⇒ 永不结算" 这个洞本身 |
 * | 2 | 失败消息带任务名 | 用户 / 日志能知道**是哪条**卡了 |
 * | 3 | worker **真的被杀** | 只判失败不杀 ⇒ 池容量永久掉一格（且无报错） |
 * | 4 | `pending()` 归零 | 帧时钟会重新睡下，不会一直空转 |
 * | 5 | 池**自动补一个** worker | 一次超时不该让后面所有任务都跑不了 |
 *
 * ★ 第 3 与第 5 是一组：只有杀得掉才谈得上补得上。
 *
 * ★★ 每条提交**都必须挂 `catch()`**：`Future` 结算时没人接会走 Fatal 序列（G2），
 *   那会让整个测试进程直接消失（写这组时真的发生过一次 —— 症状是"没输出、
 *   退出码 3"，只有 `Errors.log` 里那行 `异步任务失败且没有 catch()` 说清了原因）。
 *   生产路径上这件事由 `AsyncCommand::start()` 保证。
 */
final class JobTimeoutTest extends SmokeTestCase
{
    /** 一个很短的上限 —— 让"超时"在测试里立刻发生 */
    private const TERMINATE_MS = 300;

    private static function host(int $terminateMs = self::TERMINATE_MS): ProcessTaskHost
    {
        return new ProcessTaskHost(
            [\PHP_BINARY, __DIR__ . '/../../../tools/Smoke/App/worker.php'],
            SmokeJobRegistry::make(),
            1,
            $terminateMs,
        );
    }

    /**
     * 提交并**当场挂好 then / catch**（见类注释最后一条）。
     *
     * @param list<string> $errors
     * @param list<mixed> $values
     */
    private static function submit(ProcessTaskHost $host, NamedJob $job, array &$errors, array &$values): Future
    {
        return $host->submit($job)
            ->then(static function (mixed $v) use (&$values): void {
                $values[] = $v;
            })
            ->catch(static function (\Throwable $e) use (&$errors): void {
                $errors[] = $e->getMessage();
            });
    }

    /**
     * 把结果从后端取回来并结算 —— 与 `PumpHost::complete()` 同一条路。
     *
     * ★ 结算**必须在 `drain()` 的回调里做**：`Completion` 只是"后端交付的一个结果"，
     *   真正让 `Future` 落地的是消费方。测试里漏了这一步 ⇒ `isDone()` 永远 false。
     */
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

    /** 反复取，直到 `$done()` 或超时（给真进程留出起进程 / 回包的时间） */
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
     * 1–5：一条卡住的任务到期 ⇒ **立刻**被判失败（带任务名）、worker 被杀、
     * `pending()` 归零。
     *
     * ★ `usleep` 是这段测试唯一"等真实时间"的地方：上限是**墙钟**，而墙钟没法伪造
     *   （要伪造就得给整个 `App` 层引入一个可注入的时钟端口 —— 那是一条目前没人
     *   走的边）。代价是一组测试跑 ~1s，可以接受。
     *
     * ★★ 为什么这里要测**耗时**：`workerCount() === 0` 分不出"杀掉了"和"等它
     *   自己跑完再摘掉"—— 两条路的终态一模一样，差别只在"有没有等"。而"等"
     *   正是本轮要消灭的东西（等 = 用户继续转圈 3 秒）。⇒ 判据必须是**时间**。
     * ★ 卡住的那条睡 6s：正常路径根本等不到它（超时立刻杀）；只有清扫被写坏
     *   时才会暴露成"跑了 6 秒才红"，而不是"跑 6 秒才通过"。
     */
    public function testStuckJobIsFailedAndItsWorkerIsKilled(): void
    {
        $host = self::host();

        $t0 = \microtime(true);
        $errors = [];
        $values = [];
        $future = self::submit($host, new NamedSleepJob(6_000), $errors, $values);
        \usleep(self::TERMINATE_MS * 1000 + 400_000);   // 越过上限

        $settled = self::pumpUntil($host, static fn(): bool => $future->isDone());
        $elapsedMs = (\microtime(true) - $t0) * 1000.0;

        check('卡住的任务到期后被判失败（不再"永远转圈"）', $settled && $future->isDone());
        check('失败消息说明是**超时**，并带上任务名（能定位是哪条卡了）', $errors !== []
            && \str_contains($errors[0], '超时') && \str_contains($errors[0], 'smoke.sleep'),
            'errors=' . \json_encode($errors, \JSON_UNESCAPED_UNICODE));

        // ★ 第 3 条：只判失败不杀 ⇒ 那个 worker 永远占着坑，且不报错
        check('卡住的 worker **真的被终止**（不是"只把 Future 判失败"）', $host->workerCount() === 0,
            'workers=' . $host->workerCount() . ' running=' . ($host->isRunning() ? 'yes' : 'no'));

        // ★★ 第 3 条的**真正判据**：不等它跑完（6s 的任务不该被等 6s）
        check('超时**立刻**生效（没有等那条 6s 的任务自己跑完）', $elapsedMs < 3_000.0,
            'elapsed=' . \round($elapsedMs) . 'ms');

        // ★ 第 4 条：`pending()` 不归零 ⇒ 帧时钟一直空转（每帧 15ms 空唤醒）
        check('`pending()` 归零（帧时钟会重新睡下）', $host->pending() === 0,
            'pending=' . $host->pending());

        $this->flushChecks();
        $host->shutdown();
    }

    /**
     * 5：超时之后池**自动补一个** worker —— 一次卡死不该让后面所有任务都跑不了。
     *
     * ★ 这条是"杀"的**对价**：敢杀，是因为 `flush()` 会补。只测杀不测补，
     *   等于默许"超时一次，后台就永久不可用"。
     */
    public function testPoolSpawnsReplacementAfterKill(): void
    {
        $host = self::host();

        $errors = [];
        $values = [];
        $stuck = self::submit($host, new NamedSleepJob(3_000), $errors, $values);
        \usleep(self::TERMINATE_MS * 1000 + 400_000);
        self::pumpUntil($host, static fn(): bool => $stuck->isDone());

        $next = self::submit($host, new NamedSumJob(10), $errors, $values);
        $ok = self::pumpUntil($host, static fn(): bool => $next->isDone());

        check('超时之后池能补上新 worker，后续任务照常跑', $ok && $values === [55],
            'errors=' . \json_encode($errors, \JSON_UNESCAPED_UNICODE)
            . ' values=' . \json_encode($values));

        $this->flushChecks();
        $host->shutdown();
    }

    /**
     * 默认值必须是**开**：默认关等于把"用户永远没有出路"这个洞留在默认配置里。
     *
     * ★ 这条钉的是**构造签名**而不是行为：FPM 的默认值是 `0`（关），运维界把它叫作
     *   *the dangerous default* —— 本仓默认开，而"默认值"这种东西**看不见就等于
     *   没做**，所以它得有一个能读的口子（`requestTerminateMs()`）和一条判据。
     */
    public function testDefaultIsOn(): void
    {
        $host = new ProcessTaskHost(
            [\PHP_BINARY, __DIR__ . '/../../../tools/Smoke/App/worker.php'],
            SmokeJobRegistry::make(),
        );

        check('默认**有**上限（不是 0 = 关）', $host->requestTerminateMs() > 0,
            'default=' . $host->requestTerminateMs());
        check('显式给 0 仍然是 0（"关"必须可表达）', self::host(0)->requestTerminateMs() === 0);
        check('负值按"关"处理（不喊诊断）', self::host(-5)->requestTerminateMs() === 0);

        $this->flushChecks();
    }

    /**
     * `0` = 不设上限（显式关掉这道闸），卡住的任务**不会**被判失败。
     *
     * ★ 这条存在的理由：默认值是"开"，那"关"就得是**可表达的**，否则默认值等于
     *   硬编码。它同时也是第 1 条的反证 —— 证明失败**确实来自超时清扫**，而不是
     *   "worker 反正会崩"之类的巧合。
     * ★ 睡 1500ms 而不是 30s：本用例结束时 worker 还活着，`shutdown()` 的
     *   `proc_close()` 会**等它退出**（等 30s 的测试不可接受）。
     */
    public function testZeroMeansNoDeadline(): void
    {
        $host = self::host(0);

        $errors = [];
        $values = [];
        $future = self::submit($host, new NamedSleepJob(1_500), $errors, $values);
        \usleep(self::TERMINATE_MS * 1000 + 400_000);

        self::pump($host);

        check('上限为 0 时不清扫（任务还在途）', !$future->isDone() && $errors === [],
            'done=' . ($future->isDone() ? 'yes' : 'no') . ' errors=' . \json_encode($errors));
        check('worker 还活着（没被误杀）', $host->workerCount() === 1,
            'workers=' . $host->workerCount());

        $this->flushChecks();
        $host->shutdown();
    }
}
