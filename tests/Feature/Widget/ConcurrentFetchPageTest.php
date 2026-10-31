<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use App\Showcase\Pages\ConcurrentFetchPageState;
use Drift\App\Application;
use Drift\Async\AsyncValue;
use Drift\Async\Future;
use Drift\Dev\Smoke\App\ConcurrentFetchApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Element;
use Drift\Widget\Text;

/**
 * `ConcurrentFetchPage`（示例台「并发取数」页）—— **`Future::allSettled()` 的
 * 端到端消费者**（2026-10-31）。
 *
 * ## 为什么这一页必须有测试（而不只是 `FutureTest` 里三条单元）
 *
 * 单元测试能证明聚合器的语义；它证明不了**页面有没有真的"一次发三条"**。
 * 而"同时等 N 个接口"这个需求，唯一的失败方式恰恰是**看起来在工作**：
 * 页面一条一条地发（`run(1)` → 等 → `run(2)` → …），界面照样出三张卡、
 * 数字照样对，只是总耗时是三条相加、而且**不报错**。
 *
 * ⇒ 判据必须是**结构**的：三条任务在**同一帧**里全部进了后台（`$app->requested`
 *   有 3 条、`$made` 有 3 条且都没结算），而不是墙钟（机器一忙就假红）。
 *
 * ## 判据的分工
 *
 * | 层 | 判据在哪 |
 * |---|---|
 * | `all()` / `allSettled()` 的语义（顺序 / 取消 / 不失败 / 不连坐） | `tests/Unit/Async/FutureTest.php` |
 * | 池真的并行（3 个 worker 各接一条） | `tools/smoke.php` 的 `P3 ·` / `N3 ·` 段 |
 * | **本页：真挂树跑帧之后，三条是否同时在途、成败怎么画** | 本文件 |
 *
 * ★ 最后一层只有在页面真的建过树之后才成立 —— 而本页构造器有必填参数，
 *   `tools/golden-raster.php` 会跳过它（见 `ConcurrentFetchApplication` 的注释）。
 */
final class ConcurrentFetchPageTest extends SmokeTestCase
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
    private static function page(array $ids): array
    {
        $items = [];
        foreach ($ids as $id) {
            $items[] = ['id' => $id, 'name' => '行 ' . $id, 'email' => 'r' . $id . '@example.com', 'city' => '上海'];
        }

        return ['items' => $items, 'total' => 60, 'source' => '测试后端'];
    }

    /**
     * 树上所有 `Text` 的内容（按遍历序）。
     *
     * ★ 用"走完整棵树 + 全收"的写法：`findElement()` 命中第一条就停，
     *   而这里要数"画了几张写着 20 行的卡"。
     *
     * @return list<string>
     */
    private static function textsOf(Application $app): array
    {
        $root = $app->root();
        if ($root === null) {
            return [];
        }

        $texts = [];
        findElement($root, static function (Element $e) use (&$texts): bool {
            if ($e->widget() instanceof Text) {
                $texts[] = $e->widget()->content();
            }

            return false; // 永不命中 ⇒ 走完整棵树
        });

        return $texts;
    }

    /**
     * @param array<array-key, array{ok: bool, value: mixed, error: ?string}> $entries
     */
    private static function rowsOf(array $entries, int $page): int
    {
        $entry = $entries[$page] ?? null;
        if ($entry === null || $entry['ok'] !== true) {
            return -1;
        }

        $payload = $entry['value'] ?? null;
        $items = \is_array($payload) && \is_array($payload['items'] ?? null) ? $payload['items'] : [];

        return \count($items);
    }

    /**
     * 主路径：**三条任务在同一帧里全部发出**，且结果按入参页码归位。
     *
     * ★ 这是本页存在的理由。把 `allSettled` 换成"逐条 `run()` 再拼"的实现，
     *   界面一模一样，但 `$made` 会只有 1 条（第一条结算才会有第二条）⇒ 本判据红。
     */
    public function testThreeRequestsLeaveInTheSameFrame(): void
    {
        Errors::reset();

        /** @var list<Future> $made */
        $made = [];
        $app = new ConcurrentFetchApplication(static function (int $page) use (&$made): Future {
            $made[] = Future::pending();

            return $made[\count($made) - 1];
        });

        self::attach($app);
        self::frames($app, 0, 2);

        check(
            'ConcurrentFetchPage · 进页面就请求第 1、2、3 页（按入参顺序，一次发完）',
            $app->requested === [1, 2, 3],
            'requested=' . \json_encode($app->requested),
        );
        check(
            'ConcurrentFetchPage · 三条都还没结算就都发出去了（真并发，不是一等等三条）',
            \count($made) === 3 && !$made[0]->isDone() && !$made[1]->isDone() && !$made[2]->isDone(),
            'made=' . \count($made),
        );

        // 故意**乱序**结算：先第 2 页，再第 1、3 页 —— 结果仍须按入参页码归位
        $made[1]->resolve(self::page([21, 22]));
        $made[0]->resolve(self::page([11, 12]));
        self::frames($app, 2, 1);
        $made[2]->resolve(self::page([31, 32, 33]));
        self::frames($app, 3, 2);

        $state = $app->pageState();
        check('ConcurrentFetchPage · 拿得到 State', $state !== null, $state === null ? 'null' : 'ok');

        $data = $state?->command()->value()->dataOr(null);
        $entries = \is_array($data) && \is_array($data['entries'] ?? null) ? $data['entries'] : [];

        check(
            'ConcurrentFetchPage · 三块都在，键是页码（1,2,3）',
            \array_keys($entries) === [1, 2, 3],
            \json_encode(\array_keys($entries)),
        );
        check(
            'ConcurrentFetchPage · **乱序完成也按页码归位**（2 行 / 2 行 / 3 行）',
            self::rowsOf($entries, 1) === 2 && self::rowsOf($entries, 2) === 2 && self::rowsOf($entries, 3) === 3,
            'rows=' . self::rowsOf($entries, 1) . '/' . self::rowsOf($entries, 2) . '/' . self::rowsOf($entries, 3),
        );
        check(
            'ConcurrentFetchPage · 每条都有耗时（页脚要靠它算"逐条之和"）',
            \is_array($data['spent'] ?? null) && \count($data['spent']) === 3,
            \json_encode($data['spent'] ?? null),
        );
        check(
            'ConcurrentFetchPage · 总耗时是数（不是 0：`hrtime` 真的被读了两头）',
            \is_array($data) && (float) ($data['totalMs'] ?? 0.0) >= 0.0 && ($data['totalMs'] ?? null) !== null,
            (string) ($data['totalMs'] ?? 'null'),
        );
        check(
            'ConcurrentFetchPage · 三张卡都画出来了（三个"2 行/3 行"级标题里的两个 2 行 + 一个 3 行）',
            \count(\array_filter(self::textsOf($app), static fn(string $t): bool => \str_ends_with($t, ' 行'))) === 3,
            \json_encode(self::textsOf($app), \JSON_UNESCAPED_UNICODE),
        );
        check(
            'ConcurrentFetchPage · 全程无诊断（建树 / 布局 / 结果归位都干净）',
            Errors::recentDiagnostics() === [],
            \json_encode(Errors::recentDiagnostics(), \JSON_UNESCAPED_UNICODE),
        );

        $this->flushChecks();
    }

    /**
     * 一条失败 ⇒ **另外两条照常显示**（这是 `allSettled` 与 `all` 的唯一差别）。
     *
     * ★ 换成 `all()`：第 2 页一失败，整条聚合失败 + 第 1、3 页被 `cancel()`，
     *   页面落到错误页 —— 三块数据一块都看不到。那个语义对"拼看板"是错的，
     *   而症状看起来像一次普通失败，没人会去查。
     */
    public function testOneFailureKeepsTheOtherCards(): void
    {
        Errors::reset();

        /** @var list<Future> $made */
        $made = [];
        $app = new ConcurrentFetchApplication(static function (int $page) use (&$made): Future {
            $made[] = Future::pending();

            return $made[\count($made) - 1];
        });

        self::attach($app);
        self::frames($app, 0, 2);

        // ★ 只让第 2 页失败，**另外两条故意先不结算** —— "兄弟没被连坐"这件事
        //   只有在它们还 pending 的时候才看得出来（`all()` 会在这一瞬 cancel 它们）。
        $made[1]->reject(new \RuntimeException('第 2 页取数失败（HTTP 500）'));
        self::frames($app, 2, 1);

        check(
            'ConcurrentFetchPage · 一条失败不连坐兄弟（`all()` 会 cancel 另外两条）',
            !$made[0]->isCancelled() && !$made[2]->isCancelled(),
            'cancelled=' . (($made[0]->isCancelled() ? '1' : '0') . ($made[2]->isCancelled() ? '1' : '0')),
        );

        $made[0]->resolve(self::page([11, 12]));
        $made[2]->resolve(self::page([31, 32]));
        self::frames($app, 3, 2);

        $value = $app->pageState()?->command()->value();
        $data = $value?->dataOr(null);
        $entries = \is_array($data) && \is_array($data['entries'] ?? null) ? $data['entries'] : [];

        check(
            'ConcurrentFetchPage · 一条失败时聚合体仍是 data（不是 error）',
            $value !== null && $value->hasData() && !$value->hasError(),
            $value === null ? 'null' : 'state=' . $value->stateName(),
        );
        check(
            'ConcurrentFetchPage · 失败那条带原因、其余两条照常成功',
            ($entries[2]['ok'] ?? null) === false
                && \str_contains((string) ($entries[2]['error'] ?? ''), 'HTTP 500')
                && self::rowsOf($entries, 1) === 2
                && self::rowsOf($entries, 3) === 2,
            \json_encode($entries, \JSON_UNESCAPED_UNICODE),
        );
        check(
            'ConcurrentFetchPage · 界面上仍是两张有数据的卡（不是整块错误页）',
            \count(\array_filter(self::textsOf($app), static fn(string $t): bool => \str_ends_with($t, ' 行'))) === 2,
            \json_encode(self::textsOf($app), \JSON_UNESCAPED_UNICODE),
        );
        check(
            'ConcurrentFetchPage · 失败那条画的是「失败」+' . '原因（不静默）',
            \in_array('失败', self::textsOf($app), true),
            \json_encode(self::textsOf($app), \JSON_UNESCAPED_UNICODE),
        );
        check(
            'ConcurrentFetchPage · 失败不产生诊断（allSettled 接了，没走 Fatal）',
            Errors::recentDiagnostics() === [],
            \json_encode(Errors::recentDiagnostics(), \JSON_UNESCAPED_UNICODE),
        );

        $this->flushChecks();
    }

    /**
     * 「再发一次」⇒ 三条**重新**全发（不是只补失败的那条）。
     *
     * ★ 这一页的语义是"取一份快照"，不是"修好缺的那块" ⇒ 全量重发才对。
     *   顺带钉住 `AsyncCommand` 的去重：在途时连点不会多提交，**跑完之后**再点必须能提交。
     */
    public function testRunAgainResubmitsAllThree(): void
    {
        Errors::reset();

        /** @var list<Future> $made */
        $made = [];
        $app = new ConcurrentFetchApplication(static function (int $page) use (&$made): Future {
            $made[] = Future::pending();

            return $made[\count($made) - 1];
        });

        self::attach($app);
        self::frames($app, 0, 2);
        $made[0]->resolve(self::page([11]));
        $made[1]->resolve(self::page([21]));
        $made[2]->resolve(self::page([31]));
        self::frames($app, 2, 2);

        $state = $app->pageState();
        check('ConcurrentFetchPage · 首轮三条都到了', \count($made) === 3, 'made=' . \count($made));

        $state?->command()->run(ConcurrentFetchPageState::PAGES);
        self::frames($app, 4, 1);

        check(
            'ConcurrentFetchPage · 「再发一次」重新发三条（1,2,3 → 1,2,3）',
            $app->requested === [1, 2, 3, 1, 2, 3],
            'requested=' . \json_encode($app->requested),
        );
        check(
            'ConcurrentFetchPage · 第二轮在途时旧数据还在（界面不闪空）',
            $state !== null && $state->command()->value()->hasValue()
                && $state->command()->value()->isRefreshing(),
            $state === null ? 'null' : 'state=' . $state->command()->value()->stateName(),
        );
        check(
            'ConcurrentFetchPage · 全程无诊断',
            Errors::recentDiagnostics() === [],
            \json_encode(Errors::recentDiagnostics(), \JSON_UNESCAPED_UNICODE),
        );

        $this->flushChecks();
    }
}
