#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 把两侧的阶梯表**逐级相除** —— 归因的唯一入口。
 *
 * ★ 为什么需要单独一个工具：`AotMicro` 只负责"量"，比值是"两侧同一级相除"，
 *   而两侧是**两个进程、两个运行时、不可能同一时刻**跑的。所以：
 *   每一侧跑多次，按级取 **min**，再相除。min 抗噪（抖动只会加时间）。
 *
 * ★★ 判据只有一条、也只需要一条：**两侧同级的 `n` 必须相同**。
 *   不同就说明有人改了 `levels()` 却只重跑了一边 —— 那样相除得到的是垃圾。
 *
 * 用法：
 *   php tools/aot-micro-compare.php --interp=a.txt,b.txt --aot=c.txt,d.txt
 *   两边都可以给多个文件（同侧多次运行），重复的级取 min。
 *
 * @internal 量具专用
 */

/**
 * 解析一侧的一次输出。
 *
 * @return array<string, array{n: int, nsPerOp: float, minUs: float}>
 */
function parseSide(string $file): array
{
    $text = @\file_get_contents($file);
    if ($text === false) {
        \fwrite(\STDERR, '读不到文件：' . $file . "\n");
        exit(2);
    }

    $out = [];
    foreach (\explode("\n", $text) as $line) {
        // `L00 fold            800000       0.00      0.00    0.00    1.50       800000`
        if (\preg_match('/^(L\d{2} \w+)\s+(\d+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+(\d+)\s*$/', $line, $m) !== 1) {
            continue;
        }
        $out[$m[1]] = [
            'n' => (int) $m[2],
            'minUs' => (float) $m[3],
            'nsPerOp' => (float) $m[4],
        ];
    }

    if ($out === []) {
        \fwrite(\STDERR, '这个文件里没解析出任何一级（格式不对？）：' . $file . "\n");
        exit(2);
    }

    return $out;
}

/**
 * 同侧多次运行 ⇒ 每级取 min。
 *
 * @param list<string> $files
 *
 * @return array<string, array{n: int, nsPerOp: float}>
 */
function mergeSide(array $files): array
{
    $merged = [];
    foreach ($files as $f) {
        foreach (parseSide($f) as $label => $row) {
            if (!isset($merged[$label])) {
                $merged[$label] = $row;
                continue;
            }
            if ($merged[$label]['n'] !== $row['n']) {
                \fwrite(\STDERR, '同一侧多次运行的 n 不一致（' . $label . '）：'
                    . $merged[$label]['n'] . ' vs ' . $row['n'] . "\n");
                exit(2);
            }
            $merged[$label]['nsPerOp'] = \min($merged[$label]['nsPerOp'], $row['nsPerOp']);
        }
    }

    return $merged;
}

$interp = [];
$aot = [];
foreach (\array_slice($argv, 1) as $arg) {
    if (\str_starts_with($arg, '--interp=')) {
        $interp = \explode(',', \substr($arg, 9));
    } elseif (\str_starts_with($arg, '--aot=')) {
        $aot = \explode(',', \substr($arg, 6));
    } else {
        \fwrite(\STDERR, "用法: php tools/aot-micro-compare.php --interp=a.txt,b.txt --aot=c.txt,d.txt\n");
        exit(2);
    }
}

if ($interp === [] || $aot === []) {
    \fwrite(\STDERR, "两侧都要给（--interp= 与 --aot=）\n");
    exit(2);
}

$i = mergeSide($interp);
$a = mergeSide($aot);

// 判据：同级的 n 必须相同（同一件事），级名集合必须相同（同一张表）。
$bad = [];
foreach ($i as $label => $row) {
    if (!isset($a[$label])) {
        $bad[] = $label . '（AOT 侧缺失）';
        continue;
    }
    if ($a[$label]['n'] !== $row['n']) {
        $bad[] = $label . '（n ' . $row['n'] . ' vs ' . $a[$label]['n'] . '）';
    }
}
foreach ($a as $label => $row) {
    if (!isset($i[$label])) {
        $bad[] = $label . '（解释器侧缺失）';
    }
}

echo "=== 阶梯逐级相除：AOT ÷ 解释器 ===\n";
echo '级数 ' . \count($i) . '  比值 <1 = AOT 更快' . "\n\n";
\printf("%-16s %12s %12s %10s  %s\n", 'level', 'interp ns/op', 'aot ns/op', 'ratio', '判定');
echo \str_repeat('-', 74) . "\n";

$speedups = 0;
$slows = 0;
foreach ($i as $label => $row) {
    $an = $a[$label]['nsPerOp'] ?? 0.0;
    // 解释器侧 0 只在"太短没测到"时出现，别拿它做分母
    $ratio = $row['nsPerOp'] > 0 ? $an / $row['nsPerOp'] : 0.0;

    if ($ratio < 0.9) {
        $verdict = 'AOT 快 ' . \round(1 / \max($ratio, 0.001), 1) . '×';
        $speedups++;
    } elseif ($ratio > 1.1) {
        $verdict = '**AOT 慢 ' . \round($ratio, 2) . '×**';
        $slows++;
    } else {
        $verdict = '持平';
    }

    \printf(
        "%-16s %12.2f %12.2f %10.2f  %s\n",
        $label,
        $row['nsPerOp'],
        $an,
        $ratio,
        $verdict
    );
}

echo "\n" . '更快 ' . $speedups . ' 级 / 更慢 ' . $slows . ' 级 / 其余持平' . "\n";

if ($bad !== []) {
    echo "\n  FAIL 两侧不可比：" . \implode(' ', $bad) . "\n";
    exit(1);
}

echo "  ok   两侧同级 n 完全一致（" . \count($i) . " 级）\n";
exit(0);
