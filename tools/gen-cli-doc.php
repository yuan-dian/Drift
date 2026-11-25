#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 把调试手册里的生成块按 Registry / schema 重写。
 * 用法：php tools/gen-cli-doc.php [--check]
 */
require __DIR__ . '/autoload.php';

use Drift\Dev\Debug\Cli\Doc;

$mode = $argv[1] ?? '--write';
if (!\in_array($mode, ['--write', '--check'], true)) {
    \fwrite(\STDERR, "用法：php tools/gen-cli-doc.php [--write|--check]\n");
    exit(2);
}

$path = \dirname(__DIR__) . '/docs/reference/cli.md';
$before = (string) \file_get_contents($path);

if ($mode === '--check') {
    $problems = Doc::problems($before);
    if ($problems === []) {
        \fwrite(\STDOUT, "调试手册与出口注册表 / 参数 schema 一致\n");
        exit(0);
    }
    foreach ($problems as $problem) {
        \fwrite(\STDERR, '  ERROR ' . $problem . "\n");
    }
    exit(1);
}

$after = Doc::upsert($before);
if ($after === $before) {
    \fwrite(\STDOUT, "调试手册生成块无需改动\n");
    exit(0);
}
\file_put_contents($path, $after);
\fwrite(\STDOUT, "调试手册生成块已更新\n");
