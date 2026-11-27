<?php
declare(strict_types=1);

/**
 * DevTools 出口的唯一 bootstrap。
 *
 * ★ 22 个出口脚本只 require 这一处，`devtools/` 整目录搬走时也只改这一处。
 * ★ 仓库内复用 `tools/autoload.php`：它直接读 composer.json 的 psr-4 表，
 *   "物理边界"仍然只有一个来源。独立成仓后改走 vendor/autoload.php 即可。
 * ★ 顺带带上 `drift_thread_id()` 的解释器替身（dev 壳页面要用）。
 */
$root = \dirname(__DIR__);
$inRepo = $root . '/tools/autoload.php';
$standalone = $root . '/vendor/autoload.php';

if (\is_file($inRepo)) {
    require $inRepo;
} elseif (\is_file($standalone)) {
    require $standalone;
} else {
    \fwrite(\STDERR, "DevTools 无法启动：找不到 {$inRepo}，也没有 {$standalone}\n");
    exit(1);
}
