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
 *   php tools/doctor.php               # 逐项报告；有 FAIL ⇒ 退出 1，仅 WARN ⇒ 退出 0
 *   php tools/doctor.php --json        # 机器可读（agent 驱动的前提）
 *   php tools/doctor.php --strict      # WARN 也算失败（发布前自查用）
 *   php tools/doctor.php --quiet        # 只打失败项 + 汇总（塞进 gate 的输出里不噪）
 *   php tools/doctor.php --scope=gate  # **只让 affects 含 gate 的 FAIL 拦住退出码**
 *
 * ★ 为什么要有 `--scope`：`affects` 一直是「不满足时影响哪些动作」的分级字段，但
 *   判定不读它 ⇒ 一台没有 MSVC 的机器上，`gate` 的第一项 doctor 直接红，后面 27 项
 *   纯 PHP 判据一条都跑不到。CI 只能靠 `--only` 白名单绕开，而白名单不会随新增
 *   判据增长（10 项亚秒级接线判据长期进不了 CI）。
 *   与 Flutter 同形：`flutter doctor` 报全量，`flutter test` 只失败它自己关心的。
 *
 * ★ 判定方向：**宁可拦，不可漏**。`affects` 为空的 FAIL 在任何 scope 下都拦
 *   （没声明影响面 ≠ 没影响）。
 */
require __DIR__ . '/autoload.php';
require __DIR__ . '/version.php';

use Drift\Dev\Doctor\Check;
use Drift\Dev\Doctor\Environment;

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$json = in_array('--json', $args, true);
$strict = in_array('--strict', $args, true);
$quiet = in_array('--quiet', $args, true);

$scope = 'all';
foreach ($args as $arg) {
    if (str_starts_with($arg, '--scope=')) {
        $scope = substr($arg, strlen('--scope='));
    }
}
if (!in_array($scope, ['all', 'gate', 'build', 'golden'], true)) {
    fwrite(STDERR, "未知 --scope={$scope}；可选 all / gate / build / golden\n");
    exit(2);
}

/** 这条 FAIL 是否拦在当前 scope 里 */
$blocks = static function (Check $check) use ($scope): bool {
    if ($scope === 'all' || $check->affects === []) {
        return true;
    }

    return in_array($scope, $check->affects, true);
};

$checks = Environment::probe($root);

$counts = [Check::OK => 0, Check::WARN => 0, Check::FAIL => 0];
$blocking = 0;
$scopedOut = 0;
$scopeFlag = [];
foreach ($checks as $check) {
    $counts[$check->status]++;
    $blocksHere = $check->status === Check::FAIL && $blocks($check);
    $scopeFlag[$check->id] = $blocksHere;
    if ($blocksHere) {
        $blocking++;
    } elseif ($check->status === Check::FAIL) {
        $scopedOut++;
    }
}

$failed = $blocking > 0 || ($strict && $counts[Check::WARN] > 0);

if ($json) {
    $payload = [
        'ok' => !$failed,
        'strict' => $strict,
        'scope' => $scope,
        'root' => $root,
        'php' => PHP_VERSION,
        'drift_version' => driftVersion($root),
        'deps_tag' => driftDepsTag($root),
        'summary' => $counts + ['blocking_fail' => $blocking, 'scoped_out_fail' => $scopedOut],
        'checks' => array_map(
            static fn (Check $c): array => $c->toArray() + ['blocks_scope' => $scopeFlag[$c->id] ?? false],
            $checks,
        ),
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
    echo 'Drift 环境体检 · ' . driftVersion($root) . '（' . count($checks) . ' 项'
        . ($scope === 'all' ? '' : " · scope={$scope}") . "）\n\n";
}

foreach ($checks as $check) {
    if ($quiet && $check->ok()) {
        continue;
    }
    $outOfScope = $check->status === Check::FAIL && !($scopeFlag[$check->id] ?? false);
    printf(
        "  %s %s %s%s\n",
        $mark[$check->status],
        $pad($check->label, 18),
        $check->detail,
        $outOfScope ? "（不影响 {$scope}）" : '',
    );
    if ($check->fix !== null && !$check->ok()) {
        printf("      ↳ 修法：%s\n", $check->fix);
    }
    if ($check->affects !== [] && !$check->ok()) {
        printf("      ↳ 影响：%s\n", implode(' / ', $check->affects));
    }
}

echo "\n";
if ($failed) {
    $tail = $blocking > 0
        ? "{$blocking} 项必需条件不满足"
        : "{$counts[Check::WARN]} 项告警（--strict）";
    echo "✗ {$tail}（ok {$counts[Check::OK]} / warn {$counts[Check::WARN]} / fail {$counts[Check::FAIL]}"
        . ($scopedOut > 0 ? "，其中 {$scopedOut} 项不影响 {$scope}" : '') . "）\n";
    exit(1);
}

echo '✓ 环境就绪（ok ' . $counts[Check::OK] . ($counts[Check::WARN] > 0 ? " / warn {$counts[Check::WARN]}" : '')
    . ($scopedOut > 0 ? " / {$scopedOut} 项失败但不影响 {$scope}" : '') . "）\n";
exit(0);
