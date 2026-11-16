#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 27 个真实页的**双通道指纹** —— 解释器这一侧。
 *
 * | 通道 | 内容是 | 回答 |
 * |---|---|---|
 * | `geom` | 每个 `RenderBox` 的 `宽/高@x,y` | 布局算对了吗 |
 * | `paint` | 每条绘制命令的 op 与全部操作数 | 画对了吗（颜色/画笔/裁剪/文字排版内部） |
 *
 * ★ 另一半在 `probes/frame_tax_aot`（`PROBE_SECTION=fingerprint`），跑的是同一份
 *   `Drift\Dev\Bench\Fingerprint`。两侧同序、同格式 ⇒ 输出可以逐行 `diff`。
 *
 * ★★ **判据不在这里，在 `tools/aot-equivalence.php`**：它跑两侧、逐页比、
 *   不一致时自己归因 + 定位，退出码说话。本文件只是"解释器侧的原始产出"——
 *   给人看表格、给脚本要 TSV、给单页破案要明细。
 *   手工 `diff` 两份输出是 2026-11-04 之前的老流程，别再用（见 `DEBT` §37.1）。
 *
 * ★ 它证明的是「AOT 算出来 / 画出来的与解释器一致」，不是性能。
 *   存在的理由是一个真实 bug：tpc 把 `int / int` 编译成整数除法
 *   （`docs/reference/aot-constraints.md` §1.2）。截断是静默的 —— 只有逐值比对能发现。
 *
 * 用法：
 *   php tools/fingerprint.php                       # 两个通道的表格
 *   php tools/fingerprint.php --tsv                 # 可 diff 的行（两通道）
 *   php tools/fingerprint.php --tsv --channel=paint
 *   php tools/fingerprint.php --detail=AspectPage --channel=paint
 *
 * @internal 量具专用
 */

require __DIR__ . '/autoload.php';

use Drift\Dev\Bench\Fingerprint;

$tsv = false;
$detail = null;
$only = '';
foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--tsv') {
        $tsv = true;
    } elseif (\str_starts_with($arg, '--detail=')) {
        $detail = \substr($arg, 9);
    } elseif (\str_starts_with($arg, '--channel=')) {
        $only = \substr($arg, 10);
        if (!\in_array($only, Fingerprint::CHANNELS, true)) {
            \fwrite(\STDERR, '未知通道：' . $only . '（可选 ' . \implode(' / ', Fingerprint::CHANNELS) . "）\n");
            exit(2);
        }
    }
}

$channels = $only === '' ? Fingerprint::CHANNELS : [$only];

if ($detail !== null) {
    // 明细默认走几何：它是更常用的那一半（布局错比绘制错常见得多）
    $ch = $only === '' ? Fingerprint::GEOM : $only;
    $lines = Fingerprint::detail($ch, $detail);
    if ($lines === []) {
        \fwrite(\STDERR, '这一页没建出内容：' . $detail . '（通道 ' . $ch . '）' . "\n");
        exit(2);
    }
    foreach ($lines as $line) {
        echo $line . "\n";
    }
    exit(0);
}

$snap = Fingerprint::snapshot();

if ($tsv) {
    foreach ($channels as $ch) {
        foreach ($snap[$ch] as $r) {
            echo Fingerprint::row($ch, $r) . "\n";
        }
    }
    exit(0);
}

echo "=== 27 页双通道指纹 · 解释器侧 ===\n";
echo 'php=' . \PHP_VERSION . '  视口=1200x800' . "\n";

foreach ($channels as $ch) {
    $cols = Fingerprint::columns($ch);
    echo "\n[" . $ch . "]\n";
    \printf("%-24s %8s %10s %10s  %s\n", 'page', $cols[0], $cols[1], $cols[2], 'hash');
    echo \str_repeat('-', 86) . "\n";
    foreach ($snap[$ch] as $r) {
        \printf(
            "%-24s %8s %10s %10s  %s\n",
            $r['page'],
            $r['v1'],
            $r['v2'],
            $r['v3'],
            $r['hash']
        );
    }
    echo \str_repeat('-', 86) . "\n";
    echo \sprintf("合计 %d 页\n", \count($snap[$ch]));
}

$unknown = Fingerprint::unknownTypes();
if ($unknown !== []) {
    echo "\n★ 有槽类型没序列化：" . \implode('、', $unknown) . "（指纹对它们是瞎的）\n";
    exit(1);
}
