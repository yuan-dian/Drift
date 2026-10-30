<?php
declare(strict_types=1);

namespace App\Users\Store;

use Drift\App\TaskHost;
use Drift\Core\Computed;
use Drift\State\Subject;

use App\Users\Jobs\FetchUsersJob;

/**
 * 行数据 + 三态 + 搜索（§A.2.8）。
 *
 * ★ **派生值用 `Computed`**（`03` §4，W2/S5）：`visible()` 每帧会被 `select` 调一次，
 *   不缓存就是每帧一次全表过滤。原先是"手工缓存 + 4 处复位"（改依赖要记得清缓存），
 *   现在由**版本机制**保证不陈旧：依赖版本没变就直接给缓存，变了才重算。
 *   ⇒ "改了依赖忘了清缓存"这一整类静默 bug 结构上消失。
 *   粗依赖（`[$this]` = 整个 Store）是 §4.6 有意的取舍：不相干字段变化会多触发
 *   一次重算，换掉漏复位；本派生值算一次远小于 1ms，不触上界。
 *
 * ★★ **两条取数路径**（2026-10-18）：
 *   - 有 `TaskHost` ⇒ 提交 `FetchUsersJob` 到后台（**真异步**：进程池 / 同步队列都行）；
 *   - 没有 ⇒ 老路 `$src->fetchPage()`（同步，解释器与旧测试用）。
 *   ★ 两条路的**出口是同一个** `applyPage()` —— 三态、缓存失效、通知都不会走偏。
 *   ★ 这也是 §11.5 那条结论的兑现："`loading` 只在同步 IO 下不可见" —— 走后台后它
 *     真的能被用户看到（mock 后端故意慢 1.2 秒）。
 */
final class UserStore extends Subject
{
    /** @var list<User> */
    private array $rows = [];

    private LoadStatus $status = LoadStatus::Idle;

    /** 失败时的原因（`status === Error` 时有意义） */
    private string $error = '';

    private string $query = '';

    /**
     * 派生值：按 `$query` 过滤 `$rows`。
     *
     * ★ 依赖取**整个 Store**（`[$this]`，粗依赖）：`UserStore extends Subject`
     *   已实现 `Versioned`，`notifyChanged()` 先推版本 ⇒ 订阅者回调里读到的是新
     *   版本。改 `query`/`rows` 都会 `notifyChanged()` ⇒ 版本变 ⇒ 重算，无需手工复位。
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

    public function status(): LoadStatus
    {
        return $this->status;
    }

    public function error(): string
    {
        return $this->error;
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

    public function setQuery(string $q): void
    {
        if ($q === $this->query) {
            return; // ★ 相等门控
        }

        $this->query = $q;
        // ★ 依赖变了 ⇒ notifyChanged() 推版本 ⇒ visibleComputed 下次 get() 自动重算
        $this->notifyChanged();
    }

    /**
     * 取一页（三态从 `Loading` 开始）。
     *
     * ★ **`Loading` 必须在提交之前就 `notifyChanged()`** —— 否则那一帧的界面
     *   还是旧的 `Idle`，用户看不到"正在加载"（这是三态存在的全部意义）。
     */
    public function load(int $page = 1): void
    {
        $this->status = LoadStatus::Loading;
        $this->error = '';
        $this->notifyChanged();

        $host = $this->host;
        if ($host === null) {
            // 同步路径（解释器 / 没装后台端口的应用）
            $r = $this->src->fetchPage($page);
            $this->applyPage($r->ok, $r->items, $r->error);

            return;
        }

        // ★ 回调在**帧内**执行（`Application::frame()` 的 drain 段，在 settle 之前）
        //   ⇒ 这里的 `notifyChanged()` 会让同帧重建，看不到"空一帧"。
        // ★ 形状是 `Future`（N3）：`then()` 收成功、`catch()` 收失败。改造前是
        //   `submit(job, onDone, onError)` —— 两个入口（这个和 `submitSliced`）
        //   各有一套回调，调用方要记两套；现在只有一个 `Future`。
        $host->submit(new FetchUsersJob($page, $this->apiBase))
            ->then(function (array $raw): void {
                // ★ worker 回的是 JSON 数组（不是 `User` 对象）⇒ UI 侧 hydrate
                $this->applyPage(true, UserSeed::hydrate($raw), '');
            })
            ->catch(function (\Throwable $e): void {
                $this->applyPage(false, [], $e->getMessage());
            });
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
     * 两条取数路径的**唯一出口**（三态 / 缓存 / 通知只在这里落）。
     *
     * @param list<User> $rows
     */
    private function applyPage(bool $ok, array $rows, string $error): void
    {
        if ($ok) {
            $this->rows = $rows;
            $this->status = LoadStatus::Ready;
            $this->error = '';
        } else {
            $this->status = LoadStatus::Error;
            $this->error = $error;
        }

        $this->notifyChanged();
    }
}
