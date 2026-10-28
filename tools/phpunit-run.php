<?php
declare(strict_types=1);

/**
 * `gate` 里的 PHPUnit 适配器。
 *
 * 用法：`php tools/phpunit-run.php [unit|feature]`（缺省 = 两个套件都跑）
 *
 * ★ 二进制的优先级：`vendor/bin/phpunit` 优先，退到 `tools/phpunit.phar`。
 *   两者都指向 PHPUnit 10.x，行为一致；后者是给"没装 composer 依赖"的环境兜底。
 * ★ 都没有时 **skip + exit 0**，不红：`tools/phpunit.phar` 是 5.2 MB 外部二进制、
 *   `.gitignore` 排除（与 `/cpp/` 同级）。环境状态不是判据，测试过不过才是。
 *
 * 装法（二选一）：
 *   composer install                       # vendor/bin/phpunit
 *   curl -sSL -o tools/phpunit.phar https://phar.phpunit.de/phpunit-10.phar
 *
 * @api 闸门：`tools/gate.php`
 */

$root = \dirname(__DIR__);
$suite = $argv[1] ?? '';

$bin = null;
foreach ([$root . '/vendor/bin/phpunit', $root . '/tools/phpunit.phar'] as $candidate) {
    if (\is_file($candidate)) {
        $bin = $candidate;
        break;
    }
}

if ($bin === null) {
    echo "  skip  PHPUnit 未安装（composer install，或见本文件头注）\n";
    exit(0);
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
