<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\Job;
use Drift\App\SyncTaskHost;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;

/**
 * `SyncTaskHost` 的**慢任务诊断**（2026-10-30 P3）。
 *
 * ## 为什么默认后端需要这一条痕迹
 *
 * `SyncTaskHost` 是"未装配后端"时的默认值，它的诚实边界是"大响应时窗口短暂停顿"。
 * 但这条边界**在日志里没有痕迹** ⇒ 真机上"拖不动"永远归因成"这软件慢"，
 * 而不是"你没换后端"。诊断把后者变成一条可检索的事实。
 *
 * ## 判据只有两条
 *
 * | 用例 | 钉住的不变式 |
 * |---|---|
 * | ① 慢任务报一次 | 事实该被记下来，且**只记一次**（刷屏的诊断等于没诊断） |
 * | ② 快任务不报 | 阈值真的在执行 —— 否则每个同步后端应用启动就一堆噪音 |
 *
 * ★ 不做"启动期警告"是刻意的：那样每个没跑过慢任务的应用也白报一条。
 *   用事实（真跑了 50ms）当判据，与 Android 的 `NetworkOnMainThreadException` 同理。
 */
final class SyncTaskHostSlowTaskTest extends SmokeTestCase
{
    private static function job(int $sleepUs): Job
    {
        return new class ($sleepUs) implements Job {
            public function __construct(private readonly int $sleepUs)
            {
            }

            public function run(): string
            {
                if ($this->sleepUs > 0) {
                    \usleep($this->sleepUs);
                }

                return 'done';
            }
        };
    }

    /** @return list<string> 环形缓冲里提到 `SyncTaskHost` 的那几条 */
    private static function syncHostDiagnostics(): array
    {
        return \array_values(\array_filter(
            Errors::recentDiagnostics(),
            static fn (string $line): bool => \str_contains($line, 'SyncTaskHost'),
        ));
    }

    public function testSlowJobReportsOneDiagnosticOnly(): void
    {
        Errors::reset();

        $host = new SyncTaskHost();
        $slow = self::job(80_000);   // 80ms ≫ 阈值 50ms

        $host->submit($slow);
        $host->submit($slow);   // 第二条**不该**再报

        $found = self::syncHostDiagnostics();

        check('慢任务 · 报了一条诊断（同步后端卡住 UI 是事实，要留痕迹）', \count($found) === 1);
        check('慢任务 · 指出跑在 UI 线程上', \str_contains($found[0] ?? '', 'UI 线程'));
        check('慢任务 · 给出换后端的做法', \str_contains($found[0] ?? '', 'ProcessTaskHost'));
        check('慢任务 · 报出了耗时（可检索的量，不是"有点慢"）', \str_contains($found[0] ?? '', 'ms'));

        $this->flushChecks();
    }

    public function testFastJobReportsNothing(): void
    {
        Errors::reset();

        $host = new SyncTaskHost();
        $host->submit(self::job(0));        // 立即返回
        $host->submit(self::job(1_000));    // 1ms，远低于阈值

        check('快任务 · 一条诊断都不报（阈值真的在执行，不是摆设）', self::syncHostDiagnostics() === []);

        $this->flushChecks();
    }
}
