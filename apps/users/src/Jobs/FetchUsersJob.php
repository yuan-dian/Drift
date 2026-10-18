<?php
declare(strict_types=1);

namespace App\Users\Jobs;

use App\Users\Store\UserSeed;
use Drift\App\NamedJob;

/**
 * 取一页用户（**后台任务**，§17.4.5）—— 真的发一次 HTTP。
 *
 * ★ 它是"真异步"的演示载体：请求打到一个**慢**的 mock 后端（默认
 *   `php -S 127.0.0.1:8099 apps/users/mock-server.php`，延迟 1.2s），
 *   于是 `LoadStatus::Loading` **真的对用户可见**（§11.5 的结论：同步 IO 下
 *   那一态在帧末就被覆盖，用户永远看不到）。
 *
 * ★ 为什么是 HTTP 而不是 `usleep`：`usleep` 只证明"进程活着"，HTTP 才证明
 *   **后台进程真的在做 IO**（socket + 阻塞读 + 超时），而这正是 `TaskHost` 的卖点。
 *
 * ★ 返回**原始数组**而不是 `User` 实例：跨进程只能传 JSON 能表达的东西
 *   （`NamedJob` 的约定），转对象是 UI 侧 `UserSeed::hydrate()` 的事。
 *
 * ★ 服务地址走**构造参数**（默认常量）—— 不用 `getenv`：§16.7 的 `nano-hygiene`
 *   禁用 `getenv()`（AOT 不保证环境变量），而 `args()` 本来就能跨进程传。
 */
final class FetchUsersJob implements NamedJob
{
    /** 注册表里的键（UI 与 worker 必须一致） */
    public const NAME = 'users.fetch';

    /** mock 后端的默认地址（`DRIFT_API_BASE` 可覆盖） */
    public const DEFAULT_BASE = 'http://127.0.0.1:8099';

    /** 超时（秒）：比 mock 的 1.2s 宽裕，又不至于让 UI 永远等 */
    private const TIMEOUT_S = 10;

    public function __construct(
        private readonly int $page = 1,
        private readonly string $baseUrl = self::DEFAULT_BASE,
    ) {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function args(): array
    {
        return ['page' => $this->page, 'base' => $this->baseUrl];
    }

    public function run(): mixed
    {
        $url = rtrim($this->baseUrl, '/') . '/api/users?page=' . $this->page;

        // ★ 超时是**必须**的：没有它，后端悬挂会把 worker 永久占住
        //   （串行模型下等于整个后台层死掉）。
        $context = stream_context_create([
            'http' => ['timeout' => self::TIMEOUT_S, 'ignore_errors' => true],
        ]);

        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new \RuntimeException('取用户失败（网络）：' . $url);
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['ok'])) {
            throw new \RuntimeException('取用户失败（服务端）：' . substr($raw, 0, 200));
        }

        $items = $data['items'] ?? [];

        return is_array($items) ? $items : [];
    }
}
