<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Watermark;

use App\Watermark\Api\Config;
use App\Watermark\Jobs\AddTaskJob;
use App\Watermark\Jobs\FetchSignJob;
use App\Watermark\Jobs\PollResultJob;
use App\Watermark\Jobs\UploadBatchJob;
use App\Watermark\Store\WatermarkStore;
use Drift\Dev\Smoke\App\FakeEnvironment;
use Drift\Dev\Tests\Support\RecordingTaskHost;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 轮询的**节奏**：排期、间隔、终止、取消（2026-11-03，2026-11-04 迁移后仍适用）。
 *
 * ## 这个文件为什么还在
 *
 * 迁移前轮询是 `bool $polling` + 一个注入的 `$scheduler` + **三个守着它的人**
 * （`absorbPoll` 的排期、定时器回调里的 `if (!$this->polling)`、`stopPolling()` 的取消）。
 * 现在它是一段 `while` + `$co->sleep()`，**排期只有一个来源** —— 所以本文件钉的
 * 三件事从"守纪律"变成了"守行为"：间隔来自 `Config` / 终结不再排 / 手动停能作废
 * 已排的那一拍。行为没变，这正是判据不改就该绿的道理。
 *
 * ★ 那两次事故是本文件的由来，值得留着：2026-11-02，`absorbSign` 的接力标志被
 *   `loading` 那一趟通知吃掉 ⇒ "签名取回来了却永远不接力上传"，**不报错、界面停在
 *   「正在获取上传凭证…」**；而 `scheduleNextPoll()` 在此之前**零判据**（假后端一次
 *   就把结果答成终态 ⇒ "继续排下一次"那条分支从没被执行过）。本仓的老话：
 *   **未被消费的分支 = 未实现**。
 *
 * ## 判据四类
 *
 * | 用例 | 钉住的不变式 | 坏了的现象 |
 * |---|---|---|
 * | ① 未完 ⇒ 按固定间隔排下一拍 | 间隔就是 `Config::POLL_INTERVAL_MS` | 改一处常量、另一处写死 ⇒ 快慢两套节奏 |
 * | ② 终结 ⇒ **不再排**也不再提交任务 | 轮询会自己停 | 停不下来：每 10s 查一次到天荒地老 |
 * | ③ 手动停 ⇒ 已排的那一拍作废 | 取消是幂等的 | 点了"停止"之后还在查（且不报错） |
 * | ④ 没有 scheduler ⇒ 只查一次 | 测试/解释器里没有"自己转起来的定时器" | 单测里凭空起一个定时器，时序全乱 |
 *
 * ★ 定时器是**替身**（`FakeTimers`）：本类不睡、不等，`run()` 手动触发 ——
 *   与 `TapTracker` 用显式时间戳同一个理由（"时间"必须能被判据驱动）。
 *   迁移后它同时是 `AsyncScope::sleep()` 的延时函数，所以"排了几拍"直接就是
 *   "`sleep()` 被调了几次"。
 * ★ 链路的前半段（取签名 → 上传 → 建任务）沿用 `StoreHostChainTest` 的夹具形态，
 *   不重复造。
 */
final class PollRhythmTest extends TestCase
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
    public function 未完的查询会按固定间隔排下一拍(): void
    {
        [$store, $host, $timers] = $this->running(pending: 2);

        self::assertSame(1, $timers->count(), '第一次查完（还没终结）就该排下一拍（且只排一拍 —— 每轮排两次会让定时器按 2ⁿ 增长）');
        self::assertSame(Config::POLL_INTERVAL_MS, $timers->ticks[0][0], '间隔必须来自 Config，不能在别处写死一个数');
        self::assertTrue($store->isPolling(), '还没终结就自己停了 ⇒ 结果永远等不到');

        // 跑掉这一拍 ⇒ 再查一次（仍未终结）⇒ 再排一拍
        $timers->run(0);

        self::assertCount(2, $host->ofClass(PollResultJob::class), '排了的那一拍没有真的去查');
        self::assertSame(2, $timers->count(), '第二次仍未终结 ⇒ 应该再排一拍');
        self::assertTrue($store->isPolling());
        self::assertTrue($store->lastResult()?->isPending() ?? false, '查到"处理中"要落到 `lastResult()`，否则界面没有进展可画');
    }

    #[Test]
    public function 终结的查询不再排期也不再提交任务(): void
    {
        [$store, $host, $timers] = $this->running(pending: 1);

        // 第 1 次（建任务后立刻查）仍是 pending ⇒ 排了 1 拍；跑它 ⇒ 第 2 次是终态
        self::assertSame(1, $timers->count());
        $before = \count($host->submitted);
        $timers->run(0);

        self::assertFalse($store->isPolling(), '终态之后还在轮询 ⇒ 每 10 秒空转一次，永远停不下来');
        self::assertSame(1, $timers->count(), '终态之后**不许**再排下一拍');
        self::assertTrue($store->lastResult()?->isSuccess() ?? false);

        // 终态后不再提交任务：结果图由 `Image` 控件自己加载；那一拍查询本身算一个。
        self::assertCount($before + 1, $host->submitted, '终态之后除了那一次查询，不该再提交任务');
    }

    #[Test]
    public function 手动停止后已排的那一拍作废(): void
    {
        [$store, $host, $timers] = $this->running(pending: 5);

        self::assertSame(1, $timers->count(), '夹具前提：此时正有一拍排着');
        $store->stop();

        self::assertFalse($store->isPolling());

        // 定时器还是会把回调交付（真实世界：`after()` 已被排上，撤不掉）
        $timers->run(0);

        self::assertCount(1, $host->ofClass(PollResultJob::class), '用户点了"停止"，却还发了一次查询 —— 而且不报错');
        self::assertSame(1, $timers->count(), '停止之后不许再排新的一拍');
    }

    /**
     * ★ 2026-11-04 迁移后这条判据的结论变了，值得说明。
     *
     * 旧形状里 `scheduleNextPoll()` 遇到 `$scheduler === null` 就 return —— 那一拍没人排，
     * 但 `$polling` 仍是 true，于是 `isPolling()` 报"正在轮询"。旧断言把它解释成
     * 「不轮询 ≠ 这一拍没人排」。
     *
     * 新形状里轮询是 `while` + `$co->sleep()`：`sleep()` 拿不到定时器 ⇒ 返回 `false`
     * ⇒ 循环退出。**"没人排这一拍"和"不在轮询"从此是同一件事** —— 旧断言钉的那个
     * 区分是形状的产物，不是业务需要。所以这条改成：没有定时器就只查一次，
     * 且界面必须回到可操作态（不能永远显示"正在查询结果…"）。
     */
    #[Test]
    public function 没有scheduler时只查一次而不假装在轮询(): void
    {
        [$store, $host] = $this->running(pending: 3, withScheduler: false);

        self::assertCount(1, $host->ofClass(PollResultJob::class), '建任务后那一次查询照常发');
        self::assertFalse($store->isPolling(), '没有定时器却报告"正在轮询"是假的 —— 那一拍根本没人排');
        self::assertTrue(
            $store->lastResult()?->isPending() ?? false,
            '查到"处理中"要落到 `lastResult()`，否则界面没有进展可画'
        );
        self::assertTrue($store->canSubmit(), '流水线结束后界面要回到可操作态，否则按钮永久灰');
    }

    // ══════════════════ 夹具 ══════════════════

    /**
     * 起一个走到"正在轮询"的 store。
     *
     * @param int $pending 前几次查询答"处理中"（第 `$pending + 1` 次答终态）
     *
     * @return array{0: WatermarkStore, 1: RecordingTaskHost, 2: FakeTimers}
     */
    private function running(int $pending, bool $withScheduler = true): array
    {
        $timers = new FakeEnvironment();
        $polls = 0;

        $host = new RecordingTaskHost([
            FetchSignJob::class => [
                'account' => 'acc',
                'policy' => 'pol',
                'signature' => 'sig',
                'directoryPath' => '10000000/2026/11/3/',
                'accessAddress' => 'https://oss.example.com',
                'expireTime' => (string) ((\time() + 600) * 1000),
            ],
            UploadBatchJob::class => ['keys' => ['k1']],
            AddTaskJob::class => ['taskId' => 'T-1'],
            // ★ 每次提交调一次：同一个任务"第 N 次才终结"只能这么造
            PollResultJob::class => static function () use (&$polls, $pending): array {
                $polls++;
                if ($polls <= $pending) {
                    return [
                        'taskId' => 'T-1',
                        'status' => 2,     // 处理中
                        'downloadUrls' => [],
                    ];
                }

                return [
                    'taskId' => 'T-1',
                    'status' => 3,         // 完成
                    'templateName' => '修改水印',
                    'imageSize' => '2K',
                    'processingTime' => '34s',
                    'createTime' => '2026-11-03 12:00:00',
                    'updateTime' => '2026-11-03 12:00:34',
                    'errorMsg' => '',
                    'downloadUrls' => ['https://oss.example.com/r/1.png'],
                ];
            },
        ]);

        $file = (string) \tempnam(\sys_get_temp_dir(), 'drift-wm-');
        $path = $file . '.jpg';
        \rename($file, $path);
        $this->tmp[] = $path;

        $env = $withScheduler ? $timers : null;

        $store = new WatermarkStore($host, 'http://gw.invalid', $env);
        self::assertSame(1, $store->addFiles([$path]), '夹具没被选上 ⇒ 后面全靠巧合');
        $store->setTime('2026-11-03 12:00:00');
        $store->setAddress('上海市浦东新区');

        // 取签名 + 上传（签名有效 ⇒ 直接上传）
        $store->upload();
        // 建任务 ⇒ 落地时 `polling = true` 并**立刻**查一次（不等一个间隔）
        $store->submitTask();

        return [$store, $host, $timers];
    }
}
