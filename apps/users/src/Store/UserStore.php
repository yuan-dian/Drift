<?php
declare(strict_types=1);

namespace App\Users\Store;

use Drift\State\Subject;

/**
 * 行数据 + 三态 + 搜索（§A.2.8）。
 *
 * ★ **派生值用缓存 getter**（§11.3.4）：`visible()` 每帧会被 `select` 调一次，
 *   不缓存就是每帧一次全表过滤。缓存失效点**只有**依赖变更处（lint `stale-derived`
 *   守"改了依赖忘了清缓存"这条）。
 */
final class UserStore extends Subject
{
    /** @var list<User> */
    private array $rows = [];

    private LoadStatus $status = LoadStatus::Idle;

    private string $query = '';

    /** @var null|list<User> */
    private ?array $visibleCache = null;

    public function __construct(private UserSource $src)
    {
    }

    public function status(): LoadStatus
    {
        return $this->status;
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
        return $this->visibleCache ??= ($this->query === ''
            ? $this->rows
            : array_values(array_filter(
                $this->rows,
                // ★ 非 static：闭包读 `$this->query`（static 闭包用 $this 是运行时错误，
                //   而它在 `notifyChanged()` 的订阅者回调里 ⇒ 报错会被"订阅者异常隔离"
                //   包成 RECOVERABLE ⇒ debug 下整局中止）
                fn(User $u): bool => str_contains($u->name, $this->query),
            )));
    }

    public function setQuery(string $q): void
    {
        if ($q === $this->query) {
            return; // ★ 相等门控
        }

        $this->query = $q;
        $this->visibleCache = null; // ★ 依赖变了 ⇒ 清缓存
        $this->notifyChanged();
    }

    public function load(int $page = 1): void
    {
        $this->status = LoadStatus::Loading;
        $this->notifyChanged();

        $r = $this->src->fetchPage($page);

        if ($r->ok) {
            $this->rows = $r->items;
            $this->status = LoadStatus::Ready;
        } else {
            $this->status = LoadStatus::Error;
        }

        $this->visibleCache = null; // ★ rows 变了 ⇒ 清缓存
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
        $this->visibleCache = null; // ★
        $this->notifyChanged();

        return true;
    }
}
