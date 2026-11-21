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
use Drift\App\Job;
use Drift\App\TaskHost;
use Drift\Async\Future;
use Drift\Dev\Smoke\App\FakeEnvironment;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 副作用只在**结算**时发生，以及「互斥由形状保证」（2026-11-04 迁移后重写）。
 *
 * ## 这个文件为什么还在
 *
 * 迁移前它钉的是「值订阅 vs 结算订阅的趟数」—— `run()` 先广播 `loading`，
 * 进度还会再广播几趟，副作用误挂到值订阅上就会多查一次 / 每轮排两个定时器。
 * 两次真事故都出在那里。
 * ★ 「结算订阅」那条缝（`subscribeSettledFor()`）本身也已删除（`DEBT` §33）——
 *   它只在旧形状里有意义。
 *
 * 迁移后流水线是**一段顺序代码**：`await()` 只认结算（`then/ catch`），进度通知
 * 根本碰不到下一行。§10.4 说这个坑"结构上消失"了 —— 但**结构上消失的事仍要判据**，
 * 否则哪天有人把 `await` 写成 `->then()` 并 `return`，坑会原样回来。所以本文件
 * 保留，判的事换成新形状的等价物：
 *
 * | 用例 | 钉住的不变式 | 坏了的现象 |
 * |---|---|---|
 * | ① 上传进度通知不推进流水线 | 进度只改界面（`uploadProgress` / 文案） | 进度一到就开始建任务 |
 * | ② 查询每轮只排一个定时器 | 定时器只从 `sleep()` 里来 | 按 2ⁿ 增长（曾经的事故形态） |
 * | ③ 轮询中不能提交第二条 | `Phase` 是唯一一个"在忙什么" | 两条轮询互相覆盖 `lastResult` |
 * | ④ 停止轮询后不再查、不下载 | 取消打整条流水线，`finally` 归位 | 点了停止还在查，且不报错 |
 *
 * ★ 未知时序一律用 `DeferredHost`（每个任务回 `Future::pending()`，逐趟手动交付）：
 *   `RecordingTaskHost` 回已结算 Future，中间态根本不存在。
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
    public function 上传的进度通知只改界面不推进流水线(): void
    {
        $host = new DeferredHost();
        [$store] = $this->started($host);

        self::assertSame(UploadBatchJob::class, $host->nameAt(1), '夹具前提：签名已到手，上传在途');
        self::assertSame(2, $host->pending(), '夹具前提：此刻只有取签名与上传两个任务');

        // ── 进度通知：界面动，流水线不动 ────────────────────────────────
        $host->futureAt(1)->reportProgress(0.5);

        self::assertSame(0.5, $store->uploadProgress(), '进度没上界面 ⇒ 进度条永远 0%');
        self::assertSame(2, $host->pending(), '进度通知推进了流水线（多发了一个任务）');
        self::assertSame(StatusMessage::LOADING, $store->uploadMessage()->kind);
        self::assertStringContainsString('50%', $store->uploadMessage()->text, '文案没跟着进度动');
        self::assertTrue($store->isUploading());

        // ── 结算才推进 ──────────────────────────────────────────────────
        $host->futureAt(1)->resolve(['keys' => ['k1', 'k2']]);

        self::assertSame(['k1', 'k2'], $store->uploadedKeys());
        self::assertFalse($store->isUploading(), '结算之后还停在 Phase::Uploading ⇒ 按钮永久灰');
        self::assertTrue($store->canSubmit(), '上传完成却不允许提交');
        self::assertSame(0.0, $store->uploadProgress(), '离开上传阶段要归零，否则下次进来先闪一条满进度条');
    }

    #[Test]
    public function 查询每轮只排一个定时器(): void
    {
        $host = new DeferredHost();
        $env = new FakeEnvironment();
        [$store] = $this->started($host, $env);

        // 上传结算 ⇒ 才能提交；提交 ⇒ 建任务结算 ⇒ **立刻**查一次（不等一个间隔）
        $host->futureAt(1)->resolve(['keys' => ['k1']]);
        $store->submitTask();
        $host->futureAt(2)->resolve(['taskId' => 'T-1']);
        self::assertSame(PollResultJob::class, $host->nameAt(3));
        self::assertCount(0, $env->ticks, '查询还在途就排了定时器 ⇒ 会和这次查询并行');

        // 进度通知不排期（迁移前这里是"每轮两个定时器"的入口）
        $host->futureAt(3)->reportProgress(0.9);
        self::assertCount(0, $env->ticks, '查询的进度通知排了定时器');

        // 结算成"处理中" ⇒ 恰好排一拍，间隔来自 Config
        $host->futureAt(3)->resolve(self::pendingResult());
        self::assertCount(1, $env->ticks, '结算后没有恰好排一拍');
        self::assertSame(Config::POLL_INTERVAL_MS, $env->ticks[0][0], '间隔必须来自 Config，不能在别处写死');

        // 交付这一拍 ⇒ 再查一次，且**这一刻仍只有一个定时器在册**
        $env->run(0);
        self::assertSame(2, $host->countOf(PollResultJob::class), '排了的那一拍没有真的去查');
        self::assertCount(1, $env->ticks, '第 2 次查询在途时又冒出一个定时器 ⇒ 2ⁿ 增长的老事故');
        self::assertTrue($store->isPolling());
    }

    #[Test]
    public function 轮询中提交任务会被挡住而不是起第二条(): void
    {
        $host = new DeferredHost();
        $env = new FakeEnvironment();
        [$store] = $this->started($host, $env);

        $host->futureAt(1)->resolve(['keys' => ['k1']]);
        $store->submitTask();
        $host->futureAt(2)->resolve(['taskId' => 'T-1']);
        $host->futureAt(3)->resolve(self::pendingResult());
        self::assertTrue($store->isPolling(), '夹具前提：正在轮询');
        $before = $host->countOf(PollResultJob::class);

        // ★ 迁移前 `canSubmit()` 判的是 `!taskCommand->isRunning()` —— 轮询中该命令
        //   并不在跑 ⇒ 按钮是活的 ⇒ 点一下起第二条轮询，两条互相覆盖 lastResult。
        $store->submitTask();

        self::assertSame(
            $before,
            $host->countOf(PollResultJob::class),
            '轮询中点"提交任务"又起了一条查询'
        );
        self::assertSame(1, $host->countOf(AddTaskJob::class), '又提交了一次建任务');
        self::assertSame(StatusMessage::ERROR, $store->taskMessage()->kind, '挡住了却不说一句 ⇒ 用户以为按钮坏了');
        self::assertStringContainsString('稍候', $store->taskMessage()->text);
    }

    #[Test]
    public function 停止轮询后迟到的查询结果不落地也不再下载(): void
    {
        $host = new DeferredHost();
        $env = new FakeEnvironment();
        [$store] = $this->started($host, $env);

        $host->futureAt(1)->resolve(['keys' => ['k1']]);
        $store->submitTask();
        $host->futureAt(2)->resolve(['taskId' => 'T-1']);
        $host->futureAt(3)->resolve(self::pendingResult());
        self::assertCount(1, $env->ticks, '夹具前提：第一次查询结算后排了一拍');

        $env->run(0);
        self::assertSame(2, $host->countOf(PollResultJob::class), '夹具前提：第二次查询已在途');
        self::assertTrue($store->isPolling());

        $store->stop();

        self::assertFalse($store->isPolling(), '取消后阶段没有归位 ⇒ "停止轮询"按钮永远挂着');
        self::assertTrue($store->canUpload(), '取消后应该回到可操作态');
        self::assertCount(1, $env->ticks, '停止之后又排了一拍');
        self::assertSame(
            StatusMessage::INFO,
            $store->statusLine()->kind,
            '取消被当成"查询失败"报了出来 —— 用户点了停止，却看到一条错误'
        );

        // ★ 后端还在跑，结果迟到 —— 不许落到界面，更不许触发下载。
        //   （`Future::cancel()` 让 `resolve()` 首句就 return，这是它的硬契约。）
        $host->futureAt(4)->resolve(self::successResult());

        self::assertTrue(
            $store->lastResult()?->isPending() ?? false,
            '取消之后迟到的成功结果落到了界面上'
        );
        self::assertSame(0, $host->countOf(DownloadResultsJob::class), '停止之后还去下载了');
    }

    // ══════════════════ 夹具 ══════════════════

    /** 起一个走到「签名已到手、上传在途」的 store */
    private function started(DeferredHost $host, ?FakeEnvironment $env = null): array
    {
        $file = (string) \tempnam(\sys_get_temp_dir(), 'drift-wm-');
        $path = $file . '.jpg';
        \rename($file, $path);
        $this->tmp[] = $path;

        $store = new WatermarkStore($host, 'http://gw.invalid', $env);
        self::assertSame(1, $store->addFiles([$path]), '夹具没被选上 ⇒ 后面全靠巧合');
        $store->setTime('2026-11-04 12:00:00');
        $store->setAddress('上海市浦东新区');

        $store->upload();
        self::assertSame(FetchSignJob::class, $host->nameAt(0), '夹具前提：手上没签名 ⇒ 先取');
        $host->futureAt(0)->resolve(self::sign());

        return [$store];
    }

    /** @return array<string, mixed> */
    private static function sign(): array
    {
        return [
            'account' => 'acc',
            'policy' => 'pol',
            'signature' => 'sig',
            'directoryPath' => '10000000/2026/11/4/',
            'accessAddress' => 'https://oss.example.com',
            'expireTime' => (string) ((\time() + 600) * 1000),
        ];
    }

    /** @return array<string, mixed> */
    private static function pendingResult(): array
    {
        return ['taskId' => 'T-1', 'status' => 2, 'downloadUrls' => []];
    }

    /** @return array<string, mixed> */
    private static function successResult(): array
    {
        return [
            'taskId' => 'T-1',
            'status' => 3,
            'templateName' => '修改水印',
            'imageSize' => '2K',
            'processingTime' => '34s',
            'createTime' => '2026-11-04 12:00:00',
            'updateTime' => '2026-11-04 12:00:34',
            'errorMsg' => '',
            'downloadUrls' => ['https://oss.example.com/r/1.png'],
        ];
    }
}

/**
 * @internal 可控 `TaskHost`：每个任务回一条 `Future::pending()`，由测试逐趟交付。
 *
 * 与 `RecordingTaskHost` 的差别只在"何时结算" —— 那个测提交顺序，这个测中间态。
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
