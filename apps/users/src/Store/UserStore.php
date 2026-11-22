<?php
declare(strict_types=1);

namespace App\Users\Store;

use Drift\App\TaskHost;
use Drift\Async\Future;
use Drift\App\Restoration\RestorationBucket;
use Drift\Core\Computed;
use Drift\State\AsyncCommand;
use Drift\State\Cell;

use App\Users\Jobs\FetchUsersJob;

/**
 * 行数据 + 四态 + 搜索（§A.2.8）。
 *
 * ## 三个可观察值，各订各的（2026-11-22 从 `extends Subject` 迁来）
 *
 * | 值 | 形态 | 变化频率 |
 * |---|---|---|
 * | 行 | `Cell<list<User>>` | 一次取数一次 |
 * | 搜索词 | `Cell<string>` | 每个按键一次 |
 * | 四态 | `AsyncCommand`（自己就是 Subject） | 发起两趟 + 进度 |
 *
 * ★ **不再有"转发"这一层**：以前页面订本 Store，命令变 ⇒ `absorb()` 里
 *   `notifyChanged()` ⇒ 页面被叫醒。现在 `AsyncCommand` 自己就是可观察值
 *   （读 `value()` / `isRunning()` 即订阅），页面直接订命令，`absorb()` 只做
 *   "把载荷落到行"这件业务事。
 *
 * ## 派生值用 `Computed::of`（自动依赖）
 *
 * `visible()` 每帧会被读一次，不缓存就是每帧一次全表过滤。自动依赖版在求值期
 * 采集"读了哪两个格子"⇒ 改 `rows` / `query` 才重算，别的都不惊动它。
 * ★ 以前是 `new Computed($fn, [$this])` 的**粗依赖**（靠 `notifyChanged()` 推
 *   版本号）：Store 上任何字段变化都白算一次。两格之后粗依赖没有意义了。
 * ★ 自动模式自带采集帧 ⇒ `visible()` 读的两个格子不会被算到**页面的**订阅表上，
 *   页面订到的是展平后的源（`Computed::sources()`），重建时再 `get()` 一次。
 *
 * ## 取数不是手写状态机（2026-10-31）
 *
 * 之前本类是 `AsyncCommand` 的同款逻辑**手写第二遍**：`LoadStatus` 枚举 +
 * `$status` / `$error` / `$rows` 三个字段 + `$inflight` + `load()` 六步 +
 * `applyPage()` 出口。那份手写版的所有理由（旧请求作废、`Loading` 必须先通知、
 * `catch` 必须挂、出口只能有一个）现在都由命令保证，**全仓一份**：
 *
 * ```php
 * $this->page = AsyncCommand::make(fn(int $p): Future => $this->fetch($p));
 * $this->page->subscribeFor($this, fn() => $this->absorb());   // 命令变 ⇒ 落行
 * public function load(int $p = 1): void { $this->page->run($p); }
 * ```
 *
 * ## 两条取数路径，出口只剩命令那一个
 *
 * - 有 `TaskHost` ⇒ 提交 `FetchUsersJob` 到后台（**真异步**：进程池 / 同步队列都行）；
 * - 没有 ⇒ 老路 `$src->fetchPage()`（同步，解释器与旧测试用）。
 *   ★ 两条路都交 `list<User>` 给命令：同步源给的就是对象，后台回的是 JSON
 *   （唯一的跨进程形态）⇒ **解码只有一处**（`fetch()` 里那个 `then()`），
 *   `absorb()` 只做赋值。
 *   ★ 这也是 §11.5 那条结论的兑现："`loading` 只在同步 IO 下不可见" —— 走后台后它
 *     真的能被用户看到（mock 后端故意慢 1.2 秒）。
 */
final class UserStore
{
    /** @var Cell<list<User>> */
    private Cell $rows;

    /**
     * 取数命令（**唯一的异步簿记**）。
     *
     * ★ 它自己就是可观察值（`AsyncCommand implements Readable`）⇒ 页面直接读
     *   `page()->value()` 就订上了，不需要本 Store 转发一次广播。
     */
    private AsyncCommand $page;

    /** @var Cell<string> */
    private Cell $query;

    private ?RestorationBucket $bucket = null;

    /** @var Computed<list<User>> 按 `$query` 过滤 `$rows` */
    private Computed $visible;

    /**
     * @param null|TaskHost $host 非 null ⇒ 取数走后台（§17.4.5）；null ⇒ 同步 `$src`
     */
    public function __construct(
        private readonly UserSource $src,
        private readonly ?TaskHost $host = null,
        private readonly string $apiBase = FetchUsersJob::DEFAULT_BASE,
    ) {
        $this->rows = Cell::of([])->named('users.rows');
        $this->query = Cell::of('')->named('users.query');

        $this->page = AsyncCommand::make(function (int $page): Future {
            return $this->fetch($page);
        });

        // ★ 命令换值 ⇒ 落行。落在**构造器**里而不是 `load()` 里：订阅一次就够
        //   （§11.3 RT-1 —— 每 load 一次订一份，订阅数会随取数次数增长）。
        $this->page->subscribeFor($this, function (): void {
            $this->absorb();
        });

        $this->visible = Computed::of(fn(): array => $this->filtered());
    }

    /** 取数命令（页面读四态 / 禁用刷新按钮都从这里走） */
    public function page(): AsyncCommand
    {
        return $this->page;
    }

    /** @return list<User> */
    public function rows(): array
    {
        $rows = $this->rows->get();

        return \is_array($rows) ? $rows : [];
    }

    /** 冷启动复原搜索词；之后每次 setQuery 都写回同一份 bucket。 */
    public function bindRestoration(RestorationBucket $bucket): void
    {
        $this->bucket = $bucket;
        $this->query->set(self::text($bucket->read('users.query', '')));
    }

    public function query(): string
    {
        return self::text($this->query->get());
    }

    /** @return list<User> */
    public function visible(): array
    {
        $rows = $this->visible->get();

        return \is_array($rows) ? $rows : [];
    }

    /** 取一页 —— 只是"让命令跑一次"（去重 / 作废旧请求 / 重试都由命令管） */
    public function load(int $page = 1): void
    {
        $this->page->run($page);
    }

    public function setQuery(string $q): void
    {
        // ★ 只有值真的变了才写存档（`peek()`：这是一次写，不该顺手建立依赖）
        if ($q === self::text($this->query->peek())) {
            return;
        }

        $this->query->set($q);
        $this->bucket?->write('users.query', $q);
    }

    public function remove(int $id): bool
    {
        if (!$this->src->delete($id)) {
            return false;
        }

        $rows = $this->rows->peek();
        $this->rows->set(\is_array($rows) ? \array_values(\array_filter(
            $rows,
            static fn(User $u): bool => $u->id !== $id,
        )) : []);

        return true;
    }

    /**
     * 一次取数（**两条路径**）—— 命令要的那个 `\Closure(int): Future`。
     *
     * ★ 必须回 `Future`（不能像以前那样"同步路径直接就地改状态"）：命令的统一
     *   出口在 `then/catch` 上，绕过它 ⇒ 三态、去重、取消又各走各的。
     * ★ 同步路径失败用 `Future::rejected()`：路径不同、出口相同。
     * ★ 两条路都交 `list<User>`（见类注释）⇒ 命令的 `data` 载荷就是行，
     *   `absorb()` 不再需要认识"原始数组"。
     * ★ 后台路径**派生**（`submit()->then(...)`）是 2026-10-31 之后才合法的写法：
     *   `Future::cancel()` 现在沿链回到根 ⇒ 派生出来的这条对在途任务**真的**有效。
     */
    private function fetch(int $page): Future
    {
        $host = $this->host;
        if ($host === null) {
            // 同步路径（解释器 / 没装后台端口的应用）
            $r = $this->src->fetchPage($page);

            return $r->ok
                ? Future::resolved($r->items)
                : Future::rejected(new \RuntimeException($r->error));
        }

        // ★ 后台路径只有**一个**跨边界形态：JSON 数组（`FetchUsersJob` 回的）
        //   ⇒ 解码就写在这一个 `then()` 里，别处不再判"这次来的是哪种"。
        return $host->submit(new FetchUsersJob($page, $this->apiBase))
            ->then(static fn (mixed $raw): array => \is_array($raw) ? UserSeed::hydrate($raw) : []);
    }

    /**
     * 命令载荷 ⇒ 行（**唯一的落地口**）。
     *
     * ★ 只有 `data` 态改写行：失败时保留上一次的行（界面画 error，但列表不该被
     *   清空 —— 以前 `applyPage()` 也是这个语义）。"旧内容还在"由 `AsyncValue`
     *   的 `carriedFrom()` 保证，不是由这里兜底。
     * ★ 不再 `notifyChanged()`：格子 `set()` 自己广播；值没变（失败沿用旧行）
     *   就什么都不发生 —— 这正是想要的，四态的呈现由命令自己的那条订阅驱动。
     */
    private function absorb(): void
    {
        $value = $this->page->value();
        if ($value->hasData()) {
            $rows = $value->value();
            $this->rows->set(\is_array($rows) ? $rows : []);
        }
    }

    /** @return list<User> 派生值本体（求值期读两个格子 ⇒ 自动依赖） */
    private function filtered(): array
    {
        $rows = $this->rows->get();
        if (!\is_array($rows)) {
            return [];
        }

        $query = self::text($this->query->get());
        if ($query === '') {
            return $rows;
        }

        return \array_values(\array_filter(
            $rows,
            static fn(User $u): bool => str_contains($u->name, $query),
        ));
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
