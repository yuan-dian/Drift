<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\State;

use Drift\Async\AsyncValue;
use Drift\Async\Future;
use Drift\Dev\Smoke\App\AsyncCommandApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;

/**
 * **多步依赖**的范文 —— `$task` 内部组合（2026-10-31）。
 *
 * ## 这个文件存在的理由：它是"写法"的唯一正确答案，而且要能跑
 *
 * `ASYNC-ARCH-2026-10-31.md` §3.4 / §4.3 记着一条缺口：多步依赖（"先取用户，
 * 再用用户的 id 取订单"）**能写但仓库里没有一个例子**，于是"这样写对不对"只能靠猜。
 *
 * ## 三种写法（2026-11-04 更新：多了一条 `AsyncScope`）
 *
 * 本文件钉的是**第一种**。★ 原文此处写着"本仓没有 `await`（AOT 禁 `Fiber`/`Generator`）"
 * —— **那个前提已于 2026-11-04 作废**：`Fiber` 在 AOT `-m bin` 产物里十一条边界实测全绿
 * （`probes/fiber_scope`），`Drift\Async\AsyncScope` 因此落地。
 *
 * | 写法 | 形状 | 什么时候用 |
 * |---|---|---|
 * | **`$task` 内部组合**（本文） | `submit(A)->then(fn($a) => submit(B($a)))` | 2–3 步、**无循环**、中间值不用上界面 |
 * | 两次 `run()` | 第一个命令 `data` 后手动 `$second->run($id)` | 两步**各自可独立展示** |
 * | **`AsyncScope`**（`AsyncScopeCommandTest`） | `$co->await($host->submit(A))` 顺序写 | **有循环（轮询）/ 有重试 / 每步都要更新界面** |
 *
 * ★ 第一种不会因为第三条的出现而过时：它**不需要协程**，两条 Future 就够。
 *   有循环或有中间态时才值得开作用域 —— 别为了"统一写法"给两步的事套一层协程。
 *
 * ```php
 * // ★ 范文本体：整段只有一句，页面与 `AsyncCommand` 都不知道有几"步"
 * $this->detail = $this->asyncCommand(
 *     fn (int $id): Future => $host->submit(new ProfileJob($id))
 *         ->then(fn (array $profile): Future => $host->submit(new OrdersJob((int) $profile['id']))),
 * );
 * $this->detail->run($id);
 * ```
 *
 * ★ 关键性质（也是本文件要证明的三条）：
 * ① 界面上只看到**一次** `loading → data`，中间那步的值**不会**漏出去；
 * ② 第二步失败 ⇒ 整条 `error`，**不会**显示"半个页面"（第一步的数据）；
 * ③ 取消**穿过两层** —— 摊平之后取消的是**正在跑的那一步**（`Future::forwardTo()`
 *    改指内层，2026-10-31）。
 * ④ 顺带：整件事仍然只是**一条** `Future` 交给**一个** `AsyncCommand` ⇒
 *    去重 / 重试 / 卸载兜底全部照旧，不需要第二套状态。
 *
 * ★ 为什么用假 `Future::pending()` 而不是真 `ProcessTaskHost`：本文件判的是
 *   **组合语义**（谁先谁后 / 中间值会不会漏 / 取消打到哪一层），不是进程调度。
 *   真进程那部分由 `JobTimeoutTest` / `PrewarmTest` / `AsyncTablePageTest` 守着。
 */
final class MultiStepCommandTest extends SmokeTestCase
{
    private const W = 400.0;

    private const H = 300.0;

    /**
     * @param \Closure(mixed): Future $task
     *
     * @return array{0: AsyncCommandApplication, 1: BoxConstraints}
     */
    private static function boot(\Closure $task): array
    {
        $surface = new SurfaceProbe();
        $surface->resize((int) self::W, (int) self::H, 1.0);
        $constraints = BoxConstraints::tight(new Size(self::W, self::H));

        $app = new AsyncCommandApplication($task);
        $app->attach($surface, $constraints);
        $app->frame(16.0, $constraints);

        return [$app, $constraints];
    }

    private static function last(AsyncCommandApplication $app): ?AsyncValue
    {
        $snapshots = $app->snapshots;

        return $snapshots === [] ? null : $snapshots[\count($snapshots) - 1];
    }

    /** @param list<AsyncValue> $snapshots */
    private static function states(array $snapshots): string
    {
        $out = [];
        foreach ($snapshots as $v) {
            $out[] = $v->isIdle() ? 'idle' : ($v->isLoading() ? 'loading' : ($v->hasData() ? 'data' : 'error'));
        }

        return \implode(',', $out);
    }

    /** snapshots 里出现过几次 `data`（用来证明"中间值没漏出去"） */
    private static function dataCount(AsyncCommandApplication $app): int
    {
        $n = 0;
        foreach ($app->snapshots as $v) {
            if ($v->hasData()) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * ①②：两步串起来只产生**一次** `data`；第二步失败 ⇒ 整条 `error`，不见半个页面。
     *
     * ★ 第一步用 `Future::resolved()` 起步，是为了让测试在**第二步创建之后**才拿到
     *   它的句柄（`$box['second']`）—— 真任务里那一步是 `$host->submit()` 的返回值，
     *   测试没有别的地方能取到。这不改变被验证的语义：链的形状一模一样。
     */
    public function testSecondStepFailureBecomesErrorNotEmptyHalfPage(): void
    {
        Errors::reset();

        $box = ['second' => null];

        [$app, $constraints] = self::boot(
            static function (mixed $arg) use (&$box): Future {
                return Future::resolved(['id' => (int) $arg])
                    ->then(static function (mixed $user) use (&$box): Future {
                        $second = Future::pending();
                        $box['second'] = $second;

                        return $second;
                    });
            },
        );

        $command = $app->pageState()->command();
        $command->run(7);
        $app->frame(32.0, $constraints);

        check(
            '多步 · 第一步完成、第二步在途 ⇒ 界面仍是 loading（**中间值不漏**，没有半个 data）',
            self::last($app)->isLoading() && !self::last($app)->hasValue() && self::dataCount($app) === 0,
            'states=' . self::states($app->snapshots),
        );
        check('多步 · 第二步真的被提交了（`then` 的返回值被摊平）', $box['second'] !== null);

        $box['second']->reject(new \RuntimeException('订单接口失败'));
        $app->frame(48.0, $constraints);

        $last = self::last($app);
        check(
            '多步 · 第二步失败 ⇒ 整条 error（不是"第一步的 data + 一条没人看的错"）',
            $last->hasError() && !$last->hasValue() && $last->message() === '订单接口失败',
            'state=' . $last->stateName() . ' message=' . $last->message(),
        );
        check('多步 · 失败不产生诊断（命令的 catch 接住了整条链）', Errors::recentDiagnostics() === []);

        $this->flushChecks();
    }

    /** ② 的另一半：两步**都成功**才变成一次 `data`，且全程只 data 了一次 */
    public function testBothStepsSucceedInOneData(): void
    {
        $box = ['second' => null];
        $secondArgs = [];

        [$app, $constraints] = self::boot(
            static function (mixed $arg) use (&$box, &$secondArgs): Future {
                return Future::resolved(['id' => (int) $arg, 'name' => '阿朱'])
                    ->then(static function (mixed $user) use (&$box, &$secondArgs): Future {
                        $secondArgs[] = $user;
                        $second = Future::pending();
                        $box['second'] = $second;

                        return $second;
                    });
            },
        );

        $command = $app->pageState()->command();
        $command->run(7);
        $app->frame(32.0, $constraints);

        $box['second']->resolve(['orders' => 3]);
        $app->frame(48.0, $constraints);

        $last = self::last($app);
        check(
            '多步 · 两步都成功 ⇒ 一次 data，值是**第二步**的结果',
            $last->hasData() && $last->value() === ['orders' => 3] && !$command->isRunning(),
            'state=' . $last->stateName() . ' value=' . \json_encode($last->value()),
        );
        check(
            '多步 · 全程只出现过一次 data（中间值没漏给界面）',
            self::dataCount($app) === 1,
            'states=' . self::states($app->snapshots),
        );
        check(
            '多步 · 第二步拿到的是第一步的结果（依赖真的串起来了）',
            $secondArgs === [['id' => 7, 'name' => '阿朱']],
            'args=' . \json_encode($secondArgs, \JSON_UNESCAPED_UNICODE),
        );

        $this->flushChecks();
    }

    /**
     * ③：**取消打到正在跑的那一步**。
     *
     * ★ 这是 `Future::forwardTo()` 改指内层的那条契约（2026-10-31）：摊平之后
     *   `$next` 的取消钩子必须改指**内层**，否则"取 A 再取 B"取消后 B 照跑。
     * ★ 判据是"内层的钩子被调到"而不是"`isCancelled()` 为真"：后者在坏掉的实现里
     *   **也为真**（`cancel()` 总会把自己标上）。
     */
    public function testCancelReachesTheStepThatIsRunning(): void
    {
        $box = ['second' => null];
        $stoppedSecond = 0;

        [$app, $constraints] = self::boot(
            static function (mixed $arg) use (&$box, &$stoppedSecond): Future {
                return Future::resolved(['id' => (int) $arg])
                    ->then(static function (mixed $user) use (&$box, &$stoppedSecond): Future {
                        $second = Future::pending(static function () use (&$stoppedSecond): void {
                            $stoppedSecond++;
                        });
                        $box['second'] = $second;

                        return $second;
                    });
            },
        );

        $command = $app->pageState()->command();
        $command->run(7);
        $app->frame(32.0, $constraints);

        check('多步 · 取消前第二步在途', $command->isRunning() && $box['second'] !== null);

        $command->cancel();
        $app->frame(48.0, $constraints);

        check(
            '多步 · 取消打到**正在跑的那一步**（内层的取消钩子被调到，不是只把外层标一下）',
            $stoppedSecond === 1,
            'stopped=' . $stoppedSecond,
        );
        check(
            '多步 · 取消后没有在途任务、也没有值 ⇒ 回到 idle（不是永远转圈）',
            !$command->isRunning() && self::last($app)->isIdle(),
            'states=' . self::states($app->snapshots),
        );

        // ★ 取消之后迟到的结算不该再落地（`Future::resolve()` 的第一句查取消位）
        $box['second']->resolve(['orders' => 3]);
        $app->frame(64.0, $constraints);

        check(
            '多步 · 取消之后迟到的结果不落地（仍是 idle）',
            self::last($app)->isIdle(),
            'states=' . self::states($app->snapshots),
        );

        $this->flushChecks();
    }
}
