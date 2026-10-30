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
 *   于是 `AsyncValue::loading()` 那一态**真的对用户可见**（§11.5 的结论：同步 IO 下
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

    /** 连接超时（秒）：连不上就该早失败，不要等满总超时 */
    private const CONNECT_TIMEOUT_S = 5;

    /** 总超时（秒）：比 mock 的 1.2s 宽裕，又不至于让 UI 永远等 */
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

        // ★ 用 **curl** 而不是 `file_get_contents`：
        //   - 连接超时与总超时能分开设（`CURLOPT_CONNECTTIMEOUT` vs `CURLOPT_TIMEOUT`）——
        //     "连不上"要 5s 就断，"响应慢"可以等 10s；
        //   - 能拿到 HTTP 状态码（4xx/5xx 不该当成"解析失败"）；
        //   - HTTPS 的证书校验是一等公民（`file_get_contents` 要靠 stream context 拼）。
        //   ★ 前提：运行时 ini 要 `extension=curl`（`drift-build` 现在会启它，见那儿的注释）。
        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl 初始化失败（扩展没加载？）：' . $url);
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_S,
            CURLOPT_TIMEOUT => self::TIMEOUT_S,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = (string) curl_error($ch);

        if (!is_string($raw)) {
            throw new \RuntimeException('取用户失败（curl）：' . $error . ' —— ' . $url);
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('取用户失败（HTTP ' . $status . '）：' . substr($raw, 0, 200));
        }

        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['ok'])) {
            throw new \RuntimeException('取用户失败（服务端）：' . substr($raw, 0, 200));
        }

        $items = $data['items'] ?? [];

        return is_array($items) ? $items : [];
    }
}
