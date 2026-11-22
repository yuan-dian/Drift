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
 * `ProcessTaskHost` 的**在途取消**（`Killable` 档）—— 取消真能停 CPU。
 *
 * ## 为什么必须真起进程
 *
 * "取消能省 CPU"不是算法，是"一个真的在跑的子进程会不会被杀掉"。纯 PHP 替身里
 * 没有那个进程对象，测到的只是"标志位写对了"。这里照 {@see JobTimeoutTest} 用
 * `tools/Smoke/App/worker.php` 起真 worker，杀进程走的就是生产那条 `proc_terminate`。
 *
 * ## 判据各钉什么
 *
 * | # | 判据 | 漏了会怎样 |
 * |---|---|---|
 * | 1 | 取消后 worker 立刻被杀（不等那条 6s 任务跑完） | 只标记"不交付"⇒ 后台继续烧 6s CPU |
 * | 2 | `pending()` 归零 | 帧时钟一直空转；或 worker 占着坑掉容量 |
 * | 3 | 取消**不**触发 onError | "停止"是正常操作，画成"失败"会吓到用户 |
 * | 4 | 后续任务照常跑（池自动补） | 敢杀不敢补 ⇒ 取消一次后台永久不可用 |
 * | 5 | 排队中的取消只摘队列、不杀 worker | 取消排队任务却杀掉正在跑别人任务的 worker = 连坐 |
 *
 * ★★ 每条提交**都必须挂 `catch()`**：Future 结算没人接会走 Fatal 序列，测试进程直接消失。
 */
final class CancelInFlightTest extends SmokeTestCase
{
    private static function host(): ProcessTaskHost
    {
        return new ProcessTaskHost(
            [\PHP_BINARY, __DIR__ . '/../../../tools/Smoke/App/worker.php'],
            SmokeJobRegistry::make(),
            1,
            0,   // 关掉超时兜底：这里要判的是"取消"，不能被超时抢先结算
        );
    }

    /**
     * @param list<string> $errors
     * @param list<mixed>  $values
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
     * 1–3：取消一条在途的 6s 任务 ⇒ 立刻停、worker 被杀、pending 归零、不报失败。
     *
     * ★ 睡 6s：正常路径根本等不到它（取消立刻杀）；只有杀进程没写对才会暴露成
     *   "等 6 秒"。判据用**耗时**区分"杀了"和"等它自己跑完"——两者终态一样。
     * ★ 取消后 Future 仍 pending（"取消不是结算"）：不 resolve / 不 reject，
     *   `isDone()` 保持 false，靠 `AsyncCommand::abandon()` 不再等它。
     */
    public function testCancelInFlightKillsWorkerImmediately(): void
    {
        $host = self::host();

        $errors = [];
        $values = [];
        $future = self::submit($host, new NamedSleepJob(6_000), $errors, $values);

        // 等 worker 真接住任务（派发在首帧 flush），否则 cancel 会落到"排队"分支。
        self::pumpUntil($host, static fn(): bool => $host->inFlightCount() === 1);
        self::pump($host);

        $t0 = \microtime(true);
        $future->cancel();
        self::pump($host);
        $elapsedMs = (\microtime(true) - $t0) * 1000.0;

        check('取消后 worker 立刻被杀（不等那条 6s 任务自己跑完）', $host->workerCount() === 0,
            'workers=' . $host->workerCount());
        check('取消**立刻**生效（没有等 6 秒）', $elapsedMs < 3_000.0,
            'elapsed=' . \round($elapsedMs) . 'ms');
        check('`pending()` 归零（帧时钟会重新睡下，池不占坑）', $host->pending() === 0,
            'pending=' . $host->pending());
        check('取消不是失败：没有 onError、没有值交付', $errors === [] && $values === [],
            'errors=' . \json_encode($errors, \JSON_UNESCAPED_UNICODE) . ' values=' . \json_encode($values));
        check('取消后 Future 仍未结算（"停止"不是成功也不是失败）', !$future->isDone());

        $this->flushChecks();
        $host->shutdown();
    }

    /**
     * 4：取消之后池**自动补一个**——取消不该让后面所有任务都跑不了。
     */
    public function testPoolRecoversAfterCancel(): void
    {
        $host = self::host();

        $errors = [];
        $values = [];
        $victim = self::submit($host, new NamedSleepJob(6_000), $errors, $values);
        self::pumpUntil($host, static fn(): bool => $host->inFlightCount() === 1);
        $victim->cancel();
        self::pump($host);

        $next = self::submit($host, new NamedSumJob(10), $errors, $values);
        $ok = self::pumpUntil($host, static fn(): bool => $next->isDone());

        check('取消之后池能补上新 worker，后续任务照常跑', $ok && $values === [55],
            'errors=' . \json_encode($errors, \JSON_UNESCAPED_UNICODE) . ' values=' . \json_encode($values));

        $this->flushChecks();
        $host->shutdown();
    }

    /**
     * 5：取消**排队中**的任务不得杀掉正在跑别人任务的 worker（不连坐）。
     *
     * ★ maxWorkers=1：第一条占住 worker，第二条排队。取消第二条只摘队列，
     *   第一条的 worker 必须还活着并正常回包。
     */
    public function testCancelQueuedDoesNotKillBusyWorker(): void
    {
        $host = self::host();

        $errors = [];
        $values = [];
        $running = self::submit($host, new NamedSleepJob(120), $errors, $values);
        self::pumpUntil($host, static fn(): bool => $host->inFlightCount() === 1);
        $queued = self::submit($host, new NamedSumJob(10), $errors, $values);

        check('第二条确实在排队（in-flight 仍是 1）', $host->queuedCount() === 1,
            'queued=' . $host->queuedCount());

        $queued->cancel();

        check('取消排队任务不杀正在跑的 worker', $host->workerCount() === 1,
            'workers=' . $host->workerCount());
        check('排队任务被摘掉（队列归零）', $host->queuedCount() === 0,
            'queued=' . $host->queuedCount());

        check('第一条任务照常回包（没被连坐）',
            self::pumpUntil($host, static fn(): bool => $running->isDone()) && $values === [120],
            'values=' . \json_encode($values));

        $this->flushChecks();
        $host->shutdown();
    }
}
