<?php
declare(strict_types=1);

/**
 * `gate` 里的 unit 项 —— PHPUnit 的适配器。
 *
 * ★ 为什么 phar 不在时**跳过而不是失败**：`tools/phpunit.phar` 是 5.2 MB 外部
 *   二进制、`.gitignore` 排除（与 `/cpp/` 同级）。新克隆的仓库没它，若这里
 *   直接红，每个新人第一次 `gate` 都会看到一条与自己无关的失败 —— 那是噪音，
 *   不是判据。装没装 phar 是环境状态，测试过不过才是判据。
 *
 * 下载：`curl -sSL -o tools/phpunit.phar https://phar.phpunit.de/phpunit-10.phar`
 *
 * @api 闸门：`tools/gate.php`
 */

$phar = __DIR__ . '/phpunit.phar';

if (!is_file($phar)) {
    echo "  skip  tools/phpunit.phar 不存在（装法见本文件头注）\n";
    echo "  当前 unit 套件：0 个测试类\n";
    exit(0);
}

$root = \dirname(__DIR__);

$command = \escapeshellarg(PHP_BINARY)
    . ' ' . \escapeshellarg($phar)
    . ' -c ' . \escapeshellarg($root . '/phpunit.xml')
    . ' --no-coverage';

\passthru($command, $code);
exit((int) $code);
