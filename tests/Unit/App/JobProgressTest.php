<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\Completion;
use Drift\App\JobProgress;
use Drift\App\ProcessTaskHost;
use Drift\Async\AsyncValue;
use Drift\Async\Future;
use Drift\Dev\Smoke\App\NamedProgressJob;
use Drift\Dev\Smoke\App\SmokeJobRegistry;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * **进度通道**（2026-11-02，第 2 棒）—— 从 worker 进程回到 UI 的那条路。
 *
 * ## 为什么这一组必须有一条**真进程**的用例
 *
 * 进度链路上有三段，前两段都能离线判死：
 *
 * | 段 | 能离线测吗 |
 * |---|---|
 * | `JobProgress::report()` 的节流与单调 | 能（纯函数） |
 * | `Future::reportProgress()` 的丢弃规则 | 能（纯状态机） |
 * | **`{"id":N,"progress":f}` 这一行真的过了管道** | **不能** |
 *
 * 第三段里藏着本轮最危险的一个退化：`ProcessTaskHost::deliver()` 原来只有
 * "`ok` 为真 ⇒ 成功 / 否则 ⇒ 失败"两分支。进度行没有 `ok` 键 ⇒ 会落进**失败**分支
 * ⇒ 一次上传在上传途中就变成"任务失败"，而错误消息是框架自己编的
 * （"worker 未给出错误信息"）。离线测不出来，因为它要求**真的有一行进度从管道过来**。
 *
 * ## 判据各钉什么
 *
 * | # | 判据 | 钉住 |
 * |---|---|---|
 * | 1 | `report()` 无 sink 时静默 | 同步后端跑同一个 Job 不能炸 |
 * | 2 | 1% 粒度节流 | 一个 100MB 上传别把 worker 的 CPU 花在报告上 |
 * | 3 | **`1.0` 永不被节流吞** | 否则进度条永远停在 99% |
 * | 4 | 倒退 / 相等一律丢 | 进度条不能往回跳（IPC 上乱序是可能的） |
 * | 5 | 已结算后的进度丢弃 | 订阅者可能已经换了下一轮 |
 * | 6 | 晚订阅能补发一次 | `submit()` 之后才挂 `onProgress()` 是常态 |
 * | 7 | `AsyncValue::withProgress()` 保留状态与载荷 | 进度是第五维，不是第五态 |
 * | 8 | **真进程往返：进度单调、终值 1.0、结果不被吞** | 整条链路 |
 */
final class JobProgressTest extends SmokeTestCase
{
    /** ★ 每个用例前后都要卸 sink：`JobProgress` 是静态的，串味会让下一条假绿 */
    private function reset(): void
    {
        JobProgress::bind(null);
    }

    public function testReportWithoutSinkIsSilent(): void
    {
        $this->reset();

        $threw = null;
        try {
            JobProgress::report(0.5);
            JobProgress::report(1.0);
        } catch (\Throwable $e) {
            $threw = $e;
        }

        check(
            '进度 · 没有 sink 时 report() 是空操作（同步后端跑同一个 Job 不能炸）',
            $threw === null,
            $threw === null ? '(无异常)' : \get_class($threw) . '：' . $threw->getMessage(),
        );

        $this->flushChecks();
    }

    public function testThrottleKeepsOnePercentGranularity(): void
    {
        $this->reset();

        $seen = [];
        JobProgress::bind(static function (float $f) use (&$seen): void {
            $seen[] = $f;
        });

        // 100 次细碎上报（每次 0.1%）—— 只有跨过 1% 的才该出去
        for ($i = 1; $i <= 100; $i++) {
            JobProgress::report($i / 1000.0);
        }

        check(
            '进度 · 1% 粒度节流：1000 次细碎上报 ⇒ 只剩约 10 次',
            \count($seen) >= 9 && \count($seen) <= 11,
            '收到 ' . \count($seen) . ' 次：' . \implode(',', \array_map(static fn (float $f): string => \round($f, 3) . '', $seen)),
        );
        check(
            '进度 · 节流后仍然单调递增',
            $seen === \array_values(\array_unique($seen)) && $seen === \array_values(\array_filter($seen, static fn (float $f, int $k): bool => $k === 0 || $f > $seen[$k - 1], ARRAY_FILTER_USE_BOTH)),
            '序列=' . \implode(',', \array_map(static fn (float $f): string => \round($f, 3) . '', $seen)),
        );

        $this->reset();
        $this->flushChecks();
    }

    public function testFinalValueIsNeverThrottled(): void
    {
        $this->reset();

        $seen = [];
        JobProgress::bind(static function (float $f) use (&$seen): void {
            $seen[] = $f;
        });

        // ★ 这正是会踩坑的形态：最后一步很短（0.995 → 1.0，差 0.005 < 1%）
        //   ★★ 第一条必须**直接**是 0.995：若先报 0.99 再报 0.995，后者会被节流吞掉，
        //   于是"1.0 与上一次（0.99）差 0.01"恰好**不小于**阈值 ⇒ 变异（删掉 1.0 特例）
        //   照样通过 —— 判据无牙。第一版就是这么写的，变异实测全绿。
        JobProgress::report(0.995);
        JobProgress::report(1.0);

        $last = $seen === [] ? -1.0 : $seen[\count($seen) - 1];

        check(
            '进度 · 最后一步很短时 `1.0` 仍然报得出去（否则进度条永远停在 99%）',
            $last === 1.0,
            '最后一次=' . $last . ' 序列=' . \implode(',', \array_map(static fn (float $f): string => \round($f, 3) . '', $seen)),
        );

        $this->reset();
        $this->flushChecks();
    }

    public function testBackwardsAndDuplicatesAreDropped(): void
    {
        $this->reset();

        $seen = [];
        JobProgress::bind(static function (float $f) use (&$seen): void {
            $seen[] = $f;
        });

        JobProgress::report(0.5);
        JobProgress::report(0.3);  // 倒退
        JobProgress::report(0.5);  // 重复
        JobProgress::report(0.8);
        JobProgress::report(-1.0); // 越界，夹到 0.0 也是倒退
        JobProgress::report(2.0);  // 越界，夹到 1.0

        check(
            '进度 · 倒退与重复一律丢弃，越界被夹住（进度条不能往回跳）',
            $seen === [0.5, 0.8, 1.0],
            '序列=' . \implode(',', \array_map(static fn (float $f): string => \round($f, 3) . '', $seen)),
        );

        $this->reset();
        $this->flushChecks();
    }

    public function testFutureDropsProgressAfterSettle(): void
    {
        $future = Future::pending();
        $future->then(static fn (mixed $v): mixed => $v); // 挂上，避免"没人接"的 Fatal

        $seen = [];
        $future->onProgress(static function (float $f) use (&$seen): void {
            $seen[] = $f;
        });

        $future->reportProgress(0.4);
        $future->resolve('done');
        $future->reportProgress(0.9); // 已结算 ⇒ 丢

        check(
            '进度 · Future 已结算后到达的进度被丢弃（订阅者可能已经换了下一轮）',
            $seen === [0.4],
            '序列=' . \implode(',', \array_map(static fn (float $f): string => \round($f, 3) . '', $seen)),
        );
        check(
            '进度 · `hasProgress()` / `progressOr()` 反映最后一次真正收下的值',
            $future->hasProgress() && $future->progressOr() === 0.4,
            'has=' . ($future->hasProgress() ? 'true' : 'false') . ' p=' . $future->progressOr(),
        );

        $this->flushChecks();
    }

    /**
     * `Future` 侧的**单调**保护 —— 这一道与 `JobProgress` 那一道**不是重复**。
     *
     * ★ `JobProgress::report()` 的单调在 **worker 进程里**（跨进程之前）；`Future`
     *   这一道在 **UI 进程里**（跨进程之后）。中间隔着一条管道：乱序、重发、
     *   甚至"上一个任务的迟到进度包"都可能让它变脏。`ProcessTaskHost` 直接调
     *   `reportProgress()`，**不经过 `JobProgress`** ⇒ worker 侧那道挡不住它。
     *
     * ★ 第一版漏了这条（只测了"结算后丢弃"），变异"去掉单调保护"实测**全绿**。
     */
    public function testFutureRejectsBackwardsProgress(): void
    {
        $future = Future::pending();
        $future->then(static fn (mixed $v): mixed => $v);

        $seen = [];
        $future->onProgress(static function (float $f) use (&$seen): void {
            $seen[] = $f;
        });

        $future->reportProgress(0.6);
        $future->reportProgress(0.3); // 倒退 ⇒ 丢
        $future->reportProgress(0.6); // 相等 ⇒ 丢
        $future->reportProgress(0.8);
        $future->reportProgress(-5.0); // 越界夹到 0.0，仍是倒退
        $future->reportProgress(9.0);  // 越界夹到 1.0

        check(
            '进度 · `Future` 侧丢弃倒退与相等的进度（IPC 上乱序 / 重发是可能的）',
            $seen === [0.6, 0.8, 1.0],
            '序列=' . \implode(',', \array_map(static fn (float $f): string => \round($f, 3) . '', $seen)),
        );

        $future->resolve('ok');
        $this->flushChecks();
    }

    public function testLateSubscriptionGetsBackfill(): void
    {
        $future = Future::pending();
        $future->then(static fn (mixed $v): mixed => $v);

        $future->reportProgress(0.35);

        // ★ 晚订阅：`submit()` 返回之后才挂 `onProgress()` 是常态
        $seen = [];
        $future->onProgress(static function (float $f) use (&$seen): void {
            $seen[] = $f;
        });

        $future->reportProgress(0.7);

        check(
            '进度 · 晚订阅立刻补发一次当前值（不必自己判"我是不是错过了"）',
            $seen === [0.35, 0.7],
            '序列=' . \implode(',', \array_map(static fn (float $f): string => \round($f, 3) . '', $seen)),
        );

        $future->resolve('ok');
        $this->flushChecks();
    }

    public function testAsyncValueKeepsStateAndPayload(): void
    {
        $loading = AsyncValue::loading();
        $withProgress = $loading->withProgress(0.42);

        check(
            '进度 · `withProgress()` 保留状态（进度是第五维，不是第五态）',
            $withProgress->isLoading() && $withProgress->hasProgress() && $withProgress->progressOr() === 0.42,
            'state=' . $withProgress->stateName() . ' has=' . ($withProgress->hasProgress() ? 'true' : 'false') . ' p=' . $withProgress->progressOr(),
        );
        check(
            '进度 · `loading()` 本身没有进度（⇒ 界面画不定长转圈，不是 0% 的条）',
            !$loading->hasProgress(),
            'has=' . ($loading->hasProgress() ? 'true' : 'false'),
        );

        // ★★ 宿主必须是**非 loading** 的态：第一版拿 `loading()` 当宿主，于是变异
        //   "`withProgress()` 强制返回 LOADING" 照样绿 —— 因为本来就判不出来。
        //   **判据要能证伪，得让宿主与被测状态可区分。**
        $fromData = AsyncValue::data('payload')->withProgress(0.3);
        check(
            '进度 · `withProgress()` 不改状态（`data` 还是 `data`，不会被拉回 `loading`）',
            $fromData->hasValue() && !$fromData->isLoading() && $fromData->progressOr() === 0.3,
            'state=' . $fromData->stateName() . ' hasValue=' . ($fromData->hasValue() ? 'true' : 'false'),
        );

        // ★ 刷新场景：带着旧载荷进 loading，再挂进度 —— 载荷不能丢
        $withPayload = AsyncValue::loading()->carriedFrom(AsyncValue::data(['rows' => [1, 2]]))->withProgress(0.5);
        check(
            '进度 · 刷新时"旧载荷 + 进度"同时成立（列表不闪空，进度条也在走）',
            $withPayload->hasValue() && $withPayload->hasProgress(),
            'hasValue=' . ($withPayload->hasValue() ? 'true' : 'false') . ' hasProgress=' . ($withPayload->hasProgress() ? 'true' : 'false'),
        );

        // ★ `data()` 落地不带进度 —— 那时百分比没有意义
        check(
            '进度 · `data()` 造的值不带进度（完成之后不该还画着 99%）',
            !AsyncValue::data('x')->hasProgress(),
            'has=' . (AsyncValue::data('x')->hasProgress() ? 'true' : 'false'),
        );

        $this->flushChecks();
    }

    /**
     * **端到端**：真起一个 worker 进程，让它分段报进度。
     *
     * ★ 这条用例存在的唯一理由见类注释的表 —— 前两段离线可测，这一段不行。
     */
    public function testProgressCrossesTheProcessBoundary(): void
    {
        $host = new ProcessTaskHost(
            [\PHP_BINARY, __DIR__ . '/../../../tools/Smoke/App/worker.php'],
            SmokeJobRegistry::make(),
            1,
            10_000,
        );

        $steps = 4;
        $progress = [];
        $values = [];
        $errors = [];

        $future = $host->submit(new NamedProgressJob($steps, 20));
        $future->onProgress(static function (float $f) use (&$progress): void {
            $progress[] = $f;
        });
        $future->then(static function (mixed $v) use (&$values): void {
            $values[] = $v;
        })->catch(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e->getMessage();
        });

        // 与 `PumpHost::complete()` 同一条路：结算必须在 drain 的回调里做
        $deadline = \microtime(true) + 15.0;
        while (!$future->isDone() && \microtime(true) < $deadline) {
            $host->drain(static function (Completion $c): void {
                if ($c->error !== null) {
                    $c->future->reject($c->error);

                    return;
                }
                $c->future->resolve($c->value);
            });
            \usleep(5_000);
        }

        $host->shutdown();

        check(
            '进度 · 真进程往返：任务成功且**没有被进度行误判成失败**',
            $errors === [] && $values === [$steps],
            'errors=' . \implode('|', $errors) . ' values=' . \json_encode($values),
        );

        $count = \count($progress);
        check(
            '进度 · 收到多条进度（不是 0 条 —— 否则整条 IPC 根本没通）',
            $count >= 3,
            '收到 ' . $count . ' 条：' . \implode(',', \array_map(static fn (float $f): string => \round($f, 2) . '', $progress)),
        );

        $monotonic = true;
        for ($i = 1; $i < $count; $i++) {
            if ($progress[$i] <= $progress[$i - 1]) {
                $monotonic = false;
            }
        }
        check(
            '进度 · 单调递增（乱序 / 倒退会让进度条抽搐）',
            $count >= 2 && $monotonic,
            \implode(',', \array_map(static fn (float $f): string => \round($f, 2) . '', $progress)),
        );
        check(
            '进度 · 终值是 1.0（否则进度条永远停在 99%）',
            $count > 0 && $progress[$count - 1] === 1.0,
            '最后=' . ($count > 0 ? (string) $progress[$count - 1] : '(无)'),
        );

        $this->flushChecks();
    }
}
