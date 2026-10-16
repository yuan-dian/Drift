<?php
declare(strict_types=1);

namespace App\Users\Store;

/**
 * 内存源（§A.2.8 目录树）：**测试 / 离线 / 本样板的默认后端**。
 *
 * ★ 它存在的意义：没有网络、没有 `TaskHost`（M4）也能把整条链跑起来 ——
 *   M3 的出口判据"§A.2 的完整应用可跑"要的是**应用形态**可跑，不是真连后端。
 */
final class MemoryUserSource implements UserSource
{
    /** @var list<User> */
    private array $rows = [];

    public function __construct()
    {
        $this->rows = [
            new User(1, '张伟', 'zhangwei@example.com'),
            new User(2, '李娜', 'lina@example.com'),
            new User(3, '王强', 'wangqiang@example.com'),
            new User(4, '赵敏', 'zhaomin@example.com'),
        ];
    }

    public function fetchPage(int $page): Page
    {
        return new Page(ok: true, items: $this->rows);
    }

    public function delete(int $id): bool
    {
        $before = count($this->rows);
        $this->rows = array_values(array_filter(
            $this->rows,
            static fn(User $u): bool => $u->id !== $id,
        ));

        return count($this->rows) !== $before;
    }

    public function submit(string $endpoint, array $payload): bool
    {
        // 内存源：登记一个新 id（真后端是 HTTP POST）
        $this->rows[] = new User(
            count($this->rows) + 1,
            (string) ($payload['name'] ?? ''),
            (string) ($payload['email'] ?? ''),
        );

        return true;
    }
}
