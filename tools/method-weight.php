<?php
/**
 * 通用"方法体量尺子"：按类统计每个方法的代码/注释/空行，并给出分布。
 * 用法：php tools/method-weight.php src/App/Application.php [阈值]
 */

$file = $argv[1] ?? null;
if ($file === null || !is_file($file)) {
    fwrite(STDERR, "用法: php tools/method-weight.php <php文件> [阈值=40]\n");
    exit(2);
}
$thresh = (int)($argv[2] ?? 40);

$lines = file($file, FILE_IGNORE_NEW_LINES);
$defs = [];
foreach ($lines as $i => $l) {
    if (preg_match('/^    (?:final |abstract |static )*(?:public|private|protected) (?:static )?function (\w+)/', $l, $m)) {
        $defs[] = ['name' => $m[1], 'start' => $i + 1];
    }
}
if ($defs === []) {
    fwrite(STDERR, "没扫到任何方法 —— 否定结论必须自证扫到了东西\n");
    exit(2);
}
for ($k = 0; $k < count($defs); $k++) {
    $defs[$k]['end'] = $k + 1 < count($defs) ? $defs[$k + 1]['start'] - 1 : count($lines);
}

$rows = [];
foreach ($defs as $d) {
    $code = 0; $cmt = 0; $blank = 0;
    for ($i = $d['start'] - 1; $i < $d['end']; $i++) {
        $t = trim($lines[$i]);
        if ($t === '') {
            $blank++;
        } elseif (str_starts_with($t, '*') || str_starts_with($t, '/*') || str_starts_with($t, '//')) {
            $cmt++;
        } else {
            $code++;
        }
    }
    $rows[] = ['n' => $d['name'], 'all' => $d['end'] - $d['start'] + 1, 'code' => $code, 'cmt' => $cmt, 'blank' => $blank];
}

$codeSum = array_sum(array_column($rows, 'code'));
$allSum = array_sum(array_column($rows, 'all'));
printf("%s：%d 行 · %d 个方法\n", $file, count($lines), count($rows));
printf("方法体合计 %d 行：代码 %d / 注释 %d / 空 %d\n", $allSum, $codeSum,
    array_sum(array_column($rows, 'cmt')), array_sum(array_column($rows, 'blank')));
printf("代码行均值 %.1f，中位 %.1f\n", $codeSum / count($rows),
    (function (array $v): float {
        sort($v);
        $n = count($v);
        return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
    })(array_column($rows, 'code')));

$over = array_filter($rows, fn($r) => $r['code'] > $thresh);
printf("代码行 > %d 的方法：%d 个，合计 %d 行（占全部代码行 %d%%）\n",
    $thresh, count($over), array_sum(array_column($over, 'code')),
    (int)round(array_sum(array_column($over, 'code')) / max(1, $codeSum) * 100));
usort($rows, fn($a, $b) => $b['code'] <=> $a['code']);
foreach ($rows as $r) {
    if ($r['code'] > $thresh) {
        printf("  %-28s 代码 %3d / 注释 %3d / 空 %2d（共 %d）\n", $r['n'], $r['code'], $r['cmt'], $r['blank'], $r['all']);
    }
}
$under = array_filter($rows, fn($r) => $r['code'] <= $thresh);
printf("代码行 <= %d 的方法：%d 个（%.0f%%），合计 %d 行\n", $thresh, count($under),
    count($under) / count($rows) * 100, array_sum(array_column($under, 'code')));
