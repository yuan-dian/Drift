<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Async;

use Drift\Async\Future;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * `Future` —— 异步结果的唯一形状（N3）。
 *
 * ## 判据落在"组合"上，不在"能存一个值"上
 *
 * `then()` 能把值传下去这件事，随便一个数组都能做到。`Future` 存在的理由是
 * **组合**：`all` / `race` / 摊平 / 取消。所以下面每条判据都在问"组合还对不对"：
 *
 * | 判据 | 挡的退化 |
 * |---|---|
 * | `all` 结果**按入参顺序**（不是完成顺序） | 用 `array_merge` 之类的"完成序"实现 |
 * | `all` 里一条失败 ⇒ 其余被 `cancel()` | 失败后剩下的任务继续跑、没人认领 |
 * | `race` 的落选者被 `cancel()` | 落选任务白跑（后端不知道可以停） |
 * | `then()` 返回 Future ⇒ 摊平 | 嵌套一层 Future，调用方要 `then` 两次 |
 * | `cancel()` 之后迟到的 `resolve()` 被忽略 | 取消只是"标记"，结果还会回来改状态 |
 * | 已结算上 `then()` 立刻跑 | 只支持"先挂后结算"，`all` 的第二次组合就失效 |
 *
 * ★ **不测"失败没人接 ⇒ Fatal"**：那条走 `Errors::report()` 的 Fatal 序列
 *   （`abort()` 不是异常，`try/catch` 接不住），必须放子进程 —— 由
 *   `tools/smoke.php` 的 `taskerr` 用例守着。这里测它只会把 PHPUnit 打挂。
 * ★ 但**反方向必须测**：值（`Future::rejected()`、`all`/`race` 的聚合体）在构造
 *   那一瞬本来就没人接，**不许**判成事故 —— 见
 *   `testRejectedValueAndAllOverItDoNotTripTheUnhandledGuard`。
 */
final class FutureTest extends SmokeTestCase
{
    /**
     * 链式：值往下传、返回值被下游收到。
     *
     * ★ 顺序断言（`['a', 'b']`）比"值对不对"更重要：它证明回调**按挂的顺序**跑，
     *   而不是"最后挂的赢"。
     */
    public function testThenChainsValuesInOrder(): void
    {
        $seen = [];

        $future = Future::pending();
        $future->then(function (int $v) use (&$seen): int {
            $seen[] = 'a' . $v;

            return $v * 2;
        })->then(function (int $v) use (&$seen): void {
            $seen[] = 'b' . $v;
        });

        check('Future · 结算前回调不跑', $seen === [], \implode(',', $seen));

        $future->resolve(3);
        check('Future · 链式传值（a3 → b6）', $seen === ['a3', 'b6'], \implode(',', $seen));
        check('Future · 结算后 isDone() 为真', $future->isDone());
    }

    /**
     * 失败走 `catch()`，且**不调** `then()`（两条通道互斥）。
     *
     * ★ 与 `TaskHost` 时代的判据同源（"失败任务不调 onDone"）：两套通道同时触发
     *   会让业务代码把"失败"当"成功"处理 —— 那是静默错。
     */
    public function testCatchHandlesFailureAndThenDoesNotFire(): void
    {
        $ok = 0;
        $caught = null;

        $future = Future::pending();
        $future->then(function (mixed $v) use (&$ok): void {
            $ok++;
        })->catch(function (\Throwable $e) use (&$caught): void {
            $caught = $e;
        });

        $future->reject(new \RuntimeException('设备离线'));

        check('Future · 失败走 catch()（原文带出）', $caught instanceof \RuntimeException && $caught->getMessage() === '设备离线');
        check('Future · 失败**不**调 then()（两条通道互斥）', $ok === 0, (string) $ok);
    }

    /**
     * `then()` 返回 Future ⇒ **摊平**（下游等到内层结算才跑）。
     *
     * ★ 这是"取 A 再用 A 的结果取 B"不嵌套的唯一原因；不摊平就得写
     *   `->then(fn() => $b->then(fn() => ...))`，两层之后没人读得懂。
     */
    public function testThenFlattensReturnedFuture(): void
    {
        $inner = Future::pending();
        $seen = [];

        $outer = Future::pending();
        $outer->then(static function (int $v) use ($inner): Future {
            return $inner;
        })->then(function (string $s) use (&$seen): void {
            $seen[] = $s;
        });

        $outer->resolve(1);
        check('Future · 摊平：内层没结算 ⇒ 下游不跑', $seen === [], \implode(',', $seen));

        $inner->resolve('ok');
        check('Future · 摊平：内层结算 ⇒ 下游收到内层的值', $seen === ['ok'], \implode(',', $seen));
    }

    /**
     * `all`：**按入参顺序**给结果，与完成顺序无关。
     *
     * ★ 反例是"完成序"实现：先 `resolve` 的先占位 ⇒ 下标错位。业务侧拿到
     *   `$all[0]` 以为是对应用户 A，实际是用户 C —— 不报错，数据张冠李戴。
     */
    public function testAllKeepsArgumentOrderRegardlessOfCompletionOrder(): void
    {
        $a = Future::pending();
        $b = Future::pending();
        $c = Future::pending();

        $all = Future::all([$a, $b, $c]);
        $got = null;
        $all->then(function (array $values) use (&$got): void {
            $got = $values;
        });

        // 故意乱序结算
        $c->resolve('C');
        $b->resolve('B');
        check('Future::all · 还差一条 ⇒ 不结算', $got === null, \var_export($got, true));

        $a->resolve('A');
        check('Future::all · 结果按**入参**顺序（A,B,C）', $got === ['A', 'B', 'C'], \json_encode($got) ?: '');
    }

    /**
     * `all`：一条失败 ⇒ 整条失败，**其余被 cancel()**。
     *
     * ★ "其余被取消"是资源判据：不取消的话，`all` 已经失败了，剩下的请求还在跑、
     *   回来还没人接（`Future` 会把迟到结果丢掉），纯浪费。
     */
    public function testAllCancelsTheRestOnFailure(): void
    {
        $a = Future::pending();
        $b = Future::pending();

        $all = Future::all([$a, $b]);
        $error = null;
        $all->then(static function (array $v): void {
        })->catch(function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        $a->reject(new \RuntimeException('第一条就炸'));

        check('Future::all · 一条失败 ⇒ 整条失败', $error instanceof \RuntimeException && $error->getMessage() === '第一条就炸');
        check('Future::all · 失败后其余被 cancel()', $b->isCancelled());
    }

    /**
     * `race`：第一个结算的赢，其余被取消。
     *
     * ★ 两条都要断：只断"赢的是第一个"会奖励"不取消落选者"的实现。
     */
    public function testRaceTakesTheFirstAndCancelsLosers(): void
    {
        $slow = Future::pending();
        $fast = Future::pending();

        $race = Future::race([$slow, $fast]);
        $got = null;
        $race->then(function (string $v) use (&$got): void {
            $got = $v;
        });

        $fast->resolve('fast');
        check('Future::race · 先到的赢', $got === 'fast', \var_export($got, true));
        check('Future::race · 落选者被 cancel()', $slow->isCancelled());

        $slow->resolve('slow');
        check('Future::race · 落选者迟到的结果被忽略', $got === 'fast', \var_export($got, true));
    }

    /**
     * `cancel()`：标记 + 忽略迟到结果（**不**把 Future 变成失败）。
     *
     * ★ "取消不是错误"：逼调用方为一个主动取消写 `catch()`，那 `catch()` 里
     *   就必须区分"真失败"和"我自己取消的" —— 那是把状态机塞给业务。
     */
    public function testCancelIgnoresLateResult(): void
    {
        $ran = 0;
        $hook = 0;

        $future = Future::pending(static function () use (&$hook): void {
            $hook++;
        });
        $future->then(function (mixed $v) use (&$ran): void {
            $ran++;
        });

        $future->cancel();
        check('Future · cancel() 调了后端的取消钩子', $hook === 1, (string) $hook);
        check('Future · cancel() 之后 isCancelled() 为真、且**还没**结算', $future->isCancelled() && !$future->isDone());

        $future->resolve('迟到的结果');
        check('Future · cancel() 之后迟到的 resolve() 被忽略', $ran === 0 && !$future->isDone(), 'ran=' . $ran);
    }

    /**
     * 取消**聚合体**要传播给入参 —— 否则"取消一个组合请求"等于没取消。
     *
     * ★ 为什么这条重要：`AsyncCommand` 换参数时取消的正是它上一次拿到的那个 Future。
     *   若那个 Future 是 `all()`，只标记聚合体 ⇒ 每一路子任务都继续跑、结果回来没人
     *   接（占着 worker / 烧预算，且**不报错**）。这与 `cancel()` 的文档承诺
     *   （"能停就停"）相反 —— 契约说谎比没有这个能力更糟。
     * ★ 两条都要断：`all` 与 `race` 是两份实现（各自建聚合体），只测一个会让另一个
     *   静默退化。
     */
    public function testCancelPropagatesIntoComposites(): void
    {
        $a = Future::pending();
        $b = Future::pending();

        $all = Future::all([$a, $b]);
        $all->cancel();

        check(
            'Future::all · 取消聚合体 ⇒ 入参全部被 cancel()',
            $a->isCancelled() && $b->isCancelled(),
            'a=' . var_export($a->isCancelled(), true) . ' b=' . var_export($b->isCancelled(), true),
        );
        check('Future::all · 聚合体自己也标记为已取消', $all->isCancelled());

        $c = Future::pending();
        $d = Future::pending();

        $race = Future::race([$c, $d]);
        $race->cancel();

        check(
            'Future::race · 取消聚合体 ⇒ 全部候选被 cancel()',
            $c->isCancelled() && $d->isCancelled(),
            'c=' . var_export($c->isCancelled(), true) . ' d=' . var_export($d->isCancelled(), true),
        );
    }

    /**
     * 取消沿**派生链**上溯到根任务（`then` / `catch` / 摊平，三条路）。
     *
     * ★★ 这是"取消只做对了一半"的第二处破口：上一轮补的是 `all`/`race`（聚合体
     *   自己的钩子），而 `then()` 走的是 `chain()` —— **两条路互不相干**。只测一条
     *   会让另一条静默退化，而退化的表现不是报错：`AsyncCommand` 换参数时取消的
     *   是 `$task` 返回的那条，若它是 `submit()->then($decode)`，取消就停在派生节点上，
     *   排队项照发、worker 白跑一趟、结果回来没人要（界面**看不出任何异常**）。
     * ★ 判据落在**根上的取消钩子**（后端真停的地方），不落"派生那条 isCancelled()"：
     *   后者在断掉的实现里**也为真**（自己标一下而已），测不出任何东西。
     * ★ 三条都要断：`then` / `catch` 共用 `chain()`，摊平走 `forwardTo()` —— 摊平那条
     *   是**另一处**赋值（`$next` 的钩子要改指内层），漏了就是"取 A 再用 A 取 B"取消后
     *   B 照跑。
     */
    public function testCancelPropagatesUpThroughDerivedChain(): void
    {
        $stopped = 0;
        $root = Future::pending(function () use (&$stopped): void {
            $stopped = $stopped + 1;
        });
        $derived = $root->then(static fn(mixed $v): mixed => $v);

        $derived->cancel();
        check('Future · 取消 `then()` 的产物 ⇒ 根上的取消钩子被调到', $stopped === 1, 'stopped=' . $stopped);
        check(
            'Future · 根与派生两条都标记为已取消（记账与"真停"一致）',
            $root->isCancelled() && $derived->isCancelled(),
            'root=' . var_export($root->isCancelled(), true) . ' derived=' . var_export($derived->isCancelled(), true),
        );

        // 两级：`then()->catch()`（`catch` 与 `then` 共用 `chain()`，但闭包不同）
        $stopped2 = 0;
        $root2 = Future::pending(function () use (&$stopped2): void {
            $stopped2 = $stopped2 + 1;
        });
        $two = $root2->then(static fn(mixed $v): mixed => $v)->catch(static fn(\Throwable $e): mixed => null);
        $two->cancel();
        check('Future · 两级派生（then→catch）同样上溯到根', $stopped2 === 1, 'stopped=' . $stopped2);

        // 摊平：回调返回另一条 Future ⇒ 取消外层必须停住**内层**
        $inner = 0;
        $innerFuture = Future::pending(function () use (&$inner): void {
            $inner = $inner + 1;
        });
        $outerRoot = Future::pending();
        $outer = $outerRoot->then(static fn(mixed $v): Future => $innerFuture);
        $outerRoot->resolve(1);          // 回调跑 ⇒ 摊平到内层，此后取消的目标是内层
        $outer->cancel();
        check('Future · 摊平后取消外层 ⇒ 内层被取消（"取 A 再用 A 取 B"停得住）', $inner === 1, 'inner=' . $inner);

        // 正对照：不取消就照常交付（上溯不能顺手把正常路径掐掉）
        $seen = [];
        $root3 = Future::pending();
        $d3 = $root3->then(static function (mixed $v) use (&$seen): int {
            $seen[] = $v;

            return (int) $v * 2;
        });
        $root3->resolve(21);
        $d3->then(static function (mixed $v) use (&$seen): void {
            $seen[] = $v;
        });
        check('Future · 没取消时派生链照常交付（21 ⇒ 42）', $seen === [21, 42], \json_encode($seen));
    }

    /**
     * 在**已结算**的 Future 上挂回调 ⇒ 立刻跑。
     *
     * ★ `all` / `race` 的组合器会反复挂到"可能已经好了"的 Future 上；不支持这条，
     *   组合器就只能处理"全都还没好"的输入 —— 而现实中同步后端全是"已经好了"。
     */
    public function testThenOnSettledFutureRunsImmediately(): void
    {
        $seen = [];

        $done = Future::resolved(7);
        $done->then(function (int $v) use (&$seen): void {
            $seen[] = 'v' . $v;
        });
        check('Future · 已结算上 then() 立刻跑（同步后端全走这条）', $seen === ['v7'], \implode(',', $seen));

        $failed = Future::rejected(new \RuntimeException('已失败'));
        $caught = null;
        $failed->catch(function (\Throwable $e) use (&$caught): void {
            $caught = $e;
        });
        check('Future · 已失败上 catch() 立刻跑', $caught instanceof \RuntimeException && $caught->getMessage() === '已失败');
    }

    /**
     * 回调抛异常 ⇒ 下游 Future 失败（`catch()` 能接住），不是静默吞掉。
     *
     * ★ 改造前这条走 `PumpHost::pumpTasks()` 的 try/catch + Fatal 序列。现在异常
     *   有去处（下游 Future），但**没人接时仍然是 Fatal** —— 见类注释的边界说明。
     */
    public function testExceptionInCallbackBecomesDownstreamFailure(): void
    {
        $caught = null;

        $future = Future::pending();
        $future->then(static function (int $v): int {
            throw new \LogicException('回调自己炸了');
        })->catch(function (\Throwable $e) use (&$caught): void {
            $caught = $e;
        });

        $future->resolve(1);

        check(
            'Future · 回调抛异常 ⇒ 下游 catch() 接得住（不是静默吞）',
            $caught instanceof \LogicException && $caught->getMessage() === '回调自己炸了',
        );
    }

    /**
     * ★ 回归：**值不喊 Fatal** —— `rejected()` 与 `all()` 的聚合体都是"构造即结算"，
     *   那一刻调用方还没来得及挂 `catch()`。
     *
     * 挡的退化：把"失败没人接"这条判据用在构造路径上。实测过一次 ——
     * `Future::rejected($e)` 直接把 PHPUnit 进程 `exit(3)`，输出停在 9 个点、
     * 没有 OK/FAIL 摘要（`Errors::report()` → `abort()` 是 `exit()`，不是异常，
     * `try/catch` 接不住，所以只能靠"期望它跑通"来守）。
     * ★ 另一头不能松：任务链末端漏 `catch()` 仍然要 Fatal —— 那是
     *   `tools/smoke.php` 的 `taskerr` 用例。
     */
    public function testRejectedValueAndAllOverItDoNotTripTheUnhandledGuard(): void
    {
        $caught = null;
        Future::rejected(new \RuntimeException('值本身就是失败的'))
            ->catch(function (\Throwable $e) use (&$caught): void {
                $caught = $e;
            });
        check(
            'Future · rejected() 造的是**值**，构造时不判"没人接"',
            $caught instanceof \RuntimeException && $caught->getMessage() === '值本身就是失败的',
        );

        $allCaught = null;
        Future::all([Future::rejected(new \RuntimeException('组里有一条坏')), Future::resolved(1)])
            ->catch(function (\Throwable $e) use (&$allCaught): void {
                $allCaught = $e;
            });
        check(
            'Future::all · 入参里已有失败值 ⇒ 聚合体失败、catch() 接得住（不 Fatal）',
            $allCaught instanceof \RuntimeException && $allCaught->getMessage() === '组里有一条坏',
        );
    }

    /**
     * `allSettled`：**每条各自成败**，结果按入参键序；一条失败不取消兄弟。
     *
     * ★ 与 `testAllCancelsTheRestOnFailure()` 是同一条场景的**相反判据** ——
     *   两个一起看，才分得清"我测的是 all 还是 allSettled"。
     * ★ 还有一条只能在"失败时兄弟还在跑"这个窗口里判：**取消没有传播到兄弟**。
     */
    public function testAllSettledKeepsEveryArmIndependent(): void
    {
        $a = Future::pending();
        $b = Future::pending();
        $c = Future::pending();

        $settled = Future::allSettled(['第一' => $a, '第二' => $b, '第三' => $c]);
        $got = null;
        $settled->then(function (array $v) use (&$got): void {
            $got = $v;
        });

        // 故意乱序：先失败中间那条（此刻 a、c 还没结算）
        $b->reject(new \RuntimeException('第二块挂了'));

        check('Future::allSettled · 还有两条没回来 ⇒ 不结算', $got === null, \var_export($got, true));
        check(
            'Future::allSettled · 一条失败**不连坐**（兄弟没被 cancel）',
            !$a->isCancelled() && !$c->isCancelled(),
            'a=' . ($a->isCancelled() ? '1' : '0') . ' c=' . ($c->isCancelled() ? '1' : '0'),
        );

        $c->resolve('C');
        $a->resolve('A');

        check(
            'Future::allSettled · 结果按**入参键序**（第一/第二/第三，不是完成序）',
            $got !== null && \array_keys($got) === ['第一', '第二', '第三'],
            \json_encode($got, \JSON_UNESCAPED_UNICODE) ?: '',
        );
        check(
            'Future::allSettled · 成功的带值、失败的带消息（成败各自算数）',
            ($got['第一']['ok'] ?? null) === true && ($got['第一']['value'] ?? null) === 'A'
                && ($got['第二']['ok'] ?? null) === false && ($got['第二']['value'] ?? null) === null
                && ($got['第二']['error'] ?? null) === '第二块挂了'
                && ($got['第三']['ok'] ?? null) === true,
            \json_encode($got, \JSON_UNESCAPED_UNICODE) ?: '',
        );
    }

    /**
     * `allSettled`：**永不失败** —— 全部失败也走 `then()`，不碰 `catch()`。
     *
     * ★ 这条是本方法与 `all()` 的分水岭：`all()` 在全失败时走 `catch()`，页面
     *   只能落到错误态；`allSettled()` 让页面拿到三份"失败原因"，各画各的。
     * ★ `$settled->task` 必须为 false：它不会失败，若被标成"任务"，一旦哪天
     *   真的 reject 就会在没有 `catch()` 时喊 Fatal（一个永不失败的聚合体
     *   喊"失败没人接"是纯噪音）。
     */
    public function testAllSettledNeverRejects(): void
    {
        $a = Future::pending();
        $b = Future::pending();

        $settled = Future::allSettled([$a, $b]);

        $thenRan = 0;
        $catchRan = 0;
        $got = null;

        $settled->then(function (array $v) use (&$thenRan, &$got): void {
            $thenRan++;
            $got = $v;
        })->catch(function (\Throwable $e) use (&$catchRan): void {
            $catchRan++;
        });

        $a->reject(new \RuntimeException('左挂了'));
        $b->reject(new \RuntimeException('右也挂了'));

        check('Future::allSettled · 全失败也走 then()（永不失败）', $thenRan === 1 && $catchRan === 0,
            'then=' . $thenRan . ' catch=' . $catchRan);
        check(
            'Future::allSettled · 两条的失败原因都在结果里',
            \is_array($got) && ($got[0]['error'] ?? null) === '左挂了' && ($got[1]['error'] ?? null) === '右也挂了',
            \json_encode($got, \JSON_UNESCAPED_UNICODE) ?: '',
        );
    }

    /**
     * `allSettled`：取消传播给全部入参，此后**永不结算**（同 `all()`）。
     *
     * ★ 不传的话，"换了参数"的页面会把三条任务留在后台跑，回来还没人接 ——
     *   正是 `AsyncCommand` 每次换参数都要取消上一次的那个理由。
     */
    public function testAllSettledCancelPropagatesAndNeverSettles(): void
    {
        $a = Future::pending();
        $b = Future::pending();

        $settled = Future::allSettled([$a, $b]);

        $ran = 0;
        $settled->then(function (array $v) use (&$ran): void {
            $ran++;
        });

        $settled->cancel();

        check('Future::allSettled · 取消 ⇒ 全部入参被 cancel()', $a->isCancelled() && $b->isCancelled(),
            'a=' . ($a->isCancelled() ? '1' : '0') . ' b=' . ($b->isCancelled() ? '1' : '0'));

        $a->resolve('A');
        $b->resolve('B');

        check('Future::allSettled · 取消后连迟到结果都不交付（聚合体不结算）', $ran === 0, (string) $ran);
    }

    /**
     * `allSettled`：空数组 ⇒ 立刻成功（`[]`），与 `all([])` 一致。
     */
    public function testAllSettledOfEmptyIsImmediatelyDone(): void
    {
        $got = null;
        Future::allSettled([])->then(function (array $v) use (&$got): void {
            $got = $v;
        });

        check('Future::allSettled · 空数组 ⇒ 立刻成功且结果为空', $got === [], \var_export($got, true));
    }
}
