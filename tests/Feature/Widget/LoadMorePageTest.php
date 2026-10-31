<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\App\Application;
use Drift\Async\AsyncValue;
use Drift\Async\Future;
use Drift\Dev\Smoke\App\LoadMoreApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Button;
use Drift\Widget\Element;

/**
 * `LoadMorePage`（示例台「加载更多」页）—— **追加语义的端到端消费者**（2026-10-31）。
 *
 * ## 为什么这一页必须有测试
 *
 * `tools/golden-raster.php` 自动发现 `apps/showcase/src/Pages/*.php`，但只收
 * 「构造器无必填参数」的页；本页要注入取数闭包 ⇒ 被跳过 ⇒ 它的 `build()`
 * 没有被任何基线覆盖。而它有三个只在**真建树**时才走到的风险点：
 *
 * | 风险点 | 表现 |
 * |---|---|
 * | `fold()` 的 `error` 支要读 `hasValue()`（带旧值时画列表） | 判错 ⇒ 一次失败把整页换成错误页 |
 * | `LazyColumn` 放进 `Expanded` | 无界高度 ⇒ 布局期断言 / 高度为 0 |
 * | 页脚文案由 `isRefreshing()` / `hasError()` 决定 | 判错 ⇒ 追加中"什么都没在发生" |
 *
 * ## 判据的分工（别和 `AsyncCommandAppendTest` 重复）
 *
 * - 命令侧（合并 / 在途保留 / 失败保留 / 重试）：`tests/Feature/State/AsyncCommandAppendTest.php`
 * - 本页侧：**真的挂树、真的跑帧之后，行攒起来了没有、诊断干净不干净**
 *   —— 这两件事只有"页面真的画过"才能证明。
 */
final class LoadMorePageTest extends SmokeTestCase
{
    private const W = 900.0;

    private const H = 640.0;

    private static function attach(Application $app): void
    {
        $surface = new SurfaceProbe();
        $surface->resize((int) self::W, (int) self::H, 1.0);
        $app->attach($surface, BoxConstraints::tight(new Size(self::W, self::H)));
    }

    /** 跑几帧（时间戳递增，否则帧时钟判"没前进"） */
    private static function frames(Application $app, int $from = 0, int $count = 3): void
    {
        $constraints = BoxConstraints::tight(new Size(self::W, self::H));
        for ($i = 0; $i < $count; $i++) {
            $app->frame(($from + $i) * 16.0, $constraints);
        }
    }

    /**
     * 一页数据（形状照抄 `FetchRowsJob`）。
     *
     * @param list<int> $ids
     *
     * @return array{items: list<array<string, mixed>>, total: int, source: string}
     */
    private static function page(array $ids, int $total = 6): array
    {
        $items = [];
        foreach ($ids as $id) {
            $items[] = ['id' => $id, 'name' => '用户 ' . $id, 'email' => 'u' . $id . '@example.com', 'city' => '北京'];
        }

        return ['items' => $items, 'total' => $total, 'source' => '测试'];
    }

    /** @return list<int> */
    private static function idsOf(AsyncValue $value): array
    {
        $data = $value->dataOr(null);
        $rows = \is_array($data) && \is_array($data['items'] ?? null) ? $data['items'] : [];

        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    /**
     * 主路径：进页面取第 1 页 → 「加载更多」追加第 2 页 ⇒ **行攒起来**（不是只剩第 2 页）。
     *
     * ★ 这条判据就是本页存在的理由。换成 `run(2)` 的话同一段代码会得到 `[3,4,5]`
     *   —— 界面看起来完全正常，只是前面的行没了。
     */
    public function testLoadMoreAppendsInsteadOfReplacing(): void
    {
        Errors::reset();

        $made = [];
        $app = new LoadMoreApplication(static function (int $page) use (&$made): Future {
            $made[] = Future::pending();

            return $made[\count($made) - 1];
        });

        self::attach($app);
        self::frames($app, 0, 2);

        check('LoadMorePage · 进页面就请求第 1 页（只一次）', $app->requested === [1],
            'requested=' . \json_encode($app->requested));

        $made[0]->resolve(self::page([1, 2, 3]));
        self::frames($app, 2, 2);

        $state = $app->pageState();
        check('LoadMorePage · 首屏 3 行', $state !== null && self::idsOf($state->command()->value()) === [1, 2, 3],
            $state === null ? '拿不到 State' : 'ids=' . \json_encode(self::idsOf($state->command()->value())));

        // 追加第 2 页
        $state->command()->append(2);
        self::frames($app, 4, 1);

        check(
            'LoadMorePage · 追加在途 ⇒ 值里还是第 1 页的 3 行（界面不闪空）',
            self::idsOf($state->command()->value()) === [1, 2, 3],
            'ids=' . \json_encode(self::idsOf($state->command()->value())),
        );

        $made[1]->resolve(self::page([4, 5, 6]));
        self::frames($app, 5, 2);

        check(
            'LoadMorePage · 第 2 页到达 ⇒ **6 行**（攒起来了，不是替代）',
            self::idsOf($state->command()->value()) === [1, 2, 3, 4, 5, 6],
            'ids=' . \json_encode(self::idsOf($state->command()->value())),
        );
        check('LoadMorePage · 两次请求分别要了第 1、2 页', $app->requested === [1, 2],
            'requested=' . \json_encode($app->requested));
        check('LoadMorePage · 全程无诊断（建树 / 布局 / 追加都干净）',
            Errors::recentDiagnostics() === [],
            \json_encode(Errors::recentDiagnostics(), \JSON_UNESCAPED_UNICODE));

        $this->flushChecks();
    }

    /**
     * 失败但**带旧值** ⇒ 仍然画列表（错误条在页脚），不是整块错误页。
     *
     * ★ 这是本页与「异步表格」的**唯一界面差别**，也是 `fold()` 不替页面做决定
     *   的地方：`error` 支照旧被选中（失败必须让用户看见），但页面可以在里面
     *   继续画已经拿到的行（`hasValue()`）。
     */
    public function testAppendFailureKeepsListOnScreen(): void
    {
        Errors::reset();

        $made = [];
        $app = new LoadMoreApplication(static function (int $page) use (&$made): Future {
            $made[] = Future::pending();

            return $made[\count($made) - 1];
        });

        self::attach($app);
        self::frames($app, 0, 2);
        $made[0]->resolve(self::page([1, 2, 3]));
        self::frames($app, 2, 2);

        $state = $app->pageState();
        $state->command()->append(2);
        self::frames($app, 4, 1);
        $made[1]->reject(new \RuntimeException('第 2 页取数失败'));
        self::frames($app, 5, 2);

        $value = $state->command()->value();
        check(
            'LoadMorePage · 追加失败 ⇒ error **且旧行还在**（hasValue）',
            $value->hasError() && $value->hasValue() && self::idsOf($value) === [1, 2, 3],
            'state=' . $value->stateName() . ' ids=' . \json_encode(self::idsOf($value)),
        );

        // ★ 判据不是"值对了"，是"那一帧真的画了列表"：错误页上没有「加载更多」按钮
        $hasMore = false;
        $root = $app->root();
        if ($root !== null) {
            findElement($root, static function (Element $e) use (&$hasMore): bool {
                if ($e->widget() instanceof Button && $e->widget()->label() === '加载更多') {
                    $hasMore = true;
                }

                return false;
            });
        }

        check(
            'LoadMorePage · 失败后界面仍是列表形态（工具条的「加载更多」还在，不是整块错误页）',
            $hasMore,
            $hasMore ? 'ok' : '找不到「加载更多」按钮 ⇒ 画成错误页了',
        );
        check('LoadMorePage · 失败不产生诊断（命令挂了 catch，没走 Fatal）',
            Errors::recentDiagnostics() === [],
            \json_encode(Errors::recentDiagnostics(), \JSON_UNESCAPED_UNICODE));

        $this->flushChecks();
    }

    /**
     * 首屏就失败（没有旧值）⇒ 整块错误页 + 重试按钮。
     *
     * ★ 与上一条对照：**有没有旧值**决定画法。两条都必须过，否则"带旧值就画列表"
     *   这条判据可能被写成"永远画列表"（首屏失败时画一张空表，用户以为没数据）。
     */
    public function testFirstLoadFailureShowsErrorPlaceholder(): void
    {
        Errors::reset();

        $made = [];
        $app = new LoadMoreApplication(static function (int $page) use (&$made): Future {
            $made[] = Future::pending();

            return $made[\count($made) - 1];
        });

        self::attach($app);
        self::frames($app, 0, 2);
        $made[0]->reject(new \RuntimeException('首屏取数失败'));
        self::frames($app, 2, 2);

        $value = $app->pageState()->command()->value();
        check(
            'LoadMorePage · 首屏失败 ⇒ error 且**没有**旧值（不该画空列表）',
            $value->hasError() && !$value->hasValue(),
            'state=' . $value->stateName() . ' hasValue=' . ($value->hasValue() ? '1' : '0'),
        );
        check('LoadMorePage · 首屏失败不产生诊断', Errors::recentDiagnostics() === [],
            \json_encode(Errors::recentDiagnostics(), \JSON_UNESCAPED_UNICODE));

        $this->flushChecks();
    }
}
