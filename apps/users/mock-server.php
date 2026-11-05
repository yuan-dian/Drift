<?php
declare(strict_types=1);

// users 的 mock 后端：`php -S 127.0.0.1:8099 apps/users/mock-server.php`
//
// ★ `FetchUsersJob` 注释里写的就是本文件（2026-11-05 前它一直不存在 ——
//   文档假承诺：应用内列表因此恒"加载失败"）。故意慢 1.2s：
//   `AsyncValue::loading()` 那一态要真的对用户可见（§11.5）。

if (!str_contains($_SERVER['REQUEST_URI'] ?? '', '/api/users')) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo '{"ok":false}';

    return;
}

usleep(1_200_000);

header('Content-Type: application/json');
$items = [];
for ($i = 1; $i <= 12; $i++) {
    $items[] = ['id' => $i, 'name' => "用户 {$i}", 'email' => "user{$i}@example.com"];
}
echo json_encode(['ok' => true, 'items' => $items]);
