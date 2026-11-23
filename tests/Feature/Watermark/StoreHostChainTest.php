<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Watermark;

use App\Watermark\Jobs\AddTaskJob;
use App\Watermark\Jobs\FetchSignJob;
use App\Watermark\Jobs\JobRegistry;
use App\Watermark\Jobs\PollResultJob;
use App\Watermark\Jobs\UploadBatchJob;
use App\Watermark\Store\WatermarkStore;
use Drift\App\NamedJob;
use Drift\Dev\Tests\Support\RecordingTaskHost;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `WatermarkStore` 的**后台那一半**（`$host !== null`）—— 真机唯一走的路。
 *
 * ## 为什么这个文件必须存在（2026-11-02 真事故）
 *
 * Store 的每个动作都有两半：`$host === null` 走同步（解释器 / 单测），
 * `$host !== null` 走后台。此前**所有**测试都构造 `host = null` ⇒ 后台那半行
 * 代码从没被执行过。于是一个漏 `use` 的类名能一路活到用户点按钮，
 * 而 `php -l` / smoke / phpunit / lint 四道闸门全绿 —— 因为**没有一条判据落在
 * 被真机执行的那个分支上**（本仓的老话：未被消费的分支 = 未实现）。
 * lint 现在有 `class-missing-use` 挡这一类，但"分支有没有被执行过"
 * 仍得由测试回答 —— 那是静态规则替代不了的。
 *
 * ## 钉住两件事
 *
 * ① **链路顺序**：取签名 → 上传 → 建任务 → 查结果，四个任务一个不少；
 * ② **每个任务都能被 `JobRegistry` 从 `args()` 重建** —— 这是 `NamedJob` 的
 *   跨进程契约（worker 侧只有名字 + 参数）。只判 ① 的话，"参数形状漂了"
 *    要等真机 worker 才炸。
 *
 * ★ 用 `RecordingTaskHost`（已结算 Future）⇒ `then()` 立刻跑，整条链在
 *   `submitTask()` 里同步走完，不需要推帧。
 */
final class StoreHostChainTest extends TestCase
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
    public function 后台链路从取签名一路走到结果落库(): void
    {
        $urls = ['https://oss.example.com/r/1.png', 'https://oss.example.com/r/2.png'];
        $host = $this->host($urls);
        $store = $this->store($host);

        $store->upload();
        $store->submitTask();

        // 四个任务，顺序即业务流程；结果图下载由 Image 控件自治，不在 Store 链路里。
        self::assertSame(
            [FetchSignJob::class, UploadBatchJob::class, AddTaskJob::class, PollResultJob::class],
            $host->names(),
            '（取签名 → 上传 → 建任务 → 查结果）缺一环，真机就少一段'
        );
        self::assertTrue($store->lastResult()?->isSuccess() ?? false);
        self::assertSame($urls, $store->lastResult()?->downloadUrls);
    }

    /**
     * `NamedJob` 的跨进程契约：worker 侧只有 **名字 + 参数** ⇒ 每个提交过的任务
     * 都必须能被 `JobRegistry` 用 `args()` 原样重建。
     *
     * ★ 这条判据同时钉住两件事：注册表没漏登记、`args()` 与构造器一一对应
     *   （参数对不上会在 worker 侧变成 TypeError，而 UI 侧一切正常）。
     */
    #[Test]
    public function 每个提交的任务都能被注册表按参数重建(): void
    {
        $host = $this->host(['https://oss.example.com/r/1.png']);
        $store = $this->store($host);
        $store->upload();
        $store->submitTask();

        $registry = JobRegistry::make();
        self::assertNotSame([], $host->submitted, '没提交任何任务 ⇒ 下面的循环是空转');

        foreach ($host->submitted as $job) {
            self::assertInstanceOf(NamedJob::class, $job);
            $name = $job->name();
            self::assertArrayHasKey($name, $registry, '注册表漏了 ' . $name);

            $rebuilt = $registry[$name]($job->args());
            self::assertSame(\get_class($job), \get_class($rebuilt), $name . ' 重建后类型变了');
            self::assertSame($job->args(), $rebuilt->args(), $name . ' 的参数在重建后丢失或被改写');
        }
    }

    /** @param list<string> $urls */
    private function host(array $urls): RecordingTaskHost
    {
        return new RecordingTaskHost([
            FetchSignJob::class => [
                'account' => 'acc',
                'policy' => 'pol',
                'signature' => 'sig',
                'directoryPath' => '10000000/2026/11/2/',
                'accessAddress' => 'https://oss.example.com',
                'expireTime' => (string) ((\time() + 600) * 1000),
            ],
            UploadBatchJob::class => ['keys' => ['k1']],
            AddTaskJob::class => ['taskId' => 'T-1'],
            PollResultJob::class => [
                'taskId' => 'T-1',
                'status' => 3,
                'templateName' => '修改水印',
                'imageSize' => '2K',
                'processingTime' => '34s',
                'createTime' => '2026-11-02 12:00:00',
                'updateTime' => '2026-11-02 12:00:34',
                'errorMsg' => '',
                'downloadUrls' => $urls,
            ],
        ]);
    }

    private function store(RecordingTaskHost $host): WatermarkStore
    {
        $file = (string) \tempnam(\sys_get_temp_dir(), 'drift-wm-');
        $path = $file . '.jpg';
        \rename($file, $path);
        $this->tmp[] = $path;

        $store = new WatermarkStore($host, 'http://gw.invalid');
        self::assertSame(1, $store->addFiles([$path]), '夹具没被选上 ⇒ 后面全靠巧合');
        $store->setTime('2026-11-02 12:00:00');
        $store->setAddress('上海市浦东新区');

        return $store;
    }
}
