<?php
declare(strict_types=1);

/**
 * 示例台的 mock 后端（`php -S` 的路由脚本）—— **只为演示/测试**，不是框架的一部分。
 *
 * ```bash
 * php -S 127.0.0.1:8098 tools/app-workers/showcase-mock.php
 * # 另开一个终端，让示例台走真 HTTP：
 * DRIFT_SHOWCASE_HTTP=1 ./build/showcase.exe        # 或 php apps/showcase/main.php
 * ```
 *
 * ## 它在整条链里的位置
 *
 * ```
 * UI 进程 ──AsyncHttp(curl_multi, 帧内非阻塞)──> 本文件（睡 1.2s 再回 JSON）
 *     ▲                                              │
 *     └──── Future 帧内 settle ◄─────────────────────┘
 * ```
 *
 * ★ **慢在网络里，不在 UI 线程里**：curl_multi 每帧只 `exec + select(0)`，
 *   等待期间窗口照常跑帧、转圈、拖动。这是本示例要证明的事。
 *
 * ★ **为什么还留 `local` 档**（`ShowcaseApplication::ROWS_LOCAL`）：本文件需要
 *   先起服务、占一个端口、还要记得关 —— 对一个"打开就能看"的示例台来说门槛太高。
 *   `local` 档用 `usleep` 在 worker 造同样的慢，零依赖；本文件验证**真实网络**这一档
 *   （连接超时、HTTP 状态码、JSON 解析失败都是真会发生的分支）。
 *
 * ★ 行数据来自 `RowsSeed`（与 local 档共用）——
 *   改字段只需改一处，不会出现"一边能画表、一边画空白"。
 *
 * ★ **`php -S` 默认单线程**：如果示例台连着翻两页，两个 worker 会在这里排队，
 *   第二页看起来要等 2.4s。这是**真实的**（别把它当成 bug 去修 UI）。
 *   想让 mock 也并发：`PHP_CLI_SERVER_WORKERS=4 php -S ...`（PHP 7.4+，Windows 不支持）。
 *
 * @see \App\Showcase\ShowcaseApplication
 * @see \App\Showcase\Jobs\RowsSeed
 */

require dirname(__DIR__) . '/autoload.php';

use App\Showcase\Jobs\RowsSeed;

$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// 健康检查 —— 探针用它等服务器就绪，不必陪着等 1.2 秒
if ($path === '/healthz') {
    echo '{"ok":true}';

    return;
}

if ($path === '/api/rows' && $method === 'GET') {
    // 慢：模拟一次跨网往返（与 FetchRowsJob::LOCAL_DELAY_US 同量级）
    usleep(1_200_000);

    $page = max(1, (int) ($_GET['page'] ?? 1));
    $per = (int) ($_GET['per'] ?? 20);
    if ($per < 1) {
        $per = 20;
    }

    $data = RowsSeed::page($page, $per);

    echo json_encode(
        ['ok' => true, 'items' => $data['items'], 'total' => $data['total'], 'page' => $page],
        JSON_UNESCAPED_UNICODE,
    );

    return;
}

// 故意留一个能触发 error 态的口子：把 per 设成 0 之外的方式是直接打这个路径,
// 用来真机验证「加载失败 → 点重试」这条 UI 分支。
if ($path === '/api/rows/boom') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '故意失败：用来看 error 态'], JSON_UNESCAPED_UNICODE);

    return;
}

http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'not found: ' . $path], JSON_UNESCAPED_UNICODE);
