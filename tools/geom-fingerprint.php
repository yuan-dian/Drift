#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 27 个真实页的**几何指纹** —— 解释器这一侧。
 *
 * ★ 另一半在 `probes/frame_tax_aot`（`PROBE_SECTION=geom`），跑的是同一个
 *   `Drift\Dev\Bench\GeomFingerprint`。
 *
 * ★★ **判据不在这里，在 `tools/aot-equivalence.php`**：它跑两侧、逐页比、
 *   不一致时自己归因 + 定位，退出码说话。本文件只是"解释器侧的原始产出"——
 *   给人看表格、给脚本要 TSV、给单页破案要明细。
 *   手工 `diff` 两份输出是 2026-11-04 之前的老流程，别再用（见 `DEBT` §37.1）。
 *
 * ★ 它证明的是「AOT 算出来的尺寸/位置与解释器一致」，不是性能。
 *   存在的理由是一个真实 bug：tpc 把 `int / int` 编译成整数除法
 *   （`AOT-CONSTRAINTS` §1.2）。截断是静默的 —— 只有逐值比对能发现。
 *
 * 用法：
 *   php tools/geom-fingerprint.php            # 打印表格
 *   php tools/geom-fingerprint.php --tsv      # "页名<TAB>hash<TAB>nodes<TAB>sumW<TAB>sumH"
 *   php tools/geom-fingerprint.php --detail=AspectPage   # 单页逐节点（按深度缩进）
 *
 * @internal 量具专用
 */

require __DIR__ . '/autoload.php';

use Drift\Dev\Bench\GeomFingerprint;

$tsv = false;
$detail = null;
foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--tsv') {
        $tsv = true;
    } elseif (\str_starts_with($arg, '--detail=')) {
        $detail = \substr($arg, 9);
    }
}

if ($detail !== null) {
    $root = GeomFingerprint::mountPage($detail);
    if ($root === null) {
        \fwrite(\STDERR, '这一页没建出根元素：' . $detail . "\n");
        exit(2);
    }
    foreach (GeomFingerprint::detail($root) as $line) {
        echo $line . "\n";
    }
    exit(0);
}

$rows = GeomFingerprint::allPages();

if ($tsv) {
    foreach ($rows as $r) {
        echo GeomFingerprint::tsvLine($r) . "\n";
    }
    exit(0);
}

echo "=== 27 页几何指纹 · 解释器侧 ===\n";
echo 'php=' . \PHP_VERSION . '  视口=1200x800' . "\n\n";
\printf("%-24s %8s %12s %12s  %s\n", 'page', 'nodes', 'sumW', 'sumH', 'hash');
echo \str_repeat('-', 84) . "\n";
$total = 0;
foreach ($rows as $r) {
    \printf(
        "%-24s %8d %12.1f %12.1f  %s\n",
        (string) $r['page'],
        (int) $r['nodes'],
        (float) $r['sumW'],
        (float) $r['sumH'],
        (string) $r['hash']
    );
    $total += (int) $r['nodes'];
}
echo \str_repeat('-', 84) . "\n";
echo \sprintf("合计 %d 页 / %d 节点\n", \count($rows), $total);
