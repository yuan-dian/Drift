<?php
declare(strict_types=1);

/**
 * DX 退化「接入点 ↔ 判据」对账（gate 的 `dxdiag` 项）。
 *
 * ★ 为什么需要：`Diagnostics::degraded()` 一行就能加一个检测点，但「加了检测点
 *   忘了补判据」不会让任何东西变红 —— 又一条口传纪律。本脚本扫 `src/` 里的 key
 *   集合，要求每个 key 都在 `tests/` 里被 `[DX] <key>` 断言过。
 * ★ 与 PHPUnit 判据的分工：那边验「行为对不对」，这边验「有没有判据」，不同源。
 * ★ 纯 PHP 内建，不 require autoload —— 对账器不该依赖它要检查的那套代码。
 *
 * 用法：
 *   php tools/diagnostic-check.php              # 对账，exit 0/1
 *   php tools/diagnostic-check.php --selftest   # 对账器自证（合成输入，不碰源码）
 */

/** @return list<string> 目录下所有 .php 文件的内容 */
function diagnostic_php_sources(string $dir): array
{
    $out = [];
    $it = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($it as $file) {
        if ($file->isFile() && \strtolower($file->getExtension()) === 'php') {
            $out[] = (string) \file_get_contents($file->getPathname());
        }
    }

    return $out;
}

/** @return list<string> `Diagnostics::degraded('key', ...)` 里的 key（去重） */
function diagnostic_keys(array $sources): array
{
    $keys = [];
    foreach ($sources as $src) {
        if (\preg_match_all('/Diagnostics::degraded\(\s*\'([^\']+)\'/', $src, $m)) {
            foreach ($m[1] as $key) {
                $keys[$key] = true;
            }
        }
    }

    return \array_keys($keys);
}

/** @return list<string> tests 里断言过的 `[DX] <key>`（去重） */
function diagnostic_asserted(array $sources): array
{
    $asserted = [];
    foreach ($sources as $src) {
        if (\preg_match_all('/\[DX\]\s*([a-z0-9][a-z0-9\/_-]*)/', $src, $m)) {
            foreach ($m[1] as $key) {
                $asserted[$key] = true;
            }
        }
    }

    return \array_keys($asserted);
}

/** 纯函数级对账；`--selftest` 用合成输入直接验它 */
function diagnostic_reconcile(array $keys, array $asserted): array
{
    $missing = \array_values(\array_diff($keys, $asserted));
    \sort($missing);

    return $missing;
}

$selftest = \in_array('--selftest', \array_slice($argv, 1), true);
$failures = 0;

if ($selftest) {
    $cases = [
        [
            'name' => '扫描器能从源码抓出 key',
            'got' => diagnostic_keys(["<?php Diagnostics::degraded('probe/scan', 'x');"]),
            'want' => ['probe/scan'],
        ],
        [
            'name' => '接入点齐了 ⇒ 绿',
            'got' => diagnostic_reconcile(['select/unstable', 'progress/derived-chain'], ['select/unstable', 'progress/derived-chain']),
            'want' => [],
        ],
        [
            'name' => '缺一条 ⇒ 精确报那一条',
            'got' => diagnostic_reconcile(['select/unstable', 'progress/derived-chain'], ['select/unstable']),
            'want' => ['progress/derived-chain'],
        ],
        [
            'name' => '重复的接入点 key 只算一次',
            'got' => diagnostic_keys([
                "<?php Diagnostics::degraded('a', '1'); Diagnostics::degraded('a', '2');",
            ]),
            'want' => ['a'],
        ],
        [
            'name' => 'tests 里多出来的合成 key 不误报',
            'got' => diagnostic_reconcile(['select/unstable'], ['select/unstable', 'probe/a']),
            'want' => [],
        ],
    ];

    echo "[diagnostic-check --selftest] " . \count($cases) . " 条自洽判据\n";
    foreach ($cases as $case) {
        $ok = $case['got'] === $case['want'];
        echo ($ok ? '  ✓ ' : '  ✗ ') . $case['name'] . "\n";
        if (!$ok) {
            $failures++;
            \fwrite(STDERR, '    实得 ' . \json_encode($case['got']) . ' / 期望 ' . \json_encode($case['want']) . "\n");
        }
    }
    echo $failures === 0
        ? "[diagnostic-check --selftest] ✓ 全过\n"
        : "[diagnostic-check --selftest] ✗ {$failures} 条红\n";
    exit($failures === 0 ? 0 : 1);
}

$root = \dirname(__DIR__);
$keys = diagnostic_keys(diagnostic_php_sources($root . '/src'));
\sort($keys);
$asserted = diagnostic_asserted(diagnostic_php_sources($root . '/tests'));
$missing = diagnostic_reconcile($keys, $asserted);

echo '[diagnostic-check] 接入点 ' . \count($keys) . ' 个：' . \implode(', ', $keys) . "\n";
if ($missing !== []) {
    foreach ($missing as $key) {
        \fwrite(STDERR, "  - 缺判据：[DX] {$key}（在 tests/ 里断言这个前缀 + key）\n");
    }
    exit(1);
}

echo "[diagnostic-check] ✓ 每个接入点都有判据\n";
exit(0);
