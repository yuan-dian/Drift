<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\AppScope;
use Drift\App\Application;
use Drift\App\Job;
use Drift\App\TaskHost;
use Drift\Async\Future;
use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Smoke\App\SumJob;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;

/**
 * `Application::submit()`（2026-10-30 补）—— 应用侧唯一的提交入口。
 *
 * ## 为什么这个薄壳值得三条测试
 *
 * 它是"应用提交任务"与"泵每帧 drain 结果"之间**唯一的接缝**。接缝错位不会崩、
 * 不会报错，只会让结果永远躺在队列里 —— 页面永远转圈。这种失败形态靠 smoke
 * 抓不到（窗口是活的、帧在跑），只能靠结构断言钉住。
 *
 * 三条各钉一件事：
 *
 * | 用例 | 钉住的不变式 |
 * |---|---|
 * | ① 打到挂载的端口 | `submit()` 真的把活发出去了，不是自己仿造一条 Future |
 * | ② 没端口时是 rejected | 失败**降级成 UI 的 error 态**，而不是打断渲染管线 |
 * | ③ 提交口 === drain 口 | 端口是**同一个实例**，且结果确实经泵回来（这条最贵） |
 */
final class ApplicationSubmitTest extends SmokeTestCase
{
    private static function app(?AppScope $scope = null): EchoApplication
    {
        return new EchoApplication([], $scope);
    }

    private static function attach(Application $app): void
    {
        $surface = new SurfaceProbe();
        $surface->resize(320, 240, 1.0);
        $app->attach($surface, BoxConstraints::tight(new Size(320.0, 240.0)));
    }

    /**
     * 用例 ①：`submit()` 必须**委托给挂载的端口**，返回值也必须是端口给的那条 Future。
     *
     * ★ 用记录型替身而不是 `SyncTaskHost`：后者会真的算完，无法区分
     *   "submit 委托了"与"submit 自己算了" —— 两者返回的值会一模一样。
     */
    public function testSubmitDelegatesToTheMountedHost(): void
    {
        Errors::reset();

        $host = new class implements TaskHost {
            /** @var list<Job> */
            public array $submitted = [];

            public function submit(Job $job): Future
            {
                $this->submitted[] = $job;

                return Future::resolved('from-host');
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
        };

        $app = self::app(AppScope::make()->setTaskHost($host));
        $job = new SumJob(3);

        $seen = 'unset';
        $app->submit($job)->then(static function (mixed $value) use (&$seen): void {
            $seen = $value;
        });

        check('submit · 任务交给了挂载的端口，且只交一次', \count($host->submitted) === 1);
        check('submit · 交出去的是同一个 Job 实例', ($host->submitted[0] ?? null) === $job);
        check('submit · 返回的是端口给出的那条 Future（值原样透传）', $seen === 'from-host');

        $this->flushChecks();
    }

    /**
     * 用例 ②：没有后端时返回 **rejected 的 Future**，不抛。
     *
     * ★ 这是本方法最容易被"改得更严格"的一处：抛异常看着更响亮，但提交点就在
     *   `build()` 链上（`AsyncTablePage::initState()` → `load()`）—— 抛出去等于
     *   把渲染管线打断，用户看到的是空白窗口，而不是"加载失败 + 重试"。
     */
    public function testSubmitWithoutHostIsRejectedNotThrown(): void
    {
        Errors::reset();

        $app = self::app(AppScope::make()->setTaskHost(null));

        $future = $app->submit(new SumJob(3));

        $message = '';
        $future->catch(static function (\Throwable $error) use (&$message): void {
            $message = $error->getMessage();
        });

        check('submit · 没有后端时立刻结算（不留永远 pending 的 Future）', $future->isDone());
        check('submit · 失败信息指出"没有后台任务端口"', \str_contains($message, '没有后台任务端口'));
        check('submit · 失败信息指出是哪个任务没地方跑', \str_contains($message, SumJob::class));

        $this->flushChecks();
    }

    /**
     * 用例 ③：**提交口与 drain 口是同一个实例**，且结果真的经泵回来。
     *
     * ★ 运行时把 `submit()` 写成 `$this->tasks()->submit()` 也"能跑" —— 只要没人
     *   中途换后端。这条断言把"能跑"升级成"结构上不可能错"：`attach()` 挂到四个泵上
     *   的那个端口，就是 `submit()` 用的那个。
     *
     * ★ **`submit()` 之后、`frame()` 之前值必须还没到** —— 这条反向断言同样重要：
     *   如果哪天 `SyncTaskHost::submit()` 被改成"就地算完"，这一条会立刻红，
     *   提醒"提交路径已经悄悄变成同步的了"（那正是 G2 那类"异步了还是卡"的根源）。
     */
    public function testSubmitTargetAndPumpDrainTargetAreOneInstance(): void
    {
        Errors::reset();

        $app = self::app();
        self::attach($app);

        check('submit · 默认有 SyncTaskHost（零装配可用）', $app->taskHost() !== null);
        check(
            'submit · 提交用的端口就是 attach() 挂到四个泵上的那个',
            $app->taskHost() !== null && $app->taskHost() === $app->tasks()
        );

        $seen = 'unset';
        $app->submit(new SumJob(4))->then(static function (mixed $value) use (&$seen): void {
            $seen = $value;
        });

        check('submit · 提交不等于算完（结果先入队，等帧内 drain）', $seen === 'unset');

        // 推进一帧：四个泵在 settle() 之前跑 ⇒ 本帧内 drain 到结果
        $app->frame(0.0, BoxConstraints::tight(new Size(320.0, 240.0)));

        check('submit · 结果经泵在帧内回来（1+2+3+4）', $seen === 10);

        $this->flushChecks();
    }
}
