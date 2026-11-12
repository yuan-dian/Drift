<?php
declare(strict_types=1);

/**
 * `gate` 里的 PHPUnit 适配器。
 *
 * 用法：`php tools/phpunit-run.php [unit|feature]`（缺省 = 两个套件都跑）
 *
 * ★ 只有**一个**入口：composer 装出来的 `vendor/bin/phpunit`
 *   （`composer install` 读入库的 `composer.lock`，pin 到 PHPUnit 10.5.66）。
 *   2026-11-12 删掉 `tools/phpunit.phar` 兜底：5.2 MB 外部二进制不入库、
 *   版本不 pin，且与上游 `swoole/typephp` 的 `windows-build.yml` 口径不一致。
 * ★ 没装 ⇒ **exit 2（没测到）**，由 `gate` 的 `$may_skip` 记成 `skip`。
 *   ★★ 绝不 exit 0 —— 那会让 gate 把"根本没跑"记成"通过"（2026-11-12 查出
 *   CI 上 `unit` 一直是这种假绿）。环境状态不是判据，但**必须看得见**。
 *
 * 装法：`composer install`（CI 里由 setup-php 的 `tools: composer:v2` 提供）。
 *
 * @api 闸门：`tools/gate.php`
 */

$root = \dirname(__DIR__);
$suite = $argv[1] ?? '';

$bin = $root . '/vendor/bin/phpunit';

if (!\is_file($bin)) {
    echo "  skip  PHPUnit 未安装（composer install，或见本文件头注）\n";
    exit(2);
}

$command = \escapeshellarg(PHP_BINARY)
    . ' ' . \escapeshellarg($bin)
    . ' -c ' . \escapeshellarg($root . '/phpunit.xml')
    . ' --no-coverage';

if ($suite !== '') {
    $command .= ' --testsuite=' . \escapeshellarg($suite);
}

\passthru($command, $code);
exit((int) $code);
