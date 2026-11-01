<?php
declare(strict_types=1);

/**
 * `doctor` —— 环境体检（等价 `flutter doctor`）。
 *
 * 为什么需要：本仓的构建前提（PHPRC / PHPX / cl.exe / SDK 三件套 / tpc 源码树）此前
 * 只活在 `drift-build.php` 的 `fail()` 分支和人的记忆里，且 PITFALLS「PHP / tpc 硬坑」
 * 整段都是**不报错、只是没效果**的类型 —— 只能靠"构建失败→猜→改"逐个试。
 *
 * 判据只有一份：`Drift\Dev\Doctor\Environment::probe()`；`drift-build` 消费同类的
 * `prepare()` 定位逻辑 ⇒ 体检结论与构建时真正用的是同一套路径推导。
 *
 * 用法：
 *   php tools/doctor.php            # 逐项报告；有 FAIL ⇒ 退出 1，仅 WARN ⇒ 退出 0
 *   php tools/doctor.php --json     # 机器可读（agent 驱动的前提）
 *   php tools/doctor.php --strict   # WARN 也算失败（发布前自查用）
 *   php tools/doctor.php --quiet    # 只打失败项 + 汇总（塞进 gate 的输出里不噪）
 */
require __DIR__ . '/autoload.php';

use Drift\Dev\Doctor\Check;
use Drift\Dev\Doctor\Environment;

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$json = in_array('--json', $args, true);
$strict = in_array('--strict', $args, true);
$quiet = in_array('--quiet', $args, true);

$checks = Environment::probe($root);

$counts = [Check::OK => 0, Check::WARN => 0, Check::FAIL => 0];
foreach ($checks as $check) {
    $counts[$check->status]++;
}

$failed = $counts[Check::FAIL] > 0 || ($strict && $counts[Check::WARN] > 0);

if ($json) {
    $payload = [
        'ok' => !$failed,
        'strict' => $strict,
        'root' => $root,
        'php' => PHP_VERSION,
        'summary' => $counts,
        'checks' => array_map(static fn (Check $c): array => $c->toArray(), $checks),
    ];
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), "\n";
    exit($failed ? 1 : 0);
}

/** 显示宽度补齐（中文按 2 列；无 mbstring 时退化成字节数，只是对不齐不难看） */
$pad = static function (string $s, int $width): string {
    $len = function_exists('mb_strwidth') ? mb_strwidth($s, 'UTF-8') : strlen($s);

    return $s . str_repeat(' ', max(0, $width - $len));
};

$mark = [Check::OK => '✓', Check::WARN => '⚠', Check::FAIL => '✗'];

if (!$quiet) {
    echo 'Drift 环境体检（' . count($checks) . " 项）\n\n";
}

foreach ($checks as $check) {
    if ($quiet && $check->ok()) {
        continue;
    }
    printf("  %s %s %s\n", $mark[$check->status], $pad($check->label, 18), $check->detail);
    if ($check->fix !== null && !$check->ok()) {
        printf("      ↳ 修法：%s\n", $check->fix);
    }
    if ($check->affects !== [] && !$check->ok()) {
        printf("      ↳ 影响：%s\n", implode(' / ', $check->affects));
    }
}

echo "\n";
if ($failed) {
    $tail = $counts[Check::FAIL] > 0
        ? "{$counts[Check::FAIL]} 项必需条件不满足"
        : "{$counts[Check::WARN]} 项告警（--strict）";
    echo "✗ {$tail}（ok {$counts[Check::OK]} / warn {$counts[Check::WARN]} / fail {$counts[Check::FAIL]}）\n";
    exit(1);
}

echo "✓ 环境就绪（ok {$counts[Check::OK]}" . ($counts[Check::WARN] > 0 ? " / warn {$counts[Check::WARN]}" : '') . "）\n";
exit(0);
