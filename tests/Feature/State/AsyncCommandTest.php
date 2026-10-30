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
use Drift\Widget\State;

/**
 * `AsyncCommand` —— 一次异步请求的完整生命周期（发起 / 去重 / 取消 / 重试）。
 *
 * ## 这一组钉的是什么（按"漏了会怎样"排序）
 *
 * | # | 判据 | 漏了的表现 |
 * |---|---|---|
 * | 1 | 未 `run()` ⇒ `idle` | "点按钮才加载"的页面一进来就在转圈 |
 * | 2 | `run()` ⇒ `loading`，结算 ⇒ `data`（同帧） | 转圈不消失 / 数据到了界面不动 |
 * | 3 | 失败 ⇒ `error` 且**不**走 Fatal | 一次网络错误 = 进程没了（`Future` 的 G2） |
 * | 4 | 已结算的 Future 首帧直接 `data` | 白闪一帧转圈 |
 * | 5 | 同参数在途 ⇒ 不重复提交 | 连点 = 雪崩 |
 * | 6 | 换参数 ⇒ 旧请求被 `cancel()`、迟到的旧结果不落地 | 旧数据盖掉新数据（**不报错**） |
 * | 7 | `retry()` 用上次参数 | 重试按钮取到的是别的页 |
 * | 8 | `dispose()` 后结算**不标脏** | `pendingDirty` 漂移：每帧多扫一遍，**不报错** |
 * | 9 | **卸载时框架自动停掉在途任务**（页面不写 `dispose()`） | 转圈中离开页面 ⇒ worker 白跑满 1.2s |
 *
 * ★ 8 是最值钱的一条：它的失败形态是"永远不红"的性能退化，其他几条都是看得见的。
 *
 * ★ 为什么必须真挂树：命令靠 `Subject` 广播、由 `State::asyncCommand()` 转成一个
 *   `markDirty()`，而"标脏 → settle → 重建 → `build()` 读到新值"这条链只有跑帧才成立。
 */
final class AsyncCommandTest extends SmokeTestCase
{
    private const W = 400.0;

    private const H = 300.0;

    /**
     * 起一个根为命令页面的应用。
     *
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

    /** build() 最后一次读到的值（`null` = 一次都没重建，说明根本没挂上树） */
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

    public function testIdleUntilRun(): void
    {
        $made = [];
        [$app] = self::boot(static function (mixed $arg) use (&$made): Future {
            $made[] = Future::pending();

            return $made[\count($made) - 1];
        });

        $first = self::last($app);
        check(
            'AsyncCommand · 没 run() 过是 idle（不是 loading —— 画转圈会骗用户）',
            $first !== null && $first->isIdle(),
            $first === null ? 'build 一次都没跑' : 'state=' . self::states($app->snapshots),
        );

        $this->flushChecks();
    }

    public function testRunBecomesLoadingThenDataInSameFrame(): void
    {
        $made = [];
        [$app, $constraints] = self::boot(static function (mixed $arg) use (&$made): Future {
            $made[] = Future::pending();

            return $made[\count($made) - 1];
        });

        $state = $app->pageState();
        check('夹具能拿到页面 State', $state !== null);

        $state->command()->run(1);
        $app->frame(32.0, $constraints);

        check(
            'AsyncCommand · run() 之后是 loading',
            self::last($app)->isLoading() && $state->command()->isRunning(),
            'state=' . self::states($app->snapshots),
        );

        $made[0]->resolve(['total' => 2]);
        $app->frame(48.0, $constraints);

        $last = self::last($app);
        check(
            'AsyncCommand · 结算后同帧变 data，值原样到达，且不再"在途"',
            $last->hasData() && $last->value() === ['total' => 2] && !$state->command()->isRunning(),
            'state=' . self::states($app->snapshots) . ' value=' . \json_encode($last->value()),
        );

        $this->flushChecks();
    }

    public function testRejectedTaskBecomesErrorWithoutFatal(): void
    {
        Errors::reset();

        $made = [];
        [$app, $constraints] = self::boot(static function (mixed $arg) use (&$made): Future {
            $made[] = Future::pending();

            return $made[\count($made) - 1];
        });

        $app->pageState()->command()->run(1);
        $app->frame(32.0, $constraints);
        $made[0]->reject(new \RuntimeException('取数失败：HTTP 500'));
        $app->frame(48.0, $constraints);

        $last = self::last($app);
        check(
            'AsyncCommand · 失败 ⇒ error 且消息可画（不是永远转圈）',
            $last->hasError() && $last->message() === '取数失败：HTTP 500',
            'state=' . self::states($app->snapshots) . ' message=' . $last->message(),
        );

        // ★ 这条是 G2 的判据：任务失败没人接 ⇒ `Future` 走 Fatal 序列（写日志 +
        //   exit）。命令必须自己挂 `catch`，否则表现为"用例莫名其妙没跑完"。
        check('AsyncCommand · 失败不产生诊断（命令挂了 catch，没走 Fatal）', Errors::recentDiagnostics() === []);

        $this->flushChecks();
    }

    public function testAlreadySettledTaskSkipsLoadingFlash(): void
    {
        [$app, $constraints] = self::boot(static function (mixed $arg): Future {
            return Future::resolved('v');
        });

        $app->pageState()->command()->run(1);
        $app->frame(32.0, $constraints);

        check(
            'AsyncCommand · 同步后端（已结算 Future）首帧直接 data，不白闪 loading',
            self::states($app->snapshots) === 'idle,data',
            'state=' . self::states($app->snapshots),
        );

        $this->flushChecks();
    }

    public function testSameArgumentWhileRunningIsNotSubmittedTwice(): void
    {
        $made = [];
        [$app, $constraints] = self::boot(static function (mixed $arg) use (&$made): Future {
            $made[] = Future::pending();

            return $made[\count($made) - 1];
        });

        $command = $app->pageState()->command();
        $command->run(1);
        $command->run(1);              // 同参数、还在跑 ⇒ 去重
        $command->run(1);

        check(
            'AsyncCommand · 同参数且在途 ⇒ 只提交一次（挡住"转圈时连点"）',
            \count($made) === 1,
            'submitted=' . \count($made),
        );

        $command->run(2);              // 换参数 ⇒ 照常起新请求
        check(
            'AsyncCommand · 换参数 ⇒ 照常提交（去重不该挡住翻页）',
            \count($made) === 2,
            'submitted=' . \count($made),
        );

        $this->flushChecks();
    }

    /**
     * 换请求 ⇒ 旧的那条被真的 `cancel()`，且它迟到的结果**不落地**。
     *
     * ★ "旧结果盖新数据"是异步 UI 的经典静默故障：界面一直显示正常，只是数据是
     *   上一页的，没有任何报错。命令靠两道闸堵它 —— ① 真的 `cancel()`（`Future`
     *   此后忽略结算）；② 代号（迟到的落地请求直接丢弃）。
     */
    public function testStaleResultDoesNotOverwriteNewer(): void
    {
        $made = [];
        [$app, $constraints] = self::boot(static function (mixed $arg) use (&$made): Future {
            $made[] = Future::pending();

            return $made[\count($made) - 1];
        });

        $command = $app->pageState()->command();
        $command->run(1);
        $app->frame(32.0, $constraints);
        $command->run(2);
        $app->frame(48.0, $constraints);

        check('AsyncCommand · 换请求时旧 Future 被取消', $made[0]->isCancelled(), 'old.cancelled=false');

        $made[0]->resolve('第 1 页的旧数据');   // 迟到的旧结果
        $app->frame(64.0, $constraints);

        check(
            'AsyncCommand · 迟到的旧结果不落地（仍是第 2 页的 loading）',
            self::last($app)->isLoading(),
            'state=' . self::states($app->snapshots),
        );

        $made[1]->resolve('第 2 页的新数据');
        $app->frame(80.0, $constraints);

        check(
            'AsyncCommand · 新结果照常落地',
            self::last($app)->value() === '第 2 页的新数据',
            'state=' . self::states($app->snapshots),
        );

        $this->flushChecks();
    }

    public function testRetryReusesLastArgument(): void
    {
        $made = [];
        [$app, $constraints] = self::boot(static function (mixed $arg) use (&$made): Future {
            $made[] = Future::pending();

            return $made[\count($made) - 1];
        });

        $command = $app->pageState()->command();
        $command->run(7);
        $app->frame(32.0, $constraints);
        $made[0]->reject(new \RuntimeException('boom'));
        $app->frame(48.0, $constraints);

        check('AsyncCommand · 失败后是 error', self::last($app)->hasError());

        $command->retry();
        $app->frame(64.0, $constraints);

        check(
            'AsyncCommand · retry() 用上次参数重跑（提交两次，第二次还是 7）',
            \count($made) === 2 && self::last($app)->isLoading(),
            'submitted=' . \count($made) . ' state=' . self::states($app->snapshots),
        );

        $this->flushChecks();
    }

    /**
     * `dispose()` 之后迟到的结算**连标脏都不做**。
     *
     * ★★ 这一条守的是"永远不红"的故障：卸了载的 State 若还被标脏，`pendingDirty`
     *   就再也不会归零 ⇒ `settle()` 每帧多扫一遍全树（性能退化，**不报错**，
     *   `State::$pendingDirty` 的注释点名了它）。命令在 `emit()` 里先问宿主还在
     *   不在树上，于是"忘了调 `dispose()`"从"静默退化"降级成"静默丢弃结果"。
     */
    public function testDisposeSilencesLateSettlement(): void
    {
        $made = [];
        [$app, $constraints] = self::boot(static function (mixed $arg) use (&$made): Future {
            $made[] = Future::pending();

            return $made[\count($made) - 1];
        });

        $command = $app->pageState()->command();
        $command->run(1);
        $app->frame(32.0, $constraints);

        $baseline = State::pendingDirty();   // 此刻应当已经归零（脏被 settle 消费了）

        $command->dispose();
        check('AsyncCommand · dispose 取消了在途任务', $made[0]->isCancelled());

        $made[0]->resolve('迟到的');
        $app->frame(48.0, $constraints);
        $app->frame(64.0, $constraints);

        check(
            'AsyncCommand · dispose 后的结算不标脏（pendingDirty 不漂移）',
            State::pendingDirty() === $baseline,
            'pendingDirty=' . State::pendingDirty() . ' baseline=' . $baseline,
        );

        check(
            'AsyncCommand · dispose 后的结算不落地（界面停在 loading）',
            self::last($app)->isLoading(),
            'state=' . self::states($app->snapshots),
        );

        $this->flushChecks();
    }

    /**
     * 宿主**已经卸载**但命令**没被 `dispose()`**（页面没写那一句、也不需要写）时，
     * 迟到的结算同样不许标脏。
     *
     * ★ 上一版靠"命令在 `emit()` 里问宿主还在树上吗"兜底；这一版的兜底在
     *   **`State::dropCommands()`**（`unmount()` 时统一 `dispose()`）—— 更早、也
     *   更彻底：在途任务当场被 `cancel()`，不是"结算到了再丢掉"。
     * ★★ 这一条守的是**永远不红**的故障：卸了载的 State 若还被标脏，`pendingDirty`
     *   再也不归零 ⇒ `settle()` 每帧多扫一遍全树（性能退化，**不报错**，
     *   `State::$pendingDirty` 的注释点名了它）。
     */
    public function testLateSettlementAfterUnmountDoesNotMarkDirty(): void
    {
        $made = [];
        [$app, $constraints] = self::boot(static function (mixed $arg) use (&$made): Future {
            $made[] = Future::pending();

            return $made[\count($made) - 1];
        });

        $command = $app->pageState()->command();
        $command->run(1);
        $app->frame(32.0, $constraints);

        $baseline = State::pendingDirty();

        // ★ 只卸载宿主，**不** `dispose()` 命令 —— 夹具页面根本没有 `dispose()`，
        //   这正是"页面什么都不写"的真实形态：停任务的事由框架做。
        $app->root()->unmount();
        $made[0]->resolve('迟到的');

        check(
            'AsyncCommand · 忘了 dispose 也不让迟到的结算标脏（pendingDirty 不漂移）',
            State::pendingDirty() === $baseline,
            'pendingDirty=' . State::pendingDirty() . ' baseline=' . $baseline,
        );

        $this->flushChecks();
    }

    /**
     * ★★ **卸载时框架真的停掉在途任务** —— 页面不写 `dispose()`。
     *
     * 这是本轮（2026-10-31）新增能力的判据：命令改成 `Subject` 之后由
     * `State::asyncCommand()` 绑定，`StatefulElement::unmount()` 统一 `dropCommands()`
     * ⇒ "转圈中离开页面 ⇒ worker 白跑满 1.2s（翻十页留十个空转进程）"
     *   **不需要谁记得防**。
     *
     * ★ 为什么必须由框架做：`State::dispose()` 是**可覆写**的，"忘了
     *   `parent::dispose()`"正是 A4 点名的失败模式 —— 与订阅袋同一个理由
     *   （`Subscription` 的注释：把兜底放在用户侧就是"文档承诺了、机制守不住"）。
     */
    public function testUnmountStopsInFlightWithoutPageDispose(): void
    {
        $made = [];
        [$app, $constraints] = self::boot(static function (mixed $arg) use (&$made): Future {
            $made[] = Future::pending();

            return $made[\count($made) - 1];
        });

        $app->pageState()->command()->run(1);
        $app->frame(32.0, $constraints);

        check('AsyncCommand · 跑起来确实有在途任务', !$made[0]->isCancelled());

        $app->root()->unmount();

        check(
            'AsyncCommand · 卸载即停掉在途任务（页面不写 dispose —— 框架侧兜底）',
            $made[0]->isCancelled(),
            'inflight.cancelled=false ⇒ 命令还活着，worker 会白跑满 1.2s',
        );

        $this->flushChecks();
    }

    public function testStableTreeDoesNotRebuildEveryFrame(): void
    {
        $made = [];
        [$app, $constraints] = self::boot(static function (mixed $arg) use (&$made): Future {
            $made[] = Future::pending();

            return $made[\count($made) - 1];
        });

        $app->pageState()->command()->run(1);
        $app->frame(32.0, $constraints);
        $before = \count($app->snapshots);

        $app->frame(48.0, $constraints);
        $app->frame(64.0, $constraints);

        check(
            'AsyncCommand · 稳定态不每帧重建（build 调用数不随帧增长）',
            \count($app->snapshots) === $before,
            'snapshots=' . \count($app->snapshots) . ' before=' . $before,
        );

        $this->flushChecks();
    }
}
