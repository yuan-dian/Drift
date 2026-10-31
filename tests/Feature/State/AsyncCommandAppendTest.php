<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\State;

use Drift\Async\AsyncValue;
use Drift\Async\Future;
use Drift\Dev\Smoke\App\AsyncCommandApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;

/**
 * `AsyncCommand::append()` —— **追加语义**（2026-10-31）。
 *
 * ## 这一组钉的是什么
 *
 * | # | 判据 | 漏了的表现 |
 * |---|---|---|
 * | 1 | `append()` 落地时**合并**，不是替代 | 「加载更多」把前 20 行吃掉（**看起来像翻页，不报错**） |
 * | 2 | 追加**在途**时值仍是旧行（`isRefreshing()` + `hasValue()`） | 点一下「加载更多」，已读内容先消失一帧 |
 * | 3 | 追加**失败**时旧行还在（`error` + `hasValue()`） | 下一次翻页失败 ⇒ 整页变错误页，前面的行全没了 |
 * | 4 | `retry()` **复现上一次的动作** | "第 5 页失败 → 重试"把前 4 页替换掉 |
 * | 5 | 没给合并规则 ⇒ `append()` **当场抛** | 静默退化成替代，症状同 #1 |
 * | 6 | 追加之后 `run()` 仍然是替代 | 两个动作变成一个动作（"追加"没有存在意义） |
 *
 * ★ #1 与 #3 是这一组的价值所在：它们的错误形态**都不报错**，只是数据不对/少了一半。
 * ★ #4 是 #1 的延长线：失败重试是"把刚才那件事再做一次"，动作搞错就白合并了。
 */
final class AsyncCommandAppendTest extends SmokeTestCase
{
    private const W = 400.0;

    private const H = 300.0;

    /**
     * 起根为命令页面的应用。
     *
     * @param \Closure(mixed): Future $task
     * @param null|\Closure(mixed, mixed): mixed $combine
     *
     * @return array{0: AsyncCommandApplication, 1: BoxConstraints}
     */
    private static function boot(\Closure $task, ?\Closure $combine = null): array
    {
        $surface = new SurfaceProbe();
        $surface->resize((int) self::W, (int) self::H, 1.0);
        $constraints = BoxConstraints::tight(new Size(self::W, self::H));

        $app = new AsyncCommandApplication($task, $combine);
        $app->attach($surface, $constraints);
        $app->frame(16.0, $constraints);

        return [$app, $constraints];
    }

    /** build() 最后一次读到的值 */
    private static function last(AsyncCommandApplication $app): ?AsyncValue
    {
        $snapshots = $app->snapshots;

        return $snapshots === [] ? null : $snapshots[\count($snapshots) - 1];
    }

    /**
     * 一页数据（形状照抄 `apps/showcase` 的 `FetchRowsJob`）。
     *
     * @param list<int> $ids
     *
     * @return array{items: list<array{id: int}>, total: int}
     */
    private static function page(array $ids, int $total = 6): array
    {
        $items = [];
        foreach ($ids as $id) {
            $items[] = ['id' => $id];
        }

        return ['items' => $items, 'total' => $total];
    }

    /** 业务侧的合并规则（页面的那一份是 `LoadMorePageState::mergePages()`） */
    private static function merge(): \Closure
    {
        return static function (mixed $old, mixed $new): array {
            $oldItems = \is_array($old) && \is_array($old['items'] ?? null) ? $old['items'] : [];
            $newItems = \is_array($new) && \is_array($new['items'] ?? null) ? $new['items'] : [];

            return [
                'items' => \array_merge($oldItems, $newItems),
                'total' => \is_array($new) ? (int) ($new['total'] ?? 0) : 0,
            ];
        };
    }

    /**
     * #1 + #2 + #6：追加合并、在途保留旧行、之后 `run()` 仍替代。
     *
     * ★ 三条一起写是因为它们是**同一条时间线**上的三个时刻（发起 → 落地 → 换动作），
     *   拆开就得各自重放一遍帧序列，反而看不清"两个动作差在哪"。
     */
    public function testAppendMergesAndKeepsRowsWhileRunning(): void
    {
        $made = [];
        $args = [];
        [$app, $constraints] = self::boot(
            static function (mixed $arg) use (&$made, &$args): Future {
                $made[] = Future::pending();
                $args[] = $arg;

                return $made[\count($made) - 1];
            },
            self::merge(),
        );

        $command = $app->pageState()->command();

        // ① 首屏：run(1) —— 没有旧值，合也无从合
        $command->run(1);
        $app->frame(32.0, $constraints);
        $made[0]->resolve(self::page([1, 2]));
        $app->frame(48.0, $constraints);

        check(
            'AsyncCommand · run(1) 之后是第 1 页（2 行）',
            self::last($app)->hasData() && \count(self::last($app)->value()['items']) === 2,
            'state=' . self::last($app)->stateName(),
        );

        // ② 追加：在途时**旧行还在**
        $command->append(2);
        $app->frame(64.0, $constraints);

        $during = self::last($app);
        check(
            'AsyncCommand · append() 在途 ⇒ loading 且**带着上一次的载荷**（isRefreshing）',
            $during->isLoading() && $during->hasValue() && $during->isRefreshing()
            && \count($during->value()['items']) === 2,
            'state=' . $during->stateName() . ' hasValue=' . ($during->hasValue() ? '1' : '0'),
        );

        // ③ 落地：合并（2 + 2 = 4），不是替代
        $made[1]->resolve(self::page([3, 4]));
        $app->frame(80.0, $constraints);

        $merged = self::last($app);
        $ids = [];
        foreach ($merged->value()['items'] as $item) {
            $ids[] = (int) $item['id'];
        }

        check(
            'AsyncCommand · append() 落地 ⇒ 新旧**合并**（前 2 行没被吃掉）',
            $merged->hasData() && $ids === [1, 2, 3, 4],
            'ids=' . \json_encode($ids),
        );
        check('AsyncCommand · append() 落地后不再"在途"', !$command->isRunning());

        // ④ 换回 run()：仍是替代（两个动作没有被合成一个）
        $command->run(3);
        $app->frame(96.0, $constraints);
        $made[2]->resolve(self::page([5, 6]));
        $app->frame(112.0, $constraints);

        $replaced = self::last($app);
        $ids = [];
        foreach ($replaced->value()['items'] as $item) {
            $ids[] = (int) $item['id'];
        }

        check(
            'AsyncCommand · 追加之后 run() **仍然是替代**（不能被合并规则带跑）',
            $ids === [5, 6],
            'ids=' . \json_encode($ids),
        );
        check(
            'AsyncCommand · 三次发起拿到的是三个不同的参数（1 / 2 / 3）',
            $args === [1, 2, 3],
            'args=' . \json_encode($args),
        );

        $this->flushChecks();
    }

    /**
     * #3 + #4：追加失败 ⇒ 旧行还在、错误说得出来；`retry()` **仍然追加**。
     *
     * ★★ 这是最容易写错的一条：`retry()` 若走 `run()`，界面从"4 行"变成"第 2 页的
     *   2 行"—— 用户看到的只是"翻了一页"，**没有任何报错**。所以判据不能只看
     *   "重试成功了"，必须看**行数**。
     */
    public function testFailedAppendKeepsRowsAndRetryStaysAppend(): void
    {
        $made = [];
        $args = [];
        [$app, $constraints] = self::boot(
            static function (mixed $arg) use (&$made, &$args): Future {
                $made[] = Future::pending();
                $args[] = $arg;

                return $made[\count($made) - 1];
            },
            self::merge(),
        );

        $command = $app->pageState()->command();

        $command->run(1);
        $app->frame(32.0, $constraints);
        $made[0]->resolve(self::page([1, 2]));
        $app->frame(48.0, $constraints);

        $command->append(2);
        $app->frame(64.0, $constraints);
        $made[1]->reject(new \RuntimeException('第 2 页取数失败'));
        $app->frame(80.0, $constraints);

        $failed = self::last($app);
        check(
            'AsyncCommand · 追加失败 ⇒ error，但**旧行还在**（hasValue）',
            $failed->hasError() && $failed->hasValue()
            && \count($failed->value()['items']) === 2 && $failed->message() === '第 2 页取数失败',
            'state=' . $failed->stateName() . ' hasValue=' . ($failed->hasValue() ? '1' : '0'),
        );
        check('AsyncCommand · 追加失败后 isRunning() 为假（能再点）', !$command->isRunning());

        // 重试：必须**还是 append(2)**，不是 run(2)
        $command->retry();
        $app->frame(96.0, $constraints);
        $made[2]->resolve(self::page([3, 4]));
        $app->frame(112.0, $constraints);

        $retried = self::last($app);
        $ids = [];
        foreach ($retried->value()['items'] as $item) {
            $ids[] = (int) $item['id'];
        }

        check(
            'AsyncCommand · retry() 复现的是**追加**（1,2 + 3,4 = 4 行，不是只剩 3,4）',
            $retried->hasData() && $ids === [1, 2, 3, 4],
            'ids=' . \json_encode($ids),
        );
        check(
            'AsyncCommand · 重试用的还是上次那个参数（第 2 页）',
            $args === [1, 2, 2],
            'args=' . \json_encode($args),
        );

        $this->flushChecks();
    }

    /**
     * #5：没有合并规则时 `append()` **当场抛**，不静默退化成替代。
     *
     * ★ 判据是"抛出"而不是"状态没变"：静默退化才是要防的那件事 ——
     *   它会安静地吃掉前面的行，且看起来像一次正常翻页。
     */
    public function testAppendWithoutCombineThrows(): void
    {
        [$app] = self::boot(static function (mixed $arg): Future {
            return Future::pending();
        });

        $thrown = '';
        try {
            $app->pageState()->command()->append(2);
        } catch (\LogicException $e) {
            $thrown = $e->getMessage();
        }

        check(
            'AsyncCommand · 没给合并规则 ⇒ append() 当场抛（不静默退化成替代）',
            $thrown !== '' && \str_contains($thrown, '合并规则'),
            'message=' . ($thrown === '' ? '(没抛)' : $thrown),
        );

        $this->flushChecks();
    }
}
