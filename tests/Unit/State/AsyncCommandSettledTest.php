<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\State;

use Drift\Async\AsyncValue;
use Drift\Async\Future;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\State\AsyncCommand;

/**
 * `AsyncCommand::subscribeSettledFor()` —— 结算是独立的一等缝（2026-11-04）。
 *
 * ## 这一组钉的是什么（按"漏了会怎样"排序）
 *
 * | # | 判据 | 漏了的表现 |
 * |---|---|---|
 * | 1 | `loading` / 进度通知**不**触发结算 | 与 `subscribeFor()` 没区别，缝白开 |
 * | 2 | `land()` / `fail()` 各触发**一次**，且先值订阅者后结算订阅者 | 副作用跑在界面更新前，闪一帧旧状态 |
 * | 3 | **`cancel()` 回 `data` 不触发** | 用户取消却接力上传 / 轮询自己转起来（两次真 bug 的同源假阳性） |
 * | 4 | `cancel()` 回 `idle`、`dispose()`、旧请求作废都不触发 | "不再等了"被当成"结果到了" |
 * | 5 | `Subscription::cancel()` 后不再触发 | 退订失效，泄漏回调 |
 * | 6 | `append()` 合并分支只触发一次 | 追加落地漏广播，或广播两趟 |
 *
 * ★ 3 是最值钱的一条：`cancel()` 造的 `AsyncValue::data($old)` 与 `land()` 造的
 *   `AsyncValue::data($new)` **形状完全相同**，订阅者拿不到任何字段区分。
 *   把「是不是结算」从值上反推，就是近似；这条缝由 `AsyncCommand` 独占才成立。
 *
 * ★ 不挂树：结算由 `Future::resolve()` 同步触发（`Future` 的硬契约），
 *   `emit()` 不需要帧循环。挂树那一层留给 `AsyncCommandTest`。
 */
final class AsyncCommandSettledTest extends SmokeTestCase
{
    /** @var list<string> 值订阅者与结算订阅者的到达顺序 */
    private array $trace = [];

    /** @var list<Future> */
    private array $made = [];

    private function command(?\Closure $combine = null): AsyncCommand
    {
        $this->trace = [];
        $this->made = [];

        return AsyncCommand::make(
            function (mixed $arg): Future {
                $future = Future::pending();
                $this->made[] = $future;

                return $future;
            },
            $combine,
        );
    }

    /** 挂两个订阅口：值订阅者记录每一次广播，结算订阅者记录每一次结算 */
    private function observe(AsyncCommand $command): object
    {
        $owner = new \stdClass();

        $command->subscribeFor($owner, function () use ($command): void {
            $this->trace[] = 'value:' . self::stateOf($command->value());
        });
        $command->subscribeSettledFor($owner, function (AsyncValue $v): void {
            $this->trace[] = 'settled:' . self::stateOf($v);
        });

        return $owner;
    }

    private static function stateOf(AsyncValue $v): string
    {
        return $v->isIdle() ? 'idle' : ($v->isLoading() ? 'loading' : ($v->hasData() ? 'data' : 'error'));
    }

    /** @return list<string> 只留结算记录 */
    private function settledOnly(): array
    {
        return \array_values(\array_filter(
            $this->trace,
            static fn(string $entry): bool => \str_starts_with($entry, 'settled:'),
        ));
    }

    public function testLoadingAndProgressNeverSettleLandFiresOnceInOrder(): void
    {
        $command = $this->command();
        $this->observe($command);

        $command->run(1);
        check(
            '结算缝 · run() 的 loading 不触发结算',
            $this->trace === ['value:loading'],
            'trace=' . \implode(',', $this->trace),
        );

        $this->made[0]->reportProgress(0.5);
        check(
            '结算缝 · 进度通知不触发结算（值订阅者仍照常收到）',
            $this->trace === ['value:loading', 'value:loading'],
            'trace=' . \implode(',', $this->trace),
        );

        $this->made[0]->resolve('ok');
        check(
            '结算缝 · land() 只触发一次，且排在值订阅者之后',
            $this->trace === ['value:loading', 'value:loading', 'value:data', 'settled:data'],
            'trace=' . \implode(',', $this->trace),
        );

        $this->flushChecks();
    }

    public function testFailSettlesOnceWithError(): void
    {
        $command = $this->command();
        $this->observe($command);

        $command->run(1);
        $this->made[0]->reject(new \RuntimeException('boom'));

        check(
            '结算缝 · fail() 只触发一次且值为 error',
            $this->settledOnly() === ['settled:error'],
            'trace=' . \implode(',', $this->trace),
        );
        check(
            '结算缝 · error 也排在值广播之后',
            $this->trace === ['value:loading', 'value:error', 'settled:error'],
            'trace=' . \implode(',', $this->trace),
        );

        $this->flushChecks();
    }

    public function testCancelRestoringLastDataDoesNotSettle(): void
    {
        $command = $this->command();
        $this->observe($command);

        $command->run(1);
        $this->made[0]->resolve(['page' => 1]);
        check('前置 · 第一次结算已到达', $this->settledOnly() === ['settled:data']);

        $command->run(2);
        $command->cancel();

        check(
            '前置 · cancel() 回的确实是 data（与 land() 形状相同，才构成陷阱）',
            $command->value()->hasData() && !$command->value()->isLoading(),
            'state=' . self::stateOf($command->value()),
        );
        check(
            '结算缝 · cancel() 回 data 不触发结算（用户取消 ≠ 结果到了）',
            $this->settledOnly() === ['settled:data'],
            'settled=' . \implode(',', $this->settledOnly()),
        );

        $this->flushChecks();
    }

    public function testCancelToIdleDisposeAndStaleResultNeverSettle(): void
    {
        $command = $this->command();
        $this->observe($command);

        $command->run(1);
        $command->cancel();
        check(
            '结算缝 · 首屏 cancel() ⇒ idle，不触发',
            $command->value()->isIdle() && $this->settledOnly() === [],
            'trace=' . \implode(',', $this->trace),
        );

        $command->run(2);
        $command->run(3);                       // 换参数 ⇒ 上一条被真的 cancel
        $this->made[1]->resolve('迟到的旧结果');
        check(
            '前置 · 迟到的旧结果没有落地',
            $command->value()->isLoading() && $this->settledOnly() === [],
            'trace=' . \implode(',', $this->trace),
        );

        $this->made[2]->resolve('当前结果');
        check(
            '结算缝 · 只有当前那条结算触发（旧请求作废不触发）',
            $this->settledOnly() === ['settled:data'],
            'trace=' . \implode(',', $this->trace),
        );

        $command->run(4);
        $command->dispose();
        $this->made[3]->resolve('卸载后到');
        check(
            '结算缝 · dispose() 后的结算不触发',
            $this->settledOnly() === ['settled:data'],
            'settled=' . \implode(',', $this->settledOnly()),
        );

        $this->flushChecks();
    }

    public function testSubscriptionCancelStopsSettledCallback(): void
    {
        $command = $this->command();
        $owner = new \stdClass();
        $hits = 0;
        $subscription = $command->subscribeSettledFor($owner, function (AsyncValue $v) use (&$hits): void {
            ++$hits;
        });

        $command->run(1);
        $this->made[0]->resolve('first');
        check('前置 · 退订前收到一次', $hits === 1, 'hits=' . $hits);

        $subscription->cancel();
        $command->run(2);
        $this->made[1]->resolve('second');
        check(
            '结算缝 · Subscription::cancel() 之后不再收到结算',
            $hits === 1 && !$subscription->isActive(),
            'hits=' . $hits,
        );

        $this->flushChecks();
    }

    public function testAppendMergeBranchSettlesExactlyOnce(): void
    {
        $command = $this->command(static fn(mixed $old, mixed $new): array => [
            ...(\is_array($old) ? $old : []),
            ...(\is_array($new) ? $new : []),
        ]);
        $this->observe($command);

        $command->run(1);
        $this->made[0]->resolve(['a']);
        $command->append(2);
        $this->made[1]->resolve(['b']);

        check(
            '结算缝 · append 合并分支也只触发一次（land 的两条路径都带结算）',
            $this->settledOnly() === ['settled:data', 'settled:data'],
            'settled=' . \implode(',', $this->settledOnly()),
        );
        check(
            '前置 · 合并确实发生了（否则上面那条测的是替代分支）',
            $command->value()->value() === ['a', 'b'],
            'value=' . \json_encode($command->value()->value()),
        );

        $this->flushChecks();
    }
}
