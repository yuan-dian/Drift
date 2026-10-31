<?php
declare(strict_types=1);

namespace App\Users\Store;

use Drift\App\TaskHost;
use Drift\Async\Future;
use Drift\Core\Computed;
use Drift\State\AsyncCommand;
use Drift\State\Subject;

use App\Users\Jobs\FetchUsersJob;

/**
 * 行数据 + 四态 + 搜索（§A.2.8）。
 *
 * ★ **派生值用 `Computed`**（`03` §4，W2/S5）：`visible()` 每帧会被 `select` 调一次，
 *   不缓存就是每帧一次全表过滤。原先是"手工缓存 + 4 处复位"（改依赖要记得清缓存），
 *   现在由**版本机制**保证不陈旧：依赖版本没变就直接给缓存，变了才重算。
 *   ⇒ "改了依赖忘了清缓存"这一整类静默 bug 结构上消失。
 *   粗依赖（`[$this]` = 整个 Store）是 §4.6 有意的取舍：不相干字段变化会多触发
 *   一次重算，换掉漏复位；本派生值算一次远小于 1ms，不触上界。
 *
 * ## ★★ 取数已经不是手写状态机了（2026-10-31）
 *
 * 之前本类是 `AsyncCommand` 的同款逻辑**手写第二遍**：`LoadStatus` 枚举 +
 * `$status` / `$error` / `$rows` 三个字段 + `$inflight` + `load()` 六步 +
 * `applyPage()` 出口。那份手写版的所有理由（旧请求作废、`Loading` 必须先通知、
 * `catch` 必须挂、出口只能有一个）现在都由命令保证，**全仓一份**：
 *
 * ```php
 * $this->page = AsyncCommand::make(fn(int $p): Future => $this->fetch($p));
 * $this->page->subscribeFor($this, fn() => $this->absorb());   // 命令变 ⇒ 我变
 * public function load(int $p = 1): void { $this->page->run($p); }
 * ```
 *
 * ⇒ `LoadStatus` 随之删除（四态已经是 `AsyncValue`），页面改读
 *   `$store->page()->value()` 并 `fold()`。
 *
 * ## 两条取数路径仍然都在，但出口只剩命令那一个
 *
 * - 有 `TaskHost` ⇒ 提交 `FetchUsersJob` 到后台（**真异步**：进程池 / 同步队列都行）；
 * - 没有 ⇒ 老路 `$src->fetchPage()`（同步，解释器与旧测试用）。
 *   ★ 两条路都回**同一种形态**（原始数组，见 `UserSeed::dehydrate()`）⇒
 *   "原始数据 → `User`"只在 `absorb()` 一处发生。
 *   ★ 这也是 §11.5 那条结论的兑现："`loading` 只在同步 IO 下不可见" —— 走后台后它
 *     真的能被用户看到（mock 后端故意慢 1.2 秒）。
 */
final class UserStore extends Subject
{
    /** @var list<User> */
    private array $rows = [];

    /**
     * 取数命令（**唯一的异步簿记**）。
     *
     * ★ 它是 `Subject`（`AsyncCommand` 的基类）⇒ 既是"四态的值"，也能被页面
     *   直接订阅。本 Store 自己先订一份（见构造器），于是**页面照旧只订 Store
     *   一个** —— 这是 §11.3 的既有形状，不为命令另开一条路。
     */
    private AsyncCommand $page;

    private string $query = '';

    /**
     * 派生值：按 `$query` 过滤 `$rows`。
     *
     * ★ 依赖取**整个 Store**（`[$this]`，粗依赖）：`UserStore extends Subject`
     *   已实现 `Versioned`，`notifyChanged()` 先推版本 ⇒ 订阅者回调里读到的是新
     *   版本。改 `query`/`rows` 都会 `notifyChanged()` ⇒ 版本变 ⇒ 重算，无需手工复位。
     *   ★ 命令的每次换值都会被 `absorb()` 转成 `notifyChanged()` ⇒ 版本同样变。
     *
     * @var Computed<list<User>>
     */
    private Computed $visibleComputed;

    /**
     * @param null|TaskHost $host 非 null ⇒ 取数走后台（§17.4.5）；null ⇒ 同步 `$src`
     */
    public function __construct(
        private UserSource $src,
        private ?TaskHost $host = null,
        private string $apiBase = FetchUsersJob::DEFAULT_BASE,
    ) {
        $this->page = AsyncCommand::make(function (int $page): Future {
            return $this->fetch($page);
        });

        // ★ 命令换值 ⇒ 本 Store 变（见 `$page` 的注释：页面只订 Store 一个）。
        //   落在**构造器**里而不是 `load()` 里：订阅一次就够（§11.3 RT-1 ——
        //   每 load 一次订一份，订阅数会随取数次数增长）。
        $this->page->subscribeFor($this, function (): void {
            $this->absorb();
        });

        $this->visibleComputed = new Computed(
            fn(): array => $this->query === ''
                ? $this->rows
                : array_values(array_filter(
                    $this->rows,
                    // ★ 非 static：闭包读 `$this->query`（static 闭包用 $this 是运行时错误，
                    //   而它在 `notifyChanged()` 的订阅者回调里 ⇒ 报错会被"订阅者异常隔离"
                    //   包成 RECOVERABLE ⇒ debug 下整局中止）
                    fn(User $u): bool => str_contains($u->name, $this->query),
                )),
            [$this],
        );
    }

    /** 取数命令（页面读四态 / 禁用刷新按钮都从这里走） */
    public function page(): AsyncCommand
    {
        return $this->page;
    }

    /** @return list<User> */
    public function rows(): array
    {
        return $this->rows;
    }

    public function query(): string
    {
        return $this->query;
    }

    /** @return list<User> */
    public function visible(): array
    {
        return $this->visibleComputed->get();
    }

    /** 取一页 —— 只是"让命令跑一次"（去重 / 作废旧请求 / 重试都由命令管） */
    public function load(int $page = 1): void
    {
        $this->page->run($page);
    }

    public function setQuery(string $q): void
    {
        if ($q === $this->query) {
            return; // ★ 相等门控
        }

        $this->query = $q;
        // ★ 依赖变了 ⇒ notifyChanged() 推版本 ⇒ visibleComputed 下次 get() 自动重算
        $this->notifyChanged();
    }

    public function remove(int $id): bool
    {
        if (!$this->src->delete($id)) {
            return false;
        }

        $this->rows = array_values(array_filter(
            $this->rows,
            static fn(User $u): bool => $u->id !== $id,
        ));
        $this->notifyChanged();

        return true;
    }

    /**
     * 一次取数（**两条路径**）—— 命令要的那个 `\Closure(int): Future`。
     *
     * ★ 必须回 `Future`（不能像以前那样"同步路径直接就地改状态"）：命令的统一
     *   出口在 `then/catch` 上，绕过它 ⇒ 三态、去重、取消又各走各的。
     * ★ 同步路径失败用 `Future::rejected()`：路径不同、出口相同（以前是
     *   `applyPage(false, [], $error)`，现在是"一条被拒的 Future"）。
     */
    private function fetch(int $page): Future
    {
        $host = $this->host;
        if ($host === null) {
            // 同步路径（解释器 / 没装后台端口的应用）
            $r = $this->src->fetchPage($page);

            return $r->ok
                ? Future::resolved(UserSeed::dehydrate($r->items))
                : Future::rejected(new \RuntimeException($r->error));
        }

        // ★ 后台路径：回**根** Future（`submit()` 的返回值），不是 `then()` 的产物
        //   —— 取消链下游不回溯上游 ⇒ 取消派生出来的那条对在途任务毫无作用。
        //   命令是这条根 Future 的唯一持有者 ⇒ 它敢 `cancel()`，迟到的旧结果因此
        //   永不结算（smoke 的「陈旧响应」断言就是这条）。
        return $host->submit(new FetchUsersJob($page, $this->apiBase));
    }

    /**
     * 命令换值 ⇒ 落到本 Store（**唯一的落地口**）。
     *
     * ★ 只有 `data` 态改写 `$rows`：失败时保留上一次的行（界面画 error，但列表
     *   不该被清空 —— 以前 `applyPage()` 也是这个语义）。
     * ★ 2026-10-31：`error` 现在也**带着**上一次的值（`AsyncValue::carriedFrom()`），
     *   但本方法仍以 `hasData()` 为门 —— 值没变，不必再赋值一遍（那会多推一次版本
     *   ⇒ 多一次派生值重算）。"旧内容还在"由命令保证，不是由这里兜底。
     * ★ 回调在**帧内**执行（`Application::frame()` 的 drain 段，在 settle 之前）
     *   ⇒ 这里的 `notifyChanged()` 会让同帧重建，看不到"空一帧"。
     */
    private function absorb(): void
    {
        $value = $this->page->value();
        if ($value->hasData()) {
            $raw = $value->value();
            $this->rows = \is_array($raw) ? UserSeed::hydrate($raw) : [];
        }

        $this->notifyChanged();
    }
}
