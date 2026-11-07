<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Watermark;

use App\Watermark\Api\Config;
use App\Watermark\Jobs\AddTaskJob;
use App\Watermark\Jobs\DownloadResultsJob;
use App\Watermark\Jobs\FetchSignJob;
use App\Watermark\Jobs\PollResultJob;
use App\Watermark\Jobs\UploadBatchJob;
use App\Watermark\Store\StatusMessage;
use App\Watermark\Store\WatermarkStore;
use Drift\Dev\Tests\Support\RecordingTaskHost;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 轮询必须**停得下来**（§68 B2）。
 *
 * ★ 迁移后的 `pollLoop()` 是 `while (true)`，唯一的退出是「查到终态」与
 *   「没有定时器」；`fetchResult()` 失败只写一句「会继续重试」 ⇒ 网关一直挂、
 *   或服务端一直答「处理中」时，循环永不结束，也不报错。
 *
 * ★ 判据量的是外部可观察的终态：`statusLine` 变错误、`Phase` 归位可操作、
 *   **不再提交查询、不再排定时器**。只数 `sleep()` 被调用几次是实现同源，不算判据。
 */
final class PollExitTest extends TestCase
{
    /**
     * 期望写死，不从 `Config` 读 —— 否则把上限改成 99999，测试的循环与断言
     * 会一起跟着放大，判据变成实现同源、永远绿。改配置必须同步改这里并重新裁决。
     */
    private const EXPECTED_FAILURES = 5;

    private const EXPECTED_ROUNDS = 60;

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
    public function 连续查询失败到上限就停止自动重试(): void
    {
        self::assertSame(self::EXPECTED_FAILURES, Config::POLL_MAX_FAILURES, '配置变了要同步更新本文件的判据');
        [$store, $host, $timers] = $this->running(null);

        self::assertStringContainsString('继续重试', $store->statusLine()->text, '夹具前提：查询确实失败了');
        self::assertSame(1, $timers->count(), '第一次失败仍该排下一拍');

        for ($i = 0; $i < self::EXPECTED_FAILURES - 1; $i++) {
            $timers->run($i);
        }

        self::assertCount(
            self::EXPECTED_FAILURES,
            $host->ofClass(PollResultJob::class),
            '失败到上限之后还在查（或提前就停了）'
        );
        self::assertSame(self::EXPECTED_FAILURES - 1, $timers->count(), '停止之后又排了一拍');
        self::assertSame(StatusMessage::ERROR, $store->statusLine()->kind, '停了却不说一句 ⇒ 界面像卡住');
        self::assertStringContainsString('停止', $store->statusLine()->text);
        self::assertFalse($store->isPolling(), '停止后阶段没归位 ⇒ "停止轮询"按钮永远挂着');
        self::assertTrue($store->canSubmit(), '停止后界面要回到可操作态，否则按钮永久灰');
    }

    #[Test]
    public function 一直查不到终态时按总轮数上限停下(): void
    {
        self::assertSame(self::EXPECTED_ROUNDS, Config::POLL_MAX_ROUNDS, '配置变了要同步更新本文件的判据');
        [$store, $host, $timers] = $this->running(static fn(): array => [
            'taskId' => 'T-1',
            'status' => 2,
            'downloadUrls' => [],
        ]);

        for ($i = 0; $i < self::EXPECTED_ROUNDS - 1; $i++) {
            $timers->run($i);
        }

        self::assertCount(self::EXPECTED_ROUNDS, $host->ofClass(PollResultJob::class));
        self::assertSame(self::EXPECTED_ROUNDS - 1, $timers->count(), '到上界之后不许再排一拍');
        self::assertSame(StatusMessage::ERROR, $store->statusLine()->kind);
        self::assertStringContainsString('停止', $store->statusLine()->text);
        self::assertFalse($store->isPolling());
        self::assertTrue($store->canSubmit());
        self::assertCount(0, $host->ofClass(DownloadResultsJob::class), '没拿到终态不该去下载');
    }

    /**
     * 起一个「已提交任务、正在轮询」的 store。
     *
     * @param null|\Closure(): array<string, mixed> $pollAnswer null ⇒ 不配 PollResultJob
     *        （RecordingTaskHost 会回 rejected Future，即"网关一直失败"）
     */
    private function running(?\Closure $pollAnswer): array
    {
        $timers = new ExitTimers();
        $answers = [
            FetchSignJob::class => [
                'account' => 'acc',
                'policy' => 'pol',
                'signature' => 'sig',
                'directoryPath' => '***REMOVED***/2026/11/7/',
                'accessAddress' => 'https://oss.example.com',
                'expireTime' => (string) ((\time() + 600) * 1000),
            ],
            UploadBatchJob::class => ['keys' => ['k1']],
            AddTaskJob::class => ['taskId' => 'T-1'],
            DownloadResultsJob::class => ['paths' => [], 'failed' => []],
        ];
        if ($pollAnswer !== null) {
            $answers[PollResultJob::class] = static fn(): array => $pollAnswer();
        }
        $host = new RecordingTaskHost($answers);

        $file = (string) \tempnam(\sys_get_temp_dir(), 'drift-wm-');
        $path = $file . '.jpg';
        \rename($file, $path);
        $this->tmp[] = $path;

        $scheduler = static function (int $ms, \Closure $callback) use ($timers): int {
            return $timers->after($ms, $callback);
        };

        $store = new WatermarkStore($host, 'http://gw.invalid', $scheduler);
        self::assertSame(1, $store->addFiles([$path]), '夹具没被选上 ⇒ 后面全靠巧合');
        $store->setTime('2026-11-07 12:00:00');
        $store->setAddress('上海市浦东新区');
        $store->upload();
        $store->submitTask();

        return [$store, $host, $timers];
    }
}

/** @internal 手动交付的定时器队列（同 PollRhythmTest::FakeTimers 的形状） */
final class ExitTimers
{
    /** @var list<array{0: int, 1: \Closure}> */
    public array $ticks = [];

    public function after(int $ms, \Closure $callback): int
    {
        $this->ticks[] = [$ms, $callback];

        return \count($this->ticks);
    }

    public function count(): int
    {
        return \count($this->ticks);
    }

    public function run(int $index): void
    {
        TestCase::assertArrayHasKey($index, $this->ticks, '没有第 ' . $index . ' 拍可跑');

        ($this->ticks[$index][1])();
    }
}
