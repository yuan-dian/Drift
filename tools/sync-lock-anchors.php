<?php
declare(strict_types=1);

/**
 * 把 README 里的 SHA256 锚点同步到 deps.lock.json。
 *
 * pack-deps.php 负责生成锁；README 是给人看的第二份校验值。两份不一致时
 * verify 的 version 项会红，所以自动打包后必须同步，别把这一步留给手工。
 *
 * 用法：php tools/sync-lock-anchors.php
 */

$root = dirname(__DIR__);
$lockFile = $root . '/deps.lock.json';
$readme = $root . '/README.md';

$lock = json_decode((string) @file_get_contents($lockFile), true);
$sha = is_array($lock) ? (string) ($lock['sha256'] ?? '') : '';
if (preg_match('/^[0-9a-f]{64}$/', $sha) !== 1) {
    fwrite(STDERR, "deps.lock.json 里没有合法 SHA256\n");
    exit(1);
}
if (!is_file($readme)) {
    fwrite(STDERR, "README.md 不存在\n");
    exit(1);
}

$text = (string) file_get_contents($readme);
$updated = preg_replace('/\b[0-9a-f]{64}\b/i', $sha, $text, -1, $count);
if (!is_string($updated)) {
    fwrite(STDERR, "README.md 替换失败\n");
    exit(1);
}
if ($count === 0) {
    fwrite(STDERR, "README.md 里找不到 SHA256 锚点\n");
    exit(1);
}
if ($updated !== $text) {
    file_put_contents($readme, $updated);
    printf("README.md: 同步 %d 处 SHA256 → %s\n", $count, $sha);
} else {
    printf("README.md: %d 处 SHA256 已一致\n", $count);
}
