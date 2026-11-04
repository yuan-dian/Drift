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
 *
 * ★ 5 是最值钱的一条：取消若只是"不再交付"而不终结协程，Fiber 连同它捕获的
 *   闭包一起挂在 `suspend()` 上永不释放 —— 这种泄漏界面上完全看不出来。
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
}
