<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Support;

use Drift\App\Job;
use Drift\App\TaskHost;
use Drift\Async\Future;

/**
 * 记录型 `TaskHost`：**只记账、不真跑**，按任务类名回一个已结算的 `Future`。
 *
 * ## 为什么需要它（2026-11-02 真事故）
 *
 * `WatermarkStore` 的每个动作都有两半：`$host === null` 走同步（测试与解释器），
 * `$host !== null` 走后台（**真机唯一走的那一半**）。而此前所有测试都构造
 * `host = null` ⇒ 后台那半行代码**从没被执行过**。于是
 * `new DownloadResultsJob($urls)` 漏 `use` 这种错，能一路活到用户点下载：
 *
 * ```text
 * 下载结果图失败：class 'App\Watermark\Store\DownloadResultsJob' is undefined
 * ```
 *
 * ⇒ 这个替身让"后台那一半"在 phpunit 里可达：不碰网络、不跨进程，只回答
 * "你到底提交了哪个任务、参数是什么"。
 *
 * ★ **不实现 `drain()` 的交付逻辑**（空实现）：`submit()` 直接返回已结算的
 *   Future，`then()` 在已结算的 Future 上立刻跑（与真后端"下一帧交付"不同形，
 *   但被测的是"提交了什么"，不是"何时交付"—— 后者有 `CompletionQueue` 的测试）。
 * ★ 未配置的任务类 ⇒ 返回 `Future::rejected()`：**不静默成功**，否则"漏配一个
 *   任务"会表现成"流程走通了但什么都没发生"。
 */
final class RecordingTaskHost implements TaskHost
{
    /** @var list<Job> 按提交顺序 */
    public array $submitted = [];

    /** @param array<class-string, mixed> $answers 任务类名 => 要结算的载荷 */
    public function __construct(private readonly array $answers = [])
    {
    }

    public function submit(Job $job): Future
    {
        $this->submitted[] = $job;

        $class = \get_class($job);
        if (!\array_key_exists($class, $this->answers)) {
            return Future::rejected(new \RuntimeException('RecordingTaskHost 没有为 ' . $class . ' 配答案'));
        }

        return Future::resolved($this->answers[$class]);
    }

    public function pending(): int
    {
        return 0;
    }

    public function needsPolling(): bool
    {
        return false;
    }

    public function drain(callable $onEach, ?int $budgetMs = null): void
    {
    }

    /** 按类名筛出提交过的任务（断言用；不筛就得自己数下标）。 */
    public function ofClass(string $class): array
    {
        $out = [];
        foreach ($this->submitted as $job) {
            if (\get_class($job) === $class) {
                $out[] = $job;
            }
        }

        return $out;
    }

    /** 提交过的任务类名（短名），按顺序 —— 断言"链路顺序"用。 */
    public function names(): array
    {
        $out = [];
        foreach ($this->submitted as $job) {
            $out[] = \get_class($job);
        }

        return $out;
    }
}
