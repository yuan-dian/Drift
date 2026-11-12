<?php
/**
 * 全仓"方法体量"地图：按**代码行**（不含注释/空行）排，不是按文件行数。
 *
 * 用法：php tools/method-map.php [阈值=60]
 *
 * ★ 为什么要它：DEBT 一直按"文件多少行"排债，量完发现 `Application.php` 2,149 行里
 *   只有 746 行代码（41% 是注释 + 空行）⇒ 文件行数会把"写了很多说明"当成"类太大"。
 */

$thresh = (int)($argv[1] ?? 60);
$root = \dirname(__DIR__);

$files = [];
$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src'));
foreach ($it as $f) {
    if ($f->isFile() && $f->getExtension() === 'php') {
        $files[] = $f->getPathname();
    }
}
sort($files);

$rows = [];
$totalCode = 0;
foreach ($files as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $defs = [];
    foreach ($lines as $i => $l) {
        if (preg_match('/^    (?:final |abstract |static )*(?:public|private|protected) (?:static )?function (\w+)/', $l, $m)) {
            $defs[] = ['name' => $m[1], 'start' => $i + 1];
        }
    }
    if ($defs === []) {
        continue;
    }
    for ($k = 0; $k < count($defs); $k++) {
        $defs[$k]['end'] = $k + 1 < count($defs) ? $defs[$k + 1]['start'] - 1 : count($lines);
    }
    $fileCode = 0;
    foreach ($defs as $d) {
        $code = 0;
        for ($i = $d['start'] - 1; $i < $d['end']; $i++) {
            $t = trim($lines[$i]);
            if ($t === '' || str_starts_with($t, '*') || str_starts_with($t, '/*') || str_starts_with($t, '//')) {
                continue;
            }
            $code++;
        }
        $fileCode += $code;
        if ($code > $thresh) {
            $rows[] = ['file' => str_replace($root . '/', '', $file), 'name' => $d['name'], 'code' => $code, 'all' => $d['end'] - $d['start'] + 1];
        }
    }
    $totalCode += $fileCode;
}

usort($rows, fn($a, $b) => $b['code'] <=> $a['code']);
printf("扫了 %d 个文件；方法体代码行合计 %d；代码行 > %d 的方法 %d 个\n\n", count($files), $totalCode, $thresh, count($rows));
printf("%-46s %-26s %6s %6s %6s\n", '文件', '方法', '代码', '含注释', '注释比');
foreach ($rows as $r) {
    printf("%-46s %-26s %6d %6d %5.0f%%\n", $r['file'], $r['name'], $r['code'], $r['all'],
        ($r['all'] - $r['code']) / max(1, $r['all']) * 100);
}
