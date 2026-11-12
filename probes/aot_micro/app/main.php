<?php
declare(strict_types=1);

namespace Probe\AotMicro {

use Drift\Dev\Bench\AotMicro;

/**
 * AOT 微基准阶梯 —— **tpc `-m bin` 这一侧**。
 *
 * ## 它回答的那个问题
 *
 * `DEBT` §34 量出 AOT 在 12k 合成树上慢 1.73×、§35 在 27 个真实页上慢 2.24×，
 * 当时的归因是「`php::Var` 装箱」（**假说，未验证**）。可 `AOT-CONSTRAINTS` §2.3
 * 的 M2 早就测过 `mixed` + `toInt()` vs `int` 直读 = **0.94** —— 装箱在属性读上
 * 几乎免费。两个结论对不上，说明减速的成分**从来没被定位过**。
 *
 * 阶梯就是干这个的：13 级，每级只比上一级多一种操作，慢落在哪一级就是指哪一种贵。
 *
 * ## 判据（与解释器侧 `tools/aot-micro-probe.php` 逐条同义）
 *
 * | 本探针 | 判据 | 能红的真实事故 |
 * |---|---|---|
 * | M① | 每级 checksum ≠ 0 | 整段被 DCE 删掉（结果没被使用） |
 * | M② | 每级 `t(2n)/t(n) ≥ 1.3` | 常量折叠 / 循环外提 —— "快"是假的 |
 * | M③ | 每级 3 次抖动 ≤ 1.4 | 读数不可复现 |
 *
 * ★ 判据**只判"测得可不可信"，不判"谁更快"**。谁是快的那一方是**报告**出来的：
 *   拿两侧的表逐级相除。判据去判结论，等于把结论提前写进了工具里。
 *
 * ★★ 与 `frame_tax_aot` 的差别：那个要引整个 `src`（378 翻译单元 / 1m20s），
 *   这个**只引 5 个文件**（本文件 + `AotMicro` + 4 个 `Micro\*`）——
 *   阶梯刻意做到**零框架依赖**，这样它才敢随时重跑。代价是它测不到
 *   "真实 RenderBox"里那点阶梯覆盖不到的东西（例如 `Errors::report()` 的失败路径）。
 *
 * ★ 已知不覆盖：`paint` / 软光栅 / 文本塑形。这三块要么走 native，要么与本次归因无关。
 */
function runProbe(): void
{
    // AOT 产物的默认 memory_limit 是 128MB，本例全部在 10MB 内，不需要放宽。
    // ★ 但要把实际值印出来 —— 两侧不一致时读数的可比性就要打问号。
    $base = 200000;
    $rows = AotMicro::run($base);
    $checks = AotMicro::check($rows);

    AotMicro::report($rows, $checks, 'tpc AOT (-m bin)', $base);

    if (AotMicro::failCount($checks) > 0) {
        \exit(1);
    }
}

}

namespace {

/**
 * ★ tpc 的 Embed SAPI 要求**全局** `main()`；探针逻辑放在具名命名空间里，
 *   两者不能混用「分号语法」与「块语法」—— 本文件两处都用块语法。
 */
function main(): void
{
    \Probe\AotMicro\runProbe();
}

}
