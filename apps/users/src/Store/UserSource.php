<?php
declare(strict_types=1);

namespace App\Users\Store;

/** 一页数据 / 一次操作的结果（§A.2.8） */
final readonly class Page
{
    /** @param list<User> $items */
    public function __construct(
        public bool $ok,
        public array $items = [],
        public string $error = '',
    ) {
    }
}

/**
 * 数据源端口（§A.2.8 / §11.7）：**后端挂在源上，不挂 Application**。
 *
 * ★ M1–M3 用 `MemoryUserSource`（同步直跑）；M4 线程池 ⇒ 换 `HttpUserSource`
 *   （走 `TaskHost`），页面与 store 代码一字不改。
 */
interface UserSource
{
    public function fetchPage(int $page): Page;

    public function delete(int $id): bool;

    public function submit(string $endpoint, array $payload): bool;
}
