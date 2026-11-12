<?php
/**
 * 通用「方法体量尺子」—— 同一个量具，两种看法。
 *
 * 用法：
 *   php tools/method-weight.php <php文件或目录> [阈值]
 *   php tools/method-weight.php src/App/Application.php        # 单文件分布（默认阈值 40）
 *   php tools/method-weight.php src 60                        # 全仓按阈值排序（默认阈值 60）
 *
 * ★ 2026-11-12 合并自 `method-map.php` + `method-weight.php`：两份各写了一遍同一个
 *   「按 4 空格缩进的函数声明切段、数代码/注释/空行」的解析器，改一次判据要改两处。
 *   现在解析只有 `scanMethods()` 一份；两种输出的**列格式与全部数字**与合并前逐字一致
 *   （合并前后对同一个文件 / 整个 `src/` 跑过 diff），只有目录模式的路径分隔符统一成
 *   正斜杠 —— 旧 `method-map` 在 Windows 上打的是反斜杠。
 *
 * ★ 为什么按代码行而不是文件行数排债：DEBT 一直按「文件多少行」排，量完发现
 *   `Application.php` 2,149 行里只有 746 行代码（41% 是注释 + 空行）
 *   ⇒ 文件行数会把「写了很多说明」当成「类太大」。
 *
 * @internal 一次性调研尺子，不进 gate；结论已写进文档，需要复量时手动跑。
 */

/**
 * 切出文件里每个方法的代码 / 注释 / 空行计数。
 *
 * ★ 与合并前两份脚本逐字相同：只认 4 空格缩进的 `public/private/protected function`，
 *   一段到下一个声明前一行（末段到文件尾）。否定结论要自证扫到了东西 ⇒ 空则抛。
 *
 * @return list<array{n: string, all: int, code: int, cmt: int, blank: int}>
 */
function scanMethods(string $file): array
{
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    $defs = [];
    foreach ($lines as $i => $l) {
        if (preg_match('/^    (?:final |abstract |static )*(?:public|private|protected) (?:static )?function (\w+)/', $l, $m)) {
            $defs[] = ['name' => $m[1], 'start' => $i + 1];
        }
    }
    if ($defs === []) {
        return [];
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
        $rows[] = [
            'n' => $d['name'],
            'all' => $d['end'] - $d['start'] + 1,
            'code' => $code,
            'cmt' => $cmt,
            'blank' => $blank,
        ];
    }

    return $rows;
}

/** @return list<string> 目录下所有 .php，排序后返回（与旧 method-map 一致） */
function phpFilesUnder(string $dir): array
{
    $files = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $files[] = $f->getPathname();
        }
    }
    sort($files);

    return $files;
}

/** 单文件看法：分布 + 超阈清单 */
function reportFile(string $file, array $rows, int $thresh): void
{
    $codeSum = array_sum(array_column($rows, 'code'));
    $allSum = array_sum(array_column($rows, 'all'));
    printf("%s：%d 行 · %d 个方法\n", $file, count(file($file, FILE_IGNORE_NEW_LINES)), count($rows));
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
}

/** 目录看法：全仓按代码行倒序排债 */
function reportTree(string $dir, array $files, int $thresh, string $root): void
{
    $rows = [];
    $totalCode = 0;
    foreach ($files as $file) {
        $methods = scanMethods($file);
        if ($methods === []) {
            continue;
        }
        $fileCode = 0;
        foreach ($methods as $m) {
            $fileCode += $m['code'];
            if ($m['code'] > $thresh) {
                $rows[] = [
                    'file' => str_replace($root . '/', '', str_replace('\\', '/', $file)),
                    'name' => $m['n'],
                    'code' => $m['code'],
                    'all' => $m['all'],
                ];
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
}

$path = $argv[1] ?? null;
if ($path === null || !file_exists($path)) {
    fwrite(STDERR, "用法: php tools/method-weight.php <php文件或目录> [阈值=40|目录 60]\n");
    exit(2);
}

$isDir = is_dir($path);
$thresh = (int)($argv[2] ?? ($isDir ? 60 : 40));
// `reportTree()` 把路径统一成正斜杠，前缀也得同口径；否则传绝对路径时会失配，
// 目录模式就会打印绝对路径而不是 `src/...`（旧 method-map 的口径）。
$root = str_replace('\\', '/', \dirname(__DIR__));

if (!$isDir) {
    $rows = scanMethods($path);
    if ($rows === []) {
        fwrite(STDERR, "没扫到任何方法 —— 否定结论必须自证扫到了东西\n");
        exit(2);
    }
    reportFile($path, $rows, $thresh);
    exit(0);
}

$files = phpFilesUnder($path);
if ($files === []) {
    fwrite(STDERR, "目录下没有 .php 文件 —— 否定结论必须自证扫到了东西\n");
    exit(2);
}
reportTree($path, $files, $thresh, $root);
exit(0);
