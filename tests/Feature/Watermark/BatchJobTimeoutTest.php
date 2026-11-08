<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Watermark;

use App\Watermark\Jobs\DownloadResultsJob;
use App\Watermark\Jobs\UploadBatchJob;
use Drift\App\TimedJob;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 批量任务必须自己声明墙钟上限（§74）。
 *
 * ★ 两条任务都是「串行批量 + 单条上限远大于 Host 默认 30s」：
 *   上传单文件 300s、下载单张 120s。不声明就会被第一张慢文件误杀，
 *   而 worker 侧的 HTTP 还在等 —— 界面报超时，任务其实没卡。
 *
 * ★ 期望值写死下界、不从常量推导：判据要抓「表达式写错」，
 *   不能跟着常量一起放大（§68 的教训）。
 */
final class BatchJobTimeoutTest extends SmokeTestCase
{
    public function testUploadBatchDeclaresCoverageForEveryFile(): void
    {
        $three = new UploadBatchJob(
            ['policy' => 'p', 'signature' => 's'],
            [
                ['path' => 'a.png', 'key' => 'k1', 'name' => 'a.png'],
                ['path' => 'b.png', 'key' => 'k2', 'name' => 'b.png'],
                ['path' => 'c.png', 'key' => 'k3', 'name' => 'c.png'],
            ],
        );
        $one = new UploadBatchJob(
            ['policy' => 'p', 'signature' => 's'],
            [['path' => 'a.png', 'key' => 'k1', 'name' => 'a.png']],
        );

        check(
            'UploadBatchJob 声明为 TimedJob（Host 才会读它的上限）',
            $three instanceof TimedJob,
            'get_class=' . \get_class($three)
        );
        check(
            'UploadBatchJob 的上限覆盖 3 张 × 单文件上限（> 300s）',
            $three->timeoutMs() > 300_000,
            'timeoutMs=' . $three->timeoutMs()
        );
        check(
            '上限随批大小增长（3 张 > 1 张）',
            $three->timeoutMs() > $one->timeoutMs(),
            '3 张=' . $three->timeoutMs() . ' 1 张=' . $one->timeoutMs()
        );

        $this->flushChecks();
    }

    public function testDownloadBatchDeclaresCoverageForEveryUrl(): void
    {
        $job = new DownloadResultsJob(['https://x/1.png', 'https://x/2.png', 'https://x/3.png']);

        check(
            'DownloadResultsJob 声明为 TimedJob（Host 才会读它的上限）',
            $job instanceof TimedJob,
            'get_class=' . \get_class($job)
        );
        check(
            'DownloadResultsJob 的上限覆盖 3 张 × 单张上限（> 120s）',
            $job->timeoutMs() > 120_000,
            'timeoutMs=' . $job->timeoutMs()
        );

        $this->flushChecks();
    }
}
