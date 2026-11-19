<?php
declare(strict_types=1);

/**
 * 把 README 里的 SHA256 锚点同步到 deps.lock.json。
 *
 * pack-deps.php 负责生成锁；README 是给人看的第二份校验值。两份不一致时
 * verify 的 version 项会红，所以自动打包后必须同步，别把这一步留给手工。
 * deps-v2 起 README 按平台顺序列出全部哈希；deps-v1 仍是单值。
 *
 * 用法：php tools/sync-lock-anchors.php
 */

use Drift\Dev\Deps\Platforms;

$root = dirname(__DIR__);
$lockFile = $root . '/deps.lock.json';
$readme = $root . '/README.md';

require __DIR__ . '/Deps/Platforms.php';

$lock = json_decode((string) @file_get_contents($lockFile), true);
if (!is_array($lock)) {
    fwrite(STDERR, "deps.lock.json 解析失败\n");
    exit(1);
}

$expected = [];
if (Platforms::multiPlatformTag((string) ($lock['depsTag'] ?? ''))) {
    foreach (Platforms::published() as $p) {
        $sha = (string) ($lock['platforms'][$p['id']]['sha256'] ?? '');
        if (preg_match('/^[0-9a-f]{64}$/', $sha) !== 1) {
            fwrite(STDERR, "deps.lock.json 的 {$p['id']} 没有合法 SHA256\n");
            exit(1);
        }
        $expected[] = $sha;
    }
} else {
    $sha = (string) ($lock['sha256'] ?? '');
    if (preg_match('/^[0-9a-f]{64}$/', $sha) !== 1) {
        fwrite(STDERR, "deps.lock.json 里没有合法 SHA256\n");
        exit(1);
    }
    $expected[] = $sha;
}
if (!is_file($readme)) {
    fwrite(STDERR, "README.md 不存在\n");
    exit(1);
}

$text = (string) file_get_contents($readme);
$i = 0;
$updated = preg_replace_callback('/\b[0-9a-f]{64}\b/i', static function () use (&$i, $expected): string {
    $v = $expected[$i] ?? null;
    $i++;
    return $v ?? '';
}, $text, -1, $count);
if (!is_string($updated)) {
    fwrite(STDERR, "README.md 替换失败\n");
    exit(1);
}
if ($count !== count($expected)) {
    fwrite(STDERR, 'README.md 需要 ' . count($expected) . ' 个 SHA256 锚点（按平台顺序），实际找到 ' . $count . " 个\n");
    exit(1);
}
if ($updated !== $text) {
    file_put_contents($readme, $updated);
    $ids = array_map(static fn (array $p): string => $p['id'], Platforms::published());
    printf("README.md: 同步 %d 个 SHA256（%s）\n", $count, implode('、', $ids));
} else {
    printf("README.md: %d 个 SHA256 已一致\n", $count);
}
