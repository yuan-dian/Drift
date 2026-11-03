<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\Job;
use Drift\App\JobRunner;
use Drift\Dev\Tests\Support\RecordingTaskHost;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `JobRunner::exec()` —— **同步与后台的唯一选路点**（2026-11-03，P0-1）。
 *
 * ## 它替掉的是"三份实现"
 *
 * 在此之前，应用侧每个动作都要写一遍：
 *
 * ```php
 * $host = $this->host;
 * if ($host === null) {
 *     try { return Future::resolved((new ApiClient($base))->sign()->toArray()); }
 *     catch (\Throwable $e) { return Future::rejected($e); }
 * }
 * return $host->submit(new FetchSignJob($base));
 * ```
 *
 * 那段同步分支**复刻了 `Job::run()` 的每一个字节** ⇒ 同一件事有 `ApiClient` /
 * `Job::run()` / 同步分支三份实现，改一处要记得改三处。`exec()` 让同步分支
 * 直接**跑同一个 `Job`**，重复随之消失。
 *
 * ## 三条判据（少一条就会静默错）
 *
 * | 用例 | 钉住的不变式 |
 * |---|---|
 * | ① 有后端 ⇒ **提交同一个实例** | 不是"复制一份参数再交"——`Job` 构造时就带齐依赖，复制会漂 |
 * | ② 无后端 ⇒ 就地跑 `run()` | 同步路径跑的是**任务本体**，不是第二份实现 |
 * | ③ 无后端且 `run()` 抛 ⇒ `rejected` | 异常不能射进事件循环；两条路的失败出口必须同一个 |
 *
 * ★ ③ 是真机事故的形状：`SyncTaskHost::submit()` 把 `run()` 的异常收进 `Completion`
 *   的 error 通道，而裸调 `$job->run()` 会**直接抛出**。若 `exec()` 不接，
 *   "后台路径失败"表现成"Future 出错"，"同步路径失败"表现成"整个回调栈炸开" ——
 *   同一个业务错误两种症状，应用层没法统一写 `catch`。
 */
final class JobRunnerTest extends TestCase
{
    /** 一个只回固定值、可选抛异常的 Job（`Job` 是接口 ⇒ 匿名类即可，无需注册表） */
    private static function job(mixed $value, ?\Throwable $throw = null): Job
    {
        return new class ($value, $throw) implements Job {
            public function __construct(
                private readonly mixed $value,
                private readonly ?\Throwable $throw,
            ) {
            }

            public function run(): mixed
            {
                if ($this->throw !== null) {
                    throw $this->throw;
                }

                return $this->value;
            }
        };
    }

    #[Test]
    public function 有后端时提交的是同一个任务实例(): void
    {
        // 不配答案 ⇒ `RecordingTaskHost` 回 rejected —— 本用例判的是"提交了哪个实例"，
        // 不是"结算成什么值"（后者是 TaskHost 的职责）。
        $host = new RecordingTaskHost([]);
        $job = self::job('x');

        $future = JobRunner::exec($host, $job);

        self::assertCount(1, $host->submitted, '没提交任何任务 ⇒ 选路走错了方向');
        self::assertSame($job, $host->submitted[0], '提交的不是同一个实例 ⇒ 参数会被复制/漂移');
        self::assertTrue($future->isDone(), 'RecordingTaskHost 立刻结算 ⇒ 这里应当是已结算的');
    }

    #[Test]
    public function 无后端时就地跑任务本体(): void
    {
        $got = null;

        JobRunner::exec(null, self::job('结果'))->then(static function (mixed $v) use (&$got): void {
            $got = $v;
        });

        self::assertSame('结果', $got, '同步路径没跑 `run()`（或结果没结算到 Future）');
    }

    #[Test]
    public function 无后端且任务抛异常时落成被拒的Future(): void
    {
        $error = new \RuntimeException('网络断了');
        $caught = null;

        // ★ 这一行**不许抛**：抛了就说明异常会射进事件循环。
        JobRunner::exec(null, self::job('x', $error))
            ->catch(static function (\Throwable $e) use (&$caught): void {
                $caught = $e;
            });

        self::assertSame($error, $caught, '同步路径的失败没有落成 rejected ⇒ 应用层写不出统一的 catch');
    }

    #[Test]
    public function 有后端时失败同样落成被拒的Future(): void
    {
        $caught = null;

        // RecordingTaskHost 没配答案 ⇒ 回 rejected（不静默成功）
        JobRunner::exec(new RecordingTaskHost([]), self::job('x'))
            ->catch(static function (\Throwable $e) use (&$caught): void {
                $caught = $e;
            });

        self::assertInstanceOf(\Throwable::class, $caught, '两条路的失败出口不一致 ⇒ 上层必须分支');
    }
}
