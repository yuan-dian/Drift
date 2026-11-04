<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Watermark;

use App\Watermark\Jobs\AddTaskJob;
use App\Watermark\Jobs\DownloadResultsJob;
use App\Watermark\Jobs\FetchSignJob;
use App\Watermark\Jobs\PollResultJob;
use App\Watermark\Jobs\UploadBatchJob;
use App\Watermark\Store\WatermarkStore;
use Drift\App\Job;
use Drift\App\TaskHost;
use Drift\Async\Future;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 副作用只在**结算**时发生（2026-11-04，DESIGN-ASYNCCOMMAND-SETTLED）。
 *
 * ## 为什么现成三份 Store 判据不够
 *
 * `StoreHostChainTest` / `PollRhythmTest` 用的 `RecordingTaskHost` 回**已结算** Future
 * ⇒ 每次 `run()` 只有 loading + data 两趟，且中间没有进度通知。而两次真事故
 * （接力标志被 loading 吃掉 / 轮询每轮排两个定时器）都出在「值订阅对**每一次**
 * 通知都跑」这件事上。本文件把 Future 换成可控的 `pending()`，一趟一趟手动交付：
 *
 * | 步骤 | 交付 | 不该发生的事 |
 * |---|---|---|
 * | 签名 | loading → progress → data | 前三趟不接力上传；data 那一趟恰好接力一次 |
 * | 建任务 | loading → data | loading 不查结果；data 那一趟恰好查一次 |
 * | 查询 | loading → progress → data | loading/progress 不排期；data 那一趟恰好排一拍 |
 *
 * ★ 钉的是「趟数」不是「最终状态」：结算缝漏挂 / 误挂回值订阅时，
 *   最终状态照样对，但提交次数或定时器数会翻倍 —— 与真 bug 同形。
 */
final class StoreSettledEffectsTest extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $path) {
            @\unlink($path);
        }
        $this->tmp = [];
    }

    #[Test]
    public function 三条副作用都只在结算时各跑一次(): void
    {
        $host = new DeferredHost();
        $ticks = 0;
        $store = $this->store($host, static function (int $ms, \Closure $cb) use (&$ticks): int {
            ++$ticks;

            return $ticks;
        });

        // ── ① 签名 → 接力上传 ──────────────────────────────────────────
        $store->upload();
        self::assertSame(FetchSignJob::class, $host->nameAt(0), '夹具前提：先取签名（走接力分支）');
        self::assertSame(0, $host->countOf(UploadBatchJob::class), 'loading 那一趟就接力上传了');

        $host->futureAt(0)->reportProgress(0.5);
        self::assertSame(0, $host->countOf(UploadBatchJob::class), '进度通知那一趟也接力上传了');

        $host->futureAt(0)->resolve(self::sign());
        self::assertSame(1, $host->countOf(UploadBatchJob::class), '签名结算后没有恰好接力一次');
        self::assertSame(UploadBatchJob::class, $host->nameAt(1));

        // ── ② 上传 → 建任务 → 立刻查一次 ────────────────────────────────
        $host->futureAt(1)->resolve(['keys' => ['k1']]);
        $store->submitTask();
        self::assertSame(AddTaskJob::class, $host->nameAt(2));
        self::assertSame(0, $host->countOf(PollResultJob::class), '建任务的 loading 那一趟就查了结果');

        $host->futureAt(2)->resolve(['taskId' => 'T-1']);
        self::assertSame(1, $host->countOf(PollResultJob::class), '建任务结算后没有恰好立刻查一次');
        self::assertSame(PollResultJob::class, $host->nameAt(3));

        // ── ③ 查询 → 排下一拍 ──────────────────────────────────────────
        self::assertSame(0, $ticks, '查询的 loading 那一趟就排了定时器');
        $host->futureAt(3)->reportProgress(0.9);
        self::assertSame(0, $ticks, '查询的进度通知那一趟也排了定时器');

        $host->futureAt(3)->resolve(self::pendingResult());
        self::assertSame(1, $ticks, '查询结算后没有恰好排一拍（每轮两个会让定时器按 2ⁿ 增长）');
        self::assertSame(0, $host->countOf(DownloadResultsJob::class), '还没终结就下载了');
    }

    /**
     * ★ 首次提交时「值订阅 / 结算订阅」恰好等价（`$polling` 初值 false ⇒ loading 趟空转），
     *   所以必须造**上一轮仍在轮询**的遗留态，才能把"loading 趟多查一次"逼出来。
     */
    #[Test]
    public function 重复提交时建任务的loading趟不多查一次(): void
    {
        $host = new DeferredHost();
        $ticks = [];
        $store = $this->store($host, static function (int $ms, \Closure $cb) use (&$ticks): int {
            $ticks[] = $cb;

            return \count($ticks);
        });

        $store->upload();
        $host->futureAt(0)->resolve(self::sign());
        $host->futureAt(1)->resolve(['keys' => ['k1']]);
        $store->submitTask();
        $host->futureAt(2)->resolve(['taskId' => 'T-1']);
        $host->futureAt(3)->resolve(self::pendingResult());
        self::assertTrue($store->isPolling(), '夹具前提：上一轮还在轮询');
        $before = $host->countOf(PollResultJob::class);
        self::assertSame(1, $before);

        // 第二次提交：此刻 `$polling` 仍是 true ⇒ 若副作用挂在值订阅上，
        // loading 那一趟就会 `pollOnce()`，比结算版多发一次查询。
        $store->submitTask();
        self::assertSame(
            $before,
            $host->countOf(PollResultJob::class),
            '建任务的 loading 那一趟多查了一次结果（副作用误挂值订阅）'
        );

        $host->futureAt(4)->resolve(['taskId' => 'T-2']);
        self::assertSame($before + 1, $host->countOf(PollResultJob::class), '建任务结算后该恰好查一次');
    }

    #[Test]
    public function 停止轮询的cancel回data不再排期或下载(): void
    {
        $host = new DeferredHost();
        /** @var list<\Closure> $ticks */
        $ticks = [];
        $store = $this->store($host, static function (int $ms, \Closure $cb) use (&$ticks): int {
            $ticks[] = $cb;

            return \count($ticks);
        });

        $store->upload();
        $host->futureAt(0)->resolve(self::sign());
        $host->futureAt(1)->resolve(['keys' => ['k1']]);
        $store->submitTask();
        $host->futureAt(2)->resolve(['taskId' => 'T-1']);

        // 第一次查询结算（pending）⇒ 排一拍；跑它 ⇒ 第二次查询在途，
        // 此时 `pollCommand` 带着上一次的 data ⇒ cancel() 回的正是 data 而不是 idle。
        $host->futureAt(3)->resolve(self::pendingResult());
        self::assertCount(1, $ticks, '夹具前提：第一次查询结算后排了一拍');
        ($ticks[0])();
        self::assertSame(2, $host->countOf(PollResultJob::class), '夹具前提：第二次查询已在途');
        self::assertTrue($store->isPolling());

        $store->stopPolling();

        self::assertCount(1, $ticks, 'cancel() 回 data 被当成结算 ⇒ 又排了一拍');
        self::assertSame(0, $host->countOf(DownloadResultsJob::class), 'cancel() 回 data 被当成结算 ⇒ 触发了下载');
        self::assertFalse($store->isPolling());
    }

    private function store(DeferredHost $host, \Closure $scheduler): WatermarkStore
    {
        $file = (string) \tempnam(\sys_get_temp_dir(), 'drift-wm-');
        $path = $file . '.jpg';
        \rename($file, $path);
        $this->tmp[] = $path;

        $store = new WatermarkStore($host, 'http://gw.invalid', $scheduler);
        self::assertSame(1, $store->addFiles([$path]), '夹具没被选上 ⇒ 后面全靠巧合');
        $store->setTime('2026-11-04 12:00:00');
        $store->setAddress('上海市浦东新区');

        return $store;
    }

    /** @return array<string, mixed> */
    private static function sign(): array
    {
        return [
            'account' => 'acc',
            'policy' => 'pol',
            'signature' => 'sig',
            'directoryPath' => '***REMOVED***/2026/11/4/',
            'accessAddress' => 'https://oss.example.com',
            'expireTime' => (string) ((\time() + 600) * 1000),
        ];
    }

    /** @return array<string, mixed> */
    private static function pendingResult(): array
    {
        return ['taskId' => 'T-1', 'status' => 2, 'downloadUrls' => []];
    }
}

/**
 * @internal 可控 `TaskHost`：每个任务回一条 `Future::pending()`，由测试逐趟交付。
 *
 * 与 `RecordingTaskHost` 的差别只在"何时结算" —— 那个测提交顺序，这个测通知趟数。
 */
final class DeferredHost implements TaskHost
{
    /** @var list<array{job: Job, future: Future}> */
    public array $queue = [];

    public function submit(Job $job): Future
    {
        $future = Future::pending();
        $this->queue[] = ['job' => $job, 'future' => $future];

        return $future;
    }

    public function futureAt(int $index): Future
    {
        TestCase::assertArrayHasKey($index, $this->queue, '没有第 ' . $index . ' 个任务');

        return $this->queue[$index]['future'];
    }

    public function nameAt(int $index): string
    {
        TestCase::assertArrayHasKey($index, $this->queue, '没有第 ' . $index . ' 个任务');

        return \get_class($this->queue[$index]['job']);
    }

    public function countOf(string $class): int
    {
        $n = 0;
        foreach ($this->queue as $entry) {
            if (\get_class($entry['job']) === $class) {
                ++$n;
            }
        }

        return $n;
    }

    public function pending(): int
    {
        return \count($this->queue);
    }

    public function needsPolling(): bool
    {
        return false;
    }

    public function drain(callable $onEach, ?int $budgetMs = null): void
    {
    }
}
