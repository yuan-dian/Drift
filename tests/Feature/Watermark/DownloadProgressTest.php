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
 * 下载进度必须真的落到界面切片（§68）。
 *
 * ★ `DownloadResultsJob` 每张结果图都 `JobProgress::report()`，但 `downloadResults()`
 *   的 `await()` 曾漏了第二参 ⇒ 进度没人订、界面 N 张图全程只有转圈，且不报错。
 *   上传侧 `await($future, fn)` 是现成模板，缺的只是接线。
 *
 * ★ 判据量的是 **store 的公开切片**（界面订阅的东西），不是「回调有没有被挂上」：
 *   把第二参删掉、或改回手工接线，本文件必须红。
 */
final class DownloadProgressTest extends TestCase
{
    /** @var list<string> 临时文件，测完清掉 */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $path) {
            @\unlink($path);
        }
        $this->tmp = [];
    }

    #[Test]
    public function 下载进度逐张落到界面并在结算时收尾(): void
    {
        $host = new ProgressHost();
        $store = $this->downloading($host);

        self::assertSame(DownloadResultsJob::class, $host->nameAt(4), '夹具前提：下载任务已在途');
        self::assertTrue($store->isDownloading(), '没进下载阶段 ⇒ 后面全靠巧合');
        self::assertSame(0.0, $store->downloadProgress(), '开局进度必须是 0，不能留着上一批的 1.0');
        self::assertStringContainsString('0 /', $store->downloadMessage()->text);

        // 三张图里下完第二张
        $host->futureAt(4)->reportProgress(2.0 / 3.0);

        self::assertEqualsWithDelta(2.0 / 3.0, $store->downloadProgress(), 0.0001, '进度没落到切片 ⇒ 进度条恒 0');
        self::assertStringContainsString('67%', $store->downloadMessage()->text, '文案没跟着进度动');
        self::assertTrue($store->isDownloading(), '进度通知把流水线推进了（或提前收摊）');

        $host->futureAt(4)->resolve([
            'paths' => ['https://oss.example.com/r/1.png' => '/tmp/drift-r1.png'],
            'failed' => [],
        ]);

        self::assertSame(1.0, $store->downloadProgress(), '结算后进度要收尾，不能停在 67%');
        self::assertFalse($store->isDownloading(), '结算后阶段没归位 ⇒ 进度条永远挂着');
        self::assertSame(
            ['https://oss.example.com/r/1.png' => '/tmp/drift-r1.png'],
            $store->localPaths(),
            '进度接线不该碰结果落地'
        );
    }

    #[Test]
    public function 下载失败时进度文案归到错误态而不假装在下载(): void
    {
        $host = new ProgressHost();
        $store = $this->downloading($host);

        $host->futureAt(4)->resolve([
            'paths' => [],
            'failed' => ['https://oss.example.com/r/1.png' => 'HTTP 404'],
        ]);

        self::assertSame(1.0, $store->downloadProgress());
        self::assertStringContainsString('没能下载', $store->downloadMessage()->text);
        self::assertCount(1, $store->failedDownloads());
    }

    /** 走到「下载任务在途」 */
    private function downloading(ProgressHost $host): WatermarkStore
    {
        $file = (string) \tempnam(\sys_get_temp_dir(), 'drift-wm-');
        $path = $file . '.jpg';
        \rename($file, $path);
        $this->tmp[] = $path;

        // scheduler 为 null：终态直接 return，不走 sleep。
        $store = new WatermarkStore($host, 'http://gw.invalid', null);
        self::assertSame(1, $store->addFiles([$path]), '夹具没被选上 ⇒ 后面全靠巧合');
        $store->setTime('2026-11-07 12:00:00');
        $store->setAddress('上海市浦东新区');

        $store->upload();
        self::assertSame(FetchSignJob::class, $host->nameAt(0));
        $host->futureAt(0)->resolve([
            'account' => 'acc',
            'policy' => 'pol',
            'signature' => 'sig',
            'directoryPath' => '10000000/2026/11/7/',
            'accessAddress' => 'https://oss.example.com',
            'expireTime' => (string) ((\time() + 600) * 1000),
        ]);
        self::assertSame(UploadBatchJob::class, $host->nameAt(1));
        $host->futureAt(1)->resolve(['keys' => ['k1']]);

        $store->submitTask();
        self::assertSame(AddTaskJob::class, $host->nameAt(2));
        $host->futureAt(2)->resolve(['taskId' => 'T-1']);
        self::assertSame(PollResultJob::class, $host->nameAt(3));
        $host->futureAt(3)->resolve([
            'taskId' => 'T-1',
            'status' => 3,
            'templateName' => '修改水印',
            'imageSize' => '2K',
            'processingTime' => '34s',
            'createTime' => '2026-11-07 12:00:00',
            'updateTime' => '2026-11-07 12:00:34',
            'errorMsg' => '',
            'downloadUrls' => ['https://oss.example.com/r/1.png'],
        ]);

        return $store;
    }
}

/**
 * @internal 逐趟交付的 TaskHost。另起一个而不是复用 `StoreSettledEffectsTest`
 *   文件内的 `DeferredHost`：那个类只在那个文件被加载时才存在，本文件单跑会炸。
 */
final class ProgressHost implements TaskHost
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

    public function cancelSupport(): \Drift\App\CancelSupport
    {
        // 假后端同步交付（Future 要么已结算、要么由测试手动结算）⇒ 没有在跑的活
        return \Drift\App\CancelSupport::AlreadyDone;
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
