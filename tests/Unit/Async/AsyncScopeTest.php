<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Async;

use Drift\Async\AsyncCancelled;
use Drift\Async\AsyncScope;
use Drift\Async\Future;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * `AsyncScope` —— 线性异步的十条判据（2026-11-04）。
 *
 * ## 这一组钉的是什么（按"漏了会怎样"排序）
 *
 * | # | 判据 | 漏了的表现 |
 * |---|---|---|
 * | 1 | **同步路径一口气跑完**，不额外占帧 | `null` TaskHost 下多出 N 帧，界面闪 N 次 loading |
 * | 2 | 异步路径逐条结算逐步推进 | 卡在第一个 `await` 不动 |
 * | 3 | 失败**原样抛出**（类型 + 消息都不变） | 业务 `catch (MyError)` 抓不到，退化成 Fatal |
 * | 4 | body 里 `try/catch` 能接管并继续 | 一步失败整条死，重试要重开作用域 |
 * | 5 | **取消**：在途 Future 被 cancel + `finally` 执行 + 协程终结 | 后端继续跑；协程栈泄漏（每次取消漏一份） |
 * | 6 | 取消后迟到的结算**不落地** | 用户点了停止，界面又变回"成功" |
 * | 7 | `sleep()` 用注入的延时函数 | 轮询写不出来，只能退回外部排定时器 |
 * | 8 | 循环 + `await` 能跑多轮（轮询形态） | 这条是本类存在的全部理由 |
 * | 9 | 协程外调 `await()` ⇒ 当场抛 | 永久挂住，"界面不动了"查一天 |
 * | 10 | 嵌套 `AsyncScope` | 子流水线跑不起来，或取消打不到内层 |
 * | 11 | `sleep()` 没有延时函数 ⇒ 返回 `false` | `while` 里退化成零延时重试（请求风暴） |
 * | 12 | 被 body 吞掉的取消在**下一个让出点**原样重来 | 点了"停止"还在查（`fetchResult` 的现场） |
 * | 13 | `await()` 透传进度，且结算/取消后不再来 | 进度条不动（挂晚 / 挂错那条，**不报错**） |
 *
 * ★ 5 是最值钱的一条：取消若只是"不再交付"而不终结协程，Fiber 连同它捕获的
 *   闭包一起挂在 `suspend()` 上永不释放 —— 这种泄漏界面上完全看不出来。
 * ★ 12 是迁移 `apps/watermark` 时**测出来的**：`pollLoop()` 里为了"查一次失败
 *   不停轮询"写了 `catch (\Throwable)`，它把取消哨兵一起接住 ⇒ 循环继续 ⇒ 又排
 *   一个定时器。`sleep(): bool`（11）是同一轮里补的 —— 顺序代码需要知道
 *   "到底睡没睡"。两条都是应用的形状反过来改了原语。
 * ★ 13 是同一类：`uploadFiles()` 里那段「进度必须挂在根 Future 上，且在
 *   `await()` 之前」的警告注释，就是"原语没接住这条纪律"的证据。收进 `await()`
 *   之后，调用方不必再记住它。
 */
final class AsyncScopeTest extends SmokeTestCase
{
    /**
     * 取一条已结算 Future 的值。
     *
     * ★ `Future` 没有公开的 `value()`（值是结算的产物，不是状态），
     *   只能经 `then()` 拿 —— 已结算时它**立刻在当前栈执行**。
     */
    private static function valueOf(Future $future): mixed
    {
        $got = null;
        $future->then(function (mixed $v) use (&$got): void {
            $got = $v;
        });

        return $got;
    }

    /** 1 + 2：同步一口气跑完；异步逐条推进 */
    public function testSynchronousRunsInOneGoAndAsyncStepsOneByOne(): void
    {
        $steps = [];
        $outer = AsyncScope::task(function (AsyncScope $co) use (&$steps): string {
            $steps[] = 'a';
            $a = $co->await(Future::resolved(1));
            $steps[] = 'b';
            $b = $co->await(Future::resolved(2));
            $steps[] = 'c';

            return (string) ($a + $b);
        });

        check(
            '同步路径 · 整段在 task() 内跑完，不额外占帧',
            $outer->isDone() && self::valueOf($outer) === '3' && $steps === ['a', 'b', 'c'],
            'done=' . ($outer->isDone() ? 'yes' : 'no') . ' steps=' . \implode(',', $steps),
        );

        $box = ['a' => null, 'b' => null];
        $reached = [];
        $async = AsyncScope::task(function (AsyncScope $co) use (&$box, &$reached): string {
            $fa = Future::pending();
            $box['a'] = $fa;
            $a = $co->await($fa);
            $reached[] = 'got-a';

            $fb = Future::pending();
            $box['b'] = $fb;
            $b = $co->await($fb);
            $reached[] = 'got-b';

            return $a . '/' . $b;
        });

        check(
            '异步路径 · 起手就停在第一个 await（没有提前跑完）',
            !$async->isDone() && $reached === [],
            'done=' . ($async->isDone() ? 'yes' : 'no') . ' reached=' . \implode(',', $reached),
        );

        $box['a']->resolve('A');
        check('异步路径 · 结算第一条后只推进到下一个 await', $reached === ['got-a'] && !$async->isDone());

        $box['b']->resolve('B');
        check(
            '异步路径 · 全部结算后整条完成，值正确',
            $async->isDone() && self::valueOf($async) === 'A/B',
            'value=' . \var_export(self::valueOf($async), true),
        );

        $this->flushChecks();
    }

    /** 3 + 4：失败原样抛出；body 能自己接管 */
    public function testFailurePropagatesVerbatimAndBodyCanRecover(): void
    {
        $box = ['f' => null];
        $outer = AsyncScope::task(function (AsyncScope $co) use (&$box): string {
            $f = Future::pending();
            $box['f'] = $f;

            return (string) $co->await($f);
        });

        $caught = null;
        $outer->catch(function (\Throwable $e) use (&$caught): void {
            $caught = $e;
        });

        $original = new \DomainException('第二步失败');
        $box['f']->reject($original);

        check(
            '失败 · 原样抛出（同一个异常实例，类型与消息都不变）',
            $caught === $original,
            'caught=' . ($caught === null ? 'null' : \get_class($caught) . ':' . $caught->getMessage()),
        );

        // ★ 局部接管：某步失败 ⇒ body 里 catch 掉，继续跑下一步
        $box2 = ['f' => null];
        $recovered = AsyncScope::task(function (AsyncScope $co) use (&$box2): string {
            $f = Future::pending();
            $box2['f'] = $f;

            try {
                $co->await($f);

                return 'clean';
            } catch (\DomainException $e) {
                return 'recovered:' . $e->getMessage();
            }
        });

        $box2['f']->reject(new \DomainException('第一步失败'));

        check(
            '失败 · body 里 try/catch 能接管（一步失败不必整条死）',
            $recovered->isDone() && self::valueOf($recovered) === 'recovered:第一步失败',
            'value=' . \var_export(self::valueOf($recovered), true),
        );

        $this->flushChecks();
    }

    /** 5 + 6：取消打到在途那条、finally 执行、迟到的结算不落地 */
    public function testCancelReachesWaitingStopsFiberAndIgnoresLateResult(): void
    {
        $stopped = 0;
        $cleaned = 0;
        $box = ['f' => null];

        $outer = AsyncScope::task(function (AsyncScope $co) use (&$box, &$cleaned, &$stopped): string {
            try {
                $f = Future::pending(function () use (&$stopped): void {
                    $stopped++;
                });
                $box['f'] = $f;
                $co->await($f);

                return 'unreachable';
            } finally {
                $cleaned++;
            }
        });

        check('取消前 · 在途且未完成', !$outer->isDone() && $box['f'] !== null);

        $outer->cancel();

        check(
            '取消 · 打到**在途的那条** Future（后端的取消钩子被调到）',
            $stopped === 1,
            'stopped=' . $stopped,
        );
        check(
            '取消 · body 的 finally 执行了（协程被异常唤醒并终结，栈不泄漏）',
            $cleaned === 1,
            'cleaned=' . $cleaned,
        );
        check(
            '取消 · 外层是"已取消"而不是"失败"（取消不是错误）',
            $outer->isCancelled() && !$outer->isDone(),
            'cancelled=' . ($outer->isCancelled() ? 'yes' : 'no'),
        );

        // ★ 迟到的结算不该落地
        $box['f']->resolve('late');
        check(
            '取消后 · 迟到的结算不落地（仍是不完成、无值）',
            !$outer->isDone(),
            'done=' . ($outer->isDone() ? 'yes' : 'no'),
        );

        $this->flushChecks();
    }

    /** 7 + 8：sleep 用注入的延时函数；循环 + await 能跑多轮 */
    public function testSleepAndLoop(): void
    {
        $pending = [];
        $result = AsyncScope::task(
            function (AsyncScope $co): int {
                $n = 0;
                while (true) {
                    $n = $n + 1;
                    $co->sleep(10);
                    if ($n >= 3) {
                        return $n;
                    }
                }
            },
            function (int $ms, \Closure $fn) use (&$pending): void {
                $pending[] = ['ms' => $ms, 'fn' => $fn];
            },
        );

        check(
            'sleep · 起手就把定时器排上（且只排一个，不是每轮排两个）',
            \count($pending) === 1 && !$result->isDone(),
            'pending=' . (string) \count($pending),
        );

        // 推三拍：每拍结算一个定时器 ⇒ 循环走一圈
        for ($i = 0; $i < 2; $i++) {
            $item = \array_shift($pending);
            ($item['fn'])();
        }

        check(
            'sleep · 循环走完两圈后仍在第三圈（尚未返回）',
            !$result->isDone() && \count($pending) === 1,
            'done=' . ($result->isDone() ? 'yes' : 'no') . ' pending=' . (string) \count($pending),
        );

        $item = \array_shift($pending);
        ($item['fn'])();

        check(
            '循环 + await · 三圈后返回 3（轮询形态可写）',
            $result->isDone() && self::valueOf($result) === 3,
            'value=' . \var_export(self::valueOf($result), true),
        );
        check('sleep · 注入的延时函数收到的是毫秒值', $item['ms'] === 10, 'ms=' . (string) $item['ms']);

        $this->flushChecks();
    }

    /** 9：协程外调 await() 当场抛，不变成"永远挂住" */
    public function testAwaitOutsideBodyThrows(): void
    {
        $box = ['co' => null, 'f' => null];
        AsyncScope::task(function (AsyncScope $co) use (&$box): void {
            $box['co'] = $co;
            $f = Future::pending();
            $box['f'] = $f;
            $co->await($f);
        });

        $thrown = '';
        try {
            $box['co']->await(Future::resolved(1));
        } catch (\LogicException $e) {
            $thrown = $e->getMessage();
        }

        check(
            '误用 · 协程外调 await() 抛 LogicException（不是静默挂住）',
            \str_contains($thrown, 'task()'),
            'message=' . $thrown,
        );

        $this->flushChecks();
    }

    /** 10：嵌套 AsyncScope，且取消能打到内层 */
    public function testNestedScopeAndCancelReachesInner(): void
    {
        $innerStopped = 0;
        $box = ['inner' => null];

        $outer = AsyncScope::task(function (AsyncScope $co) use (&$box, &$innerStopped): string {
            $inner = AsyncScope::task(function (AsyncScope $co2) use (&$innerStopped): string {
                $f = Future::pending(function () use (&$innerStopped): void {
                    $innerStopped++;
                });

                return (string) $co2->await($f);
            });
            $box['inner'] = $inner;

            return 'inner=' . (string) $co->await($inner);
        });

        check('嵌套 · 外层已起、内层在途', !$outer->isDone() && $box['inner'] !== null);

        $box['inner']->resolve('deep');

        check(
            '嵌套 · 内层结算后外层拿到值',
            $outer->isDone() && self::valueOf($outer) === 'inner=deep',
            'value=' . \var_export(self::valueOf($outer), true),
        );

        // 第二条：取消外层 ⇒ 沿 await 的 Future 打到内层
        $box2 = ['inner' => null];
        $outer2 = AsyncScope::task(function (AsyncScope $co) use (&$box2): string {
            $inner = AsyncScope::task(function (AsyncScope $co2) use (&$box2): string {
                $f = Future::pending(function () use (&$box2): void {
                    $box2['stopped'] = true;
                });

                return (string) $co2->await($f);
            });
            $box2['inner'] = $inner;

            return (string) $co->await($inner);
        });

        $outer2->cancel();

        check(
            '嵌套 · 取消外层会沿 await 的 Future 打到内层（内层的取消钩子被调到）',
            ($box2['stopped'] ?? false) === true,
            'stopped=' . (($box2['stopped'] ?? false) ? 'yes' : 'no'),
        );

        $this->flushChecks();
    }

    /** 11：没有延时函数时 sleep 返回 false，不抛不崩 */
    public function testSleepWithoutDelayReturnsFalse(): void
    {
        $got = 'not-called';
        $outer = AsyncScope::task(function (AsyncScope $co) use (&$got): string {
            $got = $co->sleep(5);

            return 'after';
        });

        check(
            'sleep · 没有延时函数时返回 false（不抛、不静默挂住）',
            $got === false && $outer->isDone(),
            'got=' . \var_export($got, true) . ' done=' . ($outer->isDone() ? 'yes' : 'no'),
        );
        check(
            'sleep · 返回 false 之后流水线照常往下走（由调用方决定怎么退）',
            self::valueOf($outer) === 'after',
            'value=' . \var_export(self::valueOf($outer), true),
        );

        $this->flushChecks();
    }

    /**
     * 12：body 用 `catch (\Throwable)` 吞掉取消 ⇒ 下一个让出点原样重来，协程照样终结。
     *
     * ★ 这是 `apps/watermark` 迁移时抓到的真事故形态：轮询循环里为了"查一次失败
     *   不停轮询"必须 `catch (\Throwable)`，而用户点「停止」投的 `AsyncCancelled`
     *   会被它一起接住 —— 循环继续，又排一个定时器。
     * ★ `$terminated` 是本条最值钱的断言：被吞掉的取消若就此挂在一个新的 `await`
     *   上，协程**永远不终结**（`finally` 不跑，Fiber 栈连同捕获的闭包一起泄漏）。
     *   这个泄漏在界面上完全看不出来 —— 所以它必须有一条判据。
     */
    public function testSwallowedCancelComesBackAtNextAwait(): void
    {
        $swallowed = 0;
        $afterAwait = 0;
        $terminated = 0;
        $box = ['f' => null];

        $outer = AsyncScope::task(function (AsyncScope $co) use (&$swallowed, &$afterAwait, &$terminated, &$box): void {
            try {
                $f = Future::pending();
                $box['f'] = $f;

                try {
                    $co->await($f);
                } catch (\Throwable $e) {
                    $swallowed++;   // ★ 故意吞（含取消）
                }

                $co->await(Future::pending());
                $afterAwait++;      // 到不了这里
            } finally {
                $terminated++;
            }
        });

        $outer->cancel();

        check(
            '被吞的取消 · 下一个 await 立刻再抛（后续语句一句没跑）',
            $swallowed === 1 && $afterAwait === 0,
            'swallowed=' . (string) $swallowed . ' after=' . (string) $afterAwait,
        );
        check(
            '被吞的取消 · 协程真的终结了（finally 执行，Fiber 栈不泄漏）',
            $terminated === 1,
            'terminated=' . (string) $terminated,
        );
        check(
            '被吞的取消 · 外层仍是"已取消"而不是"已完成/失败"',
            $outer->isCancelled() && !$outer->isDone(),
            'cancelled=' . ($outer->isCancelled() ? 'yes' : 'no') . ' done=' . ($outer->isDone() ? 'yes' : 'no'),
        );

        $this->flushChecks();
    }

    /**
     * 13：`await()` 的进度回调 —— 只在**让出期间**透传，结算/取消之后都不再来。
     *
     * ★ 为什么这条判据必须存在：`Future::onProgress()` 不沿 `then()` 派生链传递，
     *   且结算后再挂最多补发一次终值 —— 于是"挂晚了 / 挂错那条"这两种错法**都不报错**，
     *   只表现为进度条不动。把回调收进 `await()` 的入参就是为了让正确顺序由原语保证，
     *   而不是由每个调用方记住。
     * ★ 还有一条容易被当成 bug 的既有行为：挂的时候若**已经有**进度，
     *   `Future::onProgress()` 会**立刻补发一次**（第 ③ 段）。它不是重复投递，
     *   是"迟到的订阅者也该知道现在到哪了"。
     */
    public function testAwaitForwardsProgressOnlyWhilePending(): void
    {
        $box = ['f' => null];
        $seen = [];

        $outer = AsyncScope::task(function (AsyncScope $co) use (&$box, &$seen): void {
            $f = Future::pending();
            $box['f'] = $f;

            // ① 先报一次进度，再 await —— 挂的瞬间应当补发
            $f->reportProgress(0.1);
            $co->await($f, function (float $p) use (&$seen): void {
                $seen[] = $p;
            });
        });

        $f = $box['f'];
        check(
            '进度 · 挂的时候已有进度 ⇒ 立刻补发一次',
            $seen === [0.1],
            'seen=' . \json_encode($seen),
        );

        $f->reportProgress(0.5);
        $f->reportProgress(0.75);
        check(
            '进度 · 让出期间每一次 reportProgress 都到达回调',
            $seen === [0.1, 0.5, 0.75],
            'seen=' . \json_encode($seen),
        );

        $f->resolve('done');
        $f->reportProgress(1.0);
        check(
            '进度 · 结算之后不再有进度（Future 已终结）',
            $seen === [0.1, 0.5, 0.75] && $outer->isDone(),
            'seen=' . \json_encode($seen) . ' done=' . ($outer->isDone() ? 'yes' : 'no'),
        );

        // ② 取消之后同样不该再有进度
        $box2 = ['f' => null];
        $seen2 = [];

        $cancelled = AsyncScope::task(function (AsyncScope $co) use (&$box2, &$seen2): void {
            $f = Future::pending();
            $box2['f'] = $f;
            $co->await($f, function (float $p) use (&$seen2): void {
                $seen2[] = $p;
            });
        });

        $box2['f']->reportProgress(0.3);
        $cancelled->cancel();
        $box2['f']->reportProgress(0.9);

        check(
            '进度 · 取消之后不再有进度',
            $seen2 === [0.3] && $cancelled->isCancelled(),
            'seen=' . \json_encode($seen2) . ' cancelled=' . ($cancelled->isCancelled() ? 'yes' : 'no'),
        );

        $this->flushChecks();
    }
}
