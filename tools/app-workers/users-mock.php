<?php
declare(strict_types=1);

/**
 * 临时 mock 后端（`php -S` 的路由脚本）—— **只为演示/测试**，不是框架的一部分。
 *
 * ```bash
 * php -S 127.0.0.1:8099 tools/app-workers/users-mock.php
 * # 然后启动应用（AOT 或解释器），它会把 users.fetch 交给后台进程去请求
 * ```
 *
 * ★ **为什么要有它**：`apps/users` 的异步链要证明"后台进程真的在做 IO"，
 *   而不是在 `usleep`。起一个真 HTTP 服务（带延迟）是最便宜的等价物 ——
 *   而且它把"慢"放在**网络**上，与真实后端同形。
 *
 * ★ **1.2 秒的延迟是刻意的**：`AsyncValue::loading()` 那一态只有在"明显慢"的时候
 *   才肉眼可见（§11.5 的结论：同步 IO 下它被帧末重建覆盖，用户永远看不到）。
 *   想验骨架屏/转圈就调这个数。
 */

require dirname(__DIR__) . '/autoload.php';

use App\Users\Store\UserSeed;

$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($path === '/api/users' && $method === 'GET') {
    // 慢：模拟一次跨网往返
    usleep(1_200_000);

    echo json_encode(
        ['ok' => true, 'items' => UserSeed::all(), 'page' => (int) ($_GET['page'] ?? 1)],
        JSON_UNESCAPED_UNICODE,
    );

    return;
}

// 健康检查（探针用它等服务器就绪，不必等 1.2 秒）
if ($path === '/healthz') {
    echo '{"ok":true}';

    return;
}

// ── 以下两条只服务 `tools/smoke.php` ㊶c（`Drift\App\Io\Http` 的真往返判据）──────
// ★ 为什么要在 mock 里加路由：那一段要证明的是 **HTTP 动词与请求体真的发出去了**、
//   **重定向跟随之后拿到的是最后一跳**。这两件事没有"更便宜的等价物" ——
//   拿假 Transport 测只能测到自己写的假货。

// 回显：把动词 / body / Content-Type 原样回声，供断言"发出去的是什么"
if ($path === '/echo' && $method === 'POST') {
    echo json_encode([
        'ok' => true,
        'method' => $method,
        'body' => (string) file_get_contents('php://input'),
        'type' => (string) ($_SERVER['CONTENT_TYPE'] ?? ''),
    ], JSON_UNESCAPED_UNICODE);

    return;
}

// 一跳 302 → /healthz（判"跟着走了"与"url() 是最后一跳"）
if ($path === '/redirect') {
    header('Location: /healthz', true, 302);

    return;
}

// ── multipart 回显（`Http` 的 `fields` / `files`）─────────────────────────────
//
// ★ 为什么不是"回显原始 body"：那样只能证明"字节到了"，证明不了"**结构对**"。
//   服务端（这里用 PHP 自己的 multipart 解析器当裁判）解出来的 `$_POST` / `$_FILES`
//   才是真实消费方看到的东西 —— 而 boundary 错、文件名没转义、part 少一个头，
//   全都表现为"字段缺失"而不是"解析失败"。
//
// ★ `boundary` 从 CONTENT_TYPE 里抠出来一起回显：判据要能证明"客户端给的
//   boundary"和"服务端用的 boundary"是同一个，否则"能解析"可能只是巧合。
if ($path === '/multipart' && $method === 'POST') {
    $type = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    $boundary = '';
    if (preg_match('/boundary=(.+)$/', $type, $m) === 1) {
        $boundary = trim($m[1], " \t\"");
    }

    $filesOut = [];
    foreach ($_FILES as $field => $info) {
        $tmp = (string) ($info['tmp_name'] ?? '');
        $filesOut[$field] = [
            'name' => (string) ($info['name'] ?? ''),
            'size' => (int) ($info['size'] ?? 0),
            'md5' => is_file($tmp) ? md5_file($tmp) : '',
            'mime' => (string) ($info['type'] ?? ''),
            'error' => (int) ($info['error'] ?? -1),
        ];
    }

    echo json_encode([
        'ok' => true,
        'method' => $method,
        'type' => $type,
        'boundary' => $boundary,
        'fields' => $_POST,
        'files' => $filesOut,
        // ★ **不能**用 `php://input` 数 part：PHP 解析 multipart 时会消费掉整个流，
        //   此刻 `php://input` 是**空串**（不是报错，就是空）。用解析结果计数 + 用
        //   `CONTENT_LENGTH` 对总字节，两条加起来才等价于"结构对且长度对"。
        'partCount' => count($_POST) + count($_FILES),
        'length' => (int) ($_SERVER['CONTENT_LENGTH'] ?? 0),
    ], JSON_UNESCAPED_UNICODE);

    return;
}

http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'not found: ' . $path], JSON_UNESCAPED_UNICODE);
