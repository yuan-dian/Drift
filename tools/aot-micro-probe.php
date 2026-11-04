#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * AOT 微基准阶梯 —— **解释器这一侧**。
 *
 * ★ 另一半在 `probes/aot_micro/`（tpc `-m bin`）。两侧跑的是**同一个**
 *   `Drift\Dev\Bench\AotMicro`：这一份与那一份的差别只有"谁来执行"，
 *   编排、n、树形状、判据全部共用。这是"复量"的前提。
 *
 * ★★ 拿两张表**逐级相除**才是结论。绝对值不要跨机比，也不要跨运行比。
 *
 * 用法：
 *   php tools/aot-micro-probe.php                 # 默认 base=200000
 *   php tools/aot-micro-probe.php --base=400000   # 放大（低速机器上更稳）
 *   php tools/aot-micro-probe.php --json          # 机器可读
 *
 * @internal 量具专用
 */

require __DIR__ . '/autoload.php';

use Drift\Dev\Bench\AotMicro;

$base = 200000;
$json = false;
$reps = 3;

foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $json = true;
    } elseif (\str_starts_with($arg, '--base=')) {
        $base = (int) \substr($arg, 7);
    } elseif (\str_starts_with($arg, '--reps=')) {
        $reps = \max(1, (int) \substr($arg, 7));
    } elseif ($arg === '--help' || $arg === '-h') {
        echo "用法: php tools/aot-micro-probe.php [--base=N] [--reps=N] [--json]\n";
        exit(0);
    }
}

$rows = AotMicro::run($base, $reps);
$checks = AotMicro::check($rows);

if ($json) {
    echo \json_encode(
        ['side' => 'interpreter', 'php' => \PHP_VERSION, 'base' => $base, 'rows' => $rows, 'checks' => $checks],
        \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE
    ) . "\n";
    exit(AotMicro::failCount($checks) > 0 ? 1 : 0);
}

AotMicro::report($rows, $checks, '解释器侧（opcache_cli=' . (\ini_get('opcache.enable_cli') ? 'On' : 'Off') . '）', $base);

exit(AotMicro::failCount($checks) > 0 ? 1 : 0);
