#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 双模语义等价 · 另一半：**解释器 ↔ dev 壳（ZendVM 动态加载）**。
 *
 * ★ 与 `aot-equivalence.php` 是同一枚硬币的两面：那边验「解释器 ↔ 全 AOT」，
 *   这边验「解释器 ↔ dev 壳」。两侧共用 `Fingerprint::snapshot()` 作**唯一** expected，
 *   不落盘、不建第二套基线 —— 一套 27 页 × 2 通道的判据同时守两种产物。
 * ★ 为什么不用 `inspect.php --check`：它的快照对"合法结构改动"极敏感，头部
 *   明写不进 gate；而 Fingerprint 逐值比的是布局与绘制命令，正是 dev 壳可能
 *   悄悄改变的那部分语义（`php.ini` / opcache / 加载顺序 / AOT 化的桥接回调类）。
 *
 * ```bash
 * php tools/dual-mode-equivalence.php            # 三态：0 等价 / 1 不等价 / 2 无法判定
 * php tools/dual-mode-equivalence.php --selftest # 判据正反例
 * php tools/dual-mode-equivalence.php --quiet
 * ```
 *
 * ★ dev.exe 是本地编译产物、不进 git ⇒ 本量具属 T2（发版前 / 动过 dev 壳时跑），
 *   **不进 gate.php**：CI 上没有产物，进闸门要么假绿要么恒红。
 * ★ 无头入口不会开窗 ⇒ 不需要关窗；仍设 30s 超时主动 terminate，不把进程甩给用户。
 *
 * @internal 量具专用
 */

require __DIR__ . '/autoload.php';

use Drift\Dev\Bench\Fingerprint;

/** 唯一 expected：解释器的 27 页两通道指纹，规范行、不落盘。 */
function expectedRows(): array
{
    $snap = Fingerprint::snapshot();
    $rows = [];
    foreach (Fingerprint::CHANNELS as $ch) {
        foreach ($snap[$ch] as $r) {
            $rows[] = Fingerprint::row($ch, $r);
        }
    }

    return $rows;
}

function shorten(string $row): string
{
    $flat = str_replace("\t", ' ', $row);

    return strlen($flat) > 110 ? substr($flat, 0, 110) . '…' : $flat;
}

/** @return list<string> 逐行差异，含行号与两侧值 */
function diffRows(array $expected, array $actual): array
{
    $out = [];
    $n = max(count($expected), count($actual));
    for ($i = 0; $i < $n; $i++) {
        $a = $expected[$i] ?? null;
        $b = $actual[$i] ?? null;
        if ($a === $b) {
            continue;
        }
        if ($b === null) {
            $out[] = sprintf('第 %d 行：dev 侧缺少 %s', $i + 1, shorten((string) $a));
        } elseif ($a === null) {
            $out[] = sprintf('第 %d 行：dev 侧多出 %s', $i + 1, shorten((string) $b));
        } else {
            $out[] = sprintf("第 %d 行：\n    解释器 %s\n    dev 壳  %s", $i + 1, shorten($a), shorten($b));
        }
    }

    return $out;
}

/** @return array{raw: string, code: int, timedOut: bool}|null */
function runDev(string $exe, string $entry, string $root): ?array
{
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    // php8ts.dll 在 PHP 发行版目录；从任意 shell 启动时它未必在 PATH（同 drift-dev.php）。
    $env['PATH'] = dirname(PHP_BINARY) . ';' . (string) ($env['PATH'] ?? '');
    $env['DRIFT_DEV_ENTRY'] = $entry;
    unset($env['DRIFT_DEV_APP'], $env['PROBE_SECTION'], $env['FP_PAGE'], $env['FP_CHANNEL']);

    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open([$exe], $desc, $pipes, $root, $env);
    if (!is_resource($proc)) {
        return null;
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $out = '';
    $err = '';
    $deadline = microtime(true) + 30.0;
    $timedOut = false;
    while (true) {
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        // ★ 以管道 EOF 为准：Windows 上 proc_get_status()['running'] 对 AOT 进程误报。
        if (feof($pipes[1]) && feof($pipes[2])) {
            break;
        }
        if (microtime(true) > $deadline) {
            $timedOut = true;
            proc_terminate($proc);
            break;
        }
        usleep(20000);
    }
    $out .= (string) stream_get_contents($pipes[1]);
    $err .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    // 超时路径不调 proc_close：它可能等进程消失才返回（本仓踩过）。
    $code = $timedOut ? -1 : proc_close($proc);

    return ['raw' => $out . $err, 'code' => $code, 'timedOut' => $timedOut];
}

/** 判据双向自检：一组"不该响" + 四组"该响"。 */
function selftest(): int
{
    $hash = str_repeat('a', 32);
    $base = [
        "geom\tAlignPage\t{$hash}\t1\t2\t3",
        "geom\tAspectPage\t{$hash}\t4\t5\t6",
        "paint\tAlignPage\t{$hash}\t7\t8\t9",
    ];
    $edited = $base;
    $edited[1] = str_replace($hash, str_repeat('b', 32), $edited[1]);
    $swapped = [$base[1], $base[0], $base[2]];

    $cases = [
        '同序相同 ⇒ 不响' => [$base, $base, 0, ''],
        '改一个哈希 ⇒ 响 1 条' => [$base, $edited, 1, '第 2 行'],
        '少一行 ⇒ 响且报缺少' => [$base, array_slice($base, 0, 2), 1, '缺少'],
        '多一行 ⇒ 响且报多出' => [$base, [...$base, 'geom\tExtra\t' . $hash . '\t0\t0\t0'], 1, '多出'],
        '换序 ⇒ 响 2 条' => [$base, $swapped, 2, '第 1 行'],
    ];

    $fail = 0;
    foreach ($cases as $label => [$want, $got, $count, $needle]) {
        $diff = diffRows($want, $got);
        $ok = count($diff) === $count && ($needle === '' || str_contains(implode("\n", $diff), $needle));
        printf("  %s %-24s 差异 %d 条\n", $ok ? 'ok  ' : 'FAIL', $label, count($diff));
        if (!$ok) {
            $fail++;
        }
    }
    printf("--selftest %s\n", $fail === 0 ? 'PASS 5/5' : "FAIL {$fail}/5");

    return $fail === 0 ? 0 : 1;
}

// ───────────────────────── 主流程 ─────────────────────────
$root = dirname(__DIR__);
$exe = $root . '/dev.exe';
$entry = $root . '/tools/dual-mode-fingerprint-entry.php';
$quiet = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--quiet') {
        $quiet = true;
    } elseif ($arg === '--selftest') {
        exit(selftest());
    } elseif (str_starts_with($arg, '--exe=')) {
        $exe = substr($arg, 6);
    } elseif (str_starts_with($arg, '--entry=')) {
        $entry = substr($arg, 8); // 自定义无头入口 / 变异牙检用
    } else {
        fwrite(STDERR, "未知参数：{$arg}\n");
        exit(2);
    }
}

$checks = [];
$unknown = Fingerprint::unknownTypes();
$checks[] = ['E① 槽类型全部已知（指纹没静默漏命令类型）', $unknown === []];

if (!is_file($exe)) {
    if (!$quiet) {
        echo "=== 双模语义等价：无法判定（未构建 dev 壳）===\n";
        echo "找不到 {$exe}\n先构建：php tools/drift-build.php dev\n";
        foreach ($checks as [$tag, $ok]) {
            echo ($ok ? '  ok   ' : '  FAIL ') . $tag . "\n";
        }
    }
    echo $quiet ? "SKIP(no dev.exe)\n" : "--- 等价性未验 ---\n";
    exit(2);
}

$expected = expectedRows();
$checks[] = ['E② 解释器侧 27 页两通道有内容', count($expected) > 0];

$run = runDev($exe, $entry, $root);
$hardFail = $run === null || $run['timedOut'] || $run['code'] !== 0
    || str_contains($run['raw'], 'Fatal error') || str_contains($run['raw'], 'Parse error');
$checks[] = ['D① dev 壳无头入口退出码 0 且无 Fatal', !$hardFail];

if ($hardFail) {
    if (!$quiet) {
        echo "=== 双模语义等价：无法判定（dev 壳没跑出结果）===\n";
        echo '退出码 ' . ($run['code'] ?? 'null') . "\n";
        echo substr((string) ($run['raw'] ?? ''), 0, 800) . "\n";
    }
    echo $quiet ? "SKIP(dev shell failed)\n" : '';
    exit(2);
}

$parsed = Fingerprint::parse((string) $run['raw']);
$actual = [];
foreach (Fingerprint::CHANNELS as $ch) {
    foreach ($parsed[$ch] as $r) {
        $actual[] = Fingerprint::row($ch, $r);
    }
}
$checks[] = ['D② dev 侧两通道都吐出了规范行', $actual !== []];
$expectByCh = array_fill_keys(Fingerprint::CHANNELS, 0);
foreach ($expected as $row) {
    $ch = explode("\t", $row)[0];
    if (isset($expectByCh[$ch])) {
        $expectByCh[$ch]++;
    }
}
foreach (Fingerprint::CHANNELS as $ch) {
    $checks[] = ["D③ {$ch} 通道行数一致", count($parsed[$ch]) === $expectByCh[$ch]];
}

$diff = diffRows($expected, $actual);
$checks[] = ['D④ 27 页 × 2 通道逐行相同', $diff === []];

$fail = 0;
foreach ($checks as [$tag, $ok]) {
    if (!$ok) {
        $fail++;
    }
    if (!$quiet) {
        echo ($ok ? '  ok   ' : '  FAIL ') . $tag . "\n";
    }
}
if (!$quiet) {
    printf("=== 双模语义等价（解释器 ↔ dev 壳）：%s，%d 行 ===\n", $fail === 0 ? 'PASS' : 'FAIL', count($expected));
    foreach (array_slice($diff, 0, 8) as $line) {
        echo '  · ' . $line . "\n";
    }
    if (count($diff) > 8) {
        echo '  …还有 ' . (count($diff) - 8) . " 条\n";
    }
} else {
    echo $fail === 0 ? "PASS\n" : "FAIL {$fail}\n";
}

exit($fail > 0 ? 1 : 0);
