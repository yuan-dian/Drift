#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `模块+RVA` → 函数名（读链接期 `.map`，不依赖 pdb / 调试器）。
 *
 * ## 为什么需要它
 *
 * `cpp-src/win32/crash_report.cc` 装的崩溃捕获只能给到 **模块名 + RVA**（本机没有
 * cdb / windbg，也没有 pdb）。没有这一步，"有日志"和"能定位"之间还差一环 ——
 * `watermark.exe+0x1a2b3c` 对人没有意义。
 *
 * 而 `build/base.yml` 里的 `/MAP` 顺手就能生成对照表，且**纯文本、可 grep**。
 *
 * ## 用法
 *
 * ```bash
 * # 单个 RVA（十六进制或十进制都收）
 * php tools/rva2sym.php watermark.map 0x1a2b3c
 *
 * # 直接翻译一份崩溃日志（把里面所有 exe 帧翻出来）
 * php tools/rva2sym.php watermark.map --log "$APPDATA/Drift/crash-latest.log"
 * ```
 *
 * ## 判据（`--selftest`）
 *
 * | # | 判据 | 能红的真实事故 |
 * |---|---|---|
 * | ① | 精确命中 / 落在函数中间给偏移 | 二分写错，返回了**下一个**函数（看着像，差一个） |
 * | ② | 落在第一个符号之前 ⇒ `null` | 返回 `0x0` 处的符号（把"不知道"说成"知道"） |
 * | ③ | 段表行（`.text CODE`）不进符号索引 | 把 8 位 hex 的段长当地址 ⇒ 整张表偏移 |
 * | ④ | 反修饰：`?f@C@@YAXXZ` → `C::f` | 输出整串 `?f@C@@YAXXZ`，人还得自己拆 |
 * | ⑤ | `php_a__b__c` → `a\b::c`（AOT 方法名） | 把方法名当成命名空间 |
 *
 * ★ 不走 `undname.exe`：那要多一次进程调用、且输出是 C++ 签名（`void __cdecl ...`），
 *   对"这是哪个 PHP 方法"没有增量信息。AOT 的名字是 `php_<ns>__<class>__<method>`，
 *   规则简单，本地能反。
 */

namespace Drift\Dev\Tools;

use Drift\Dev\Support\CheckItem;
use Drift\Dev\Support\CheckReport;

// ★ 本文件是"函数库 + argv 脚本"的混合体，此前没有引入装载器（只用内建函数）。
//   判据报告要走 `CheckReport`，所以这里补一次；`require_once` 防止被 include 时重入。
require_once __DIR__ . '/autoload.php';

/**
 * 解析 `.map` 文件，返回 [preferredLoadAddress, rva → list<symbol>]。
 *
 * @return array{0: int, 1: array<int, list<string>>}
 */
function parseMap(string $path): array
{
    $base = 0;
    $syms = [];

    $fh = \fopen($path, 'rb');
    if ($fh === false) {
        \fwrite(STDERR, "打不开 map：{$path}\n");
        exit(2);
    }

    while (($line = \fgets($fh)) !== false) {
        // 首选加载基址（RVA = Rva+Base 列 − 它）
        if ($base === 0 && \preg_match('/Preferred load address is ([0-9A-Fa-f]{16})/', $line, $m)) {
            $base = (int) \hexdec($m[1]);
            continue;
        }

        // 符号行：` 0001:00071cd0  ?name  0000000140072cd0 f [i] obj`
        // ★ 严格要求第 4 列是**16 位十六进制地址** ⇒ 段表行（`... .text CODE`）自然被排除，
        //   不需要另写一条"跳过段表"的分支（那种分支就是漏判的来源）。
        if (!\preg_match('/^ [0-9a-f]{4}:[0-9a-f]{8}\s+(\S+)\s+([0-9A-Fa-f]{16})\s+(f\b.*)$/', $line, $m)) {
            continue;
        }

        $rva = (int) \hexdec($m[2]);
        $syms[$rva][] = $m[1];
    }
    \fclose($fh);

    if ($base === 0) {
        \fwrite(STDERR, "map 里没有 Preferred load address：{$path}\n");
        exit(2);
    }

    // ★ 归一成 RVA。map 的地址列是 `首选加载基址 + RVA` ⇒ 减回去才和崩溃日志同口径
    //   （日志里的 RVA 是 `地址 − 运行时模块基址`，与链接期 RVA 相同 —— ASLR 只换基址）。
    $out = [];
    foreach ($syms as $va => $names) {
        $out[$va - $base] = $names;
    }

    \ksort($out);

    return [$base, $out];
}

/**
 * 找 `<= $rva` 的最近符号。
 *
 * @param array<int, list<string>> $syms 已按 rva 升序
 *
 * @return null|array{rva: int, offset: int, names: list<string>}
 */
function lookup(array $syms, int $rva): ?array
{
    $best = null;
    foreach ($syms as $addr => $names) {
        if ($addr > $rva) {
            break;
        }
        $best = ['rva' => $addr, 'offset' => $rva - $addr, 'names' => $names];
    }

    return $best;
}

/**
 * 去掉 C++ 修饰 / AOT 命名编码，给人看的名字。
 *
 * 两种输入：
 *   - MSVC 修饰名 `?seek@SkBlockMemoryStream@@UEAA_N_K@Z` ⇒ `SkBlockMemoryStream::seek`
 *   - AOT 的 PHP 函数 `php_drift__app__processtaskhost____construct` ⇒ `drift\app::processtaskhost::__construct`
 */
function pretty(string $name): string
{
    // ── MSVC 修饰名 ──
    // `?<func>@<class...>@@...` ⇒ `class::func`；没有 `@` 的（全局函数）原样返回。
    if ($name !== '' && $name[0] === '?') {
        $head = \explode('@@', \substr($name, 1), 2)[0];
        $parts = \explode('@', $head);
        $parts = \array_values(\array_filter($parts, static fn (string $s): bool => $s !== ''));
        if (\count($parts) >= 2) {
            $func = \array_shift($parts);

            return \implode('::', \array_reverse($parts)) . '::' . $func;
        }

        return $head;
    }

    // ── AOT 的 PHP 函数名 ──
    // `php_<ns>__<class>__<method>`，段之间是 `__`；参数默认值的包装后缀去掉。
    if (\str_starts_with($name, 'php_')) {
        $body = \preg_replace('/_arg_\d+_default_value$/', '', \substr($name, 4));
        if ($body === null || $body === '') {
            return $name;
        }

        // ★ **空段不是噪声，是魔术方法的标记**：`processtaskhost____construct`
        //   = 分隔符 `__` + 方法名自己的 `__construct` ⇒ `explode('__')` 会给出一个
        //   空串。直接 `array_filter` 掉它就把 `__construct` 变成了 `construct`
        //   （`--selftest` ⑥ 就是这条 —— 第一版正是这么错的）。
        $out = [];
        $carry = '';
        foreach (\explode('__', $body) as $seg) {
            if ($seg === '') {
                $carry = '__';
                continue;
            }
            $out[] = $carry . $seg;
            $carry = '';
        }

        if (\count($out) >= 2) {
            $method = \array_pop($out);

            return \implode('\\', $out) . '::' . $method;
        }

        return \implode('\\', $out);
    }

    return $name;
}

/** @param list<string> $names */
function describe(int $rva, array $names): string
{
    $shown = \array_map(static fn (string $n): string => pretty($n), \array_slice($names, 0, 3));
    $more = \count($names) > 3 ? ' …' : '';

    return \sprintf('0x%x  %s%s', $rva, \implode('  ', $shown), $more);
}

// ── `--selftest`：六条自洽判据（合成 map，绝不碰真实构建产物）──
//
// ★ `--selftest --json` 时 stdout 上只有 JSON（`CheckReport`），其余一行不印。
$selftest = \in_array('--selftest', $argv, true);
$json = $selftest && \in_array('--json', $argv, true);
if ($selftest) {
    /** @var list<CheckItem> $items */
    $items = [];

    $tmp = \tempnam(\sys_get_temp_dir(), 'rva2sym');
    if ($tmp === false) {
        \fwrite(STDERR, "建不了临时文件\n");
        exit(2);
    }
    $map = " watermark\n\n Preferred load address is 0000000140000000\n\n"
        . " Start         Length     Name                   Class\n"
        . " 0001:00000000 00331f70H .text                   CODE\n"
        . " 0001:00001000 00000020H .data                   DATA\n\n"
        . "  Address         Publics by Value              Rva+Base       Lib:Object\n\n"
        . " 0001:00000000       main                       0000000140001000 f   t.obj\n"
        . " 0001:00000100       ?seek@Foo@@UEAA_N_K@Z      0000000140001100 f   t.obj\n"
        . " 0001:00000200       php_drift__app__bar__run   0000000140001200 f   t.obj\n";
    \file_put_contents($tmp, $map);

    [$base, $syms] = parseMap($tmp);
    \unlink($tmp);

    // ① 精确命中 + 段内偏移
    $a = lookup($syms, 0x1000);
    $b = lookup($syms, 0x1010);
    $ok1 = $base === 0x140000000
        && $a !== null && $a['offset'] === 0 && $a['names'][0] === 'main'
        && $b !== null && $b['offset'] === 0x10 && $b['names'][0] === 'main';
    $items[] = new CheckItem('①', $ok1, $ok1
        ? '精确命中 0x1000→main(+0x0)，函数中间 0x1010→main(+0x10)'
        : '命中/偏移不对：' . \json_encode([$a, $b]));

    // ② 第一个符号之前 ⇒ null（不能编一个）
    $n = lookup($syms, 0x0FFF);
    $ok2 = $n === null;
    $items[] = new CheckItem('②', $ok2, $ok2 ? '0xfff 在首个符号之前 ⇒ 返回 null（没编）' : '首个符号之前返回了 ' . \json_encode($n));

    // ③ 段表行不进索引（否则 0x331f70 会被当成符号起点）
    $ok3 = !isset($syms[0x331f70 - 0x0]);
    $items[] = new CheckItem('③', $ok3, $ok3 ? '段表行（.text CODE）没被当成符号' : '段表行混进符号索引');

    // ④ MSVC 修饰名反修饰
    $p1 = pretty('?seek@Foo@@UEAA_N_K@Z');
    $ok4 = $p1 === 'Foo::seek';
    $items[] = new CheckItem('④', $ok4, $ok4 ? '?seek@Foo@@ ⇒ Foo::seek' : '反修饰给了：' . $p1);

    // ⑤ AOT 的 PHP 名（普通方法）
    $p2 = pretty('php_drift__app__bar__run');
    $ok5 = $p2 === 'drift\\app\\bar::run';
    $items[] = new CheckItem('⑤', $ok5, $ok5 ? 'php_drift__app__bar__run ⇒ drift\\app\\bar::run' : 'AOT 名给了：' . $p2);

    // ⑥ AOT 的**魔术方法**：`____` = 分隔符 `__` + 名字自己的 `__`。
    //    第一版用 `array_filter` 把空段当噪声滤掉 ⇒ `__construct` 变成 `construct`。
    $p3 = pretty('php_drift__app__store____construct_arg_1_default_value');
    $ok6 = $p3 === 'drift\\app\\store::__construct';
    $items[] = new CheckItem('⑥', $ok6, $ok6
        ? 'php_drift__app__store____construct… ⇒ drift\\app\\store::__construct'
        : '魔术方法给了：' . $p3 . '（`__` 被吃掉了）');

    (new CheckReport('rva2sym', $items, \sprintf('%d 项判据全过', \count($items))))->emit($json);
}

if ($argc < 3) {
    \fwrite(STDERR, "用法：\n  php tools/rva2sym.php <map> <rva> [...]\n  php tools/rva2sym.php <map> --log <崩溃日志>\n  php tools/rva2sym.php --selftest\n");
    exit(2);
}

$mapPath = $argv[1];
[$base, $syms] = parseMap($mapPath);
$exeName = \strtolower(\basename($mapPath, '.map')) . '.exe';

// ── `--log`：翻译整份崩溃日志 ──
if ($argv[2] === '--log') {
    $logPath = $argv[3] ?? '';
    if ($logPath === '' || !\is_file($logPath)) {
        \fwrite(STDERR, "找不到崩溃日志：{$logPath}\n");
        exit(2);
    }

    $text = (string) \file_get_contents($logPath);
    \printf("map=%s  base=0x%x  exe=%s\n\n", \basename($mapPath), $base, $exeName);

    $hit = 0;
    foreach (\explode("\n", $text) as $line) {
        // `模块+RVA`（头部的 addr= 与栈帧两种形态）
        if (!\preg_match('/([A-Za-z0-9_.\-]+)\+0x([0-9a-f]+)/', $line, $m)) {
            \printf("%s\n", $line);
            continue;
        }
        if (\strtolower($m[1]) !== $exeName) {
            \printf("%s\n", $line);   // 别的模块（php8ts.dll 等）本 map 管不了
            continue;
        }
        ++$hit;
        $rva = (int) \hexdec($m[2]);
        $sym = lookup($syms, $rva);
        if ($sym === null) {
            \printf("%s\n    ⇒ <map 里没有比它更小的符号：这个 RVA 不属于任何函数>\n", $line);
            continue;
        }
        \printf("%s\n    ⇒ +%s  %s\n", $line, \sprintf('0x%x', $sym['offset']), \implode('  ', \array_map('\Drift\Dev\Tools\pretty', $sym['names'])));
    }

    \printf("\n--- 翻译了 %d 条 exe 帧（其余模块要各自编 /MAP 才能翻）---\n", $hit);
    exit(0);
}

// ── 单个 / 多个 RVA ──
$fail = 0;
foreach (\array_slice($argv, 2) as $raw) {
    $rva = \preg_match('/^0x/i', $raw) ? (int) \hexdec($raw) : (int) $raw;
    $sym = lookup($syms, $rva);
    if ($sym === null) {
        \printf("0x%x  <map 里没有比它更小的符号>\n", $rva);
        ++$fail;
        continue;
    }
    \printf("0x%x  ⇒ +0x%x  %s\n", $rva, $sym['offset'], \describe($sym['rva'], $sym['names']));
}

exit($fail > 0 ? 1 : 0);
