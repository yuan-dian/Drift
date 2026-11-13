#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * IR 快照判据：渲染固定夹具 → 逐命令序列化 → 与入库基线逐字节比对。
 *
 *   php tests/Verify/ir-snapshot.php            # 比对（0 = 一致，1 = 有差异，2 = 没测到）
 *   php tests/Verify/ir-snapshot.php --write    # 重生成基线（只在**有意**改绘制契约时用）
 *
 * ★ 这是 V2 `behavior` 层的一条 step（`bin/drift verify --step=ir`），也是题库
 *   里 I1–I4 四条 IR 缺陷的「报红通道」。锚点从像素上移到**结构**：差异能定位到
 *   「第 87 条 PUSH_CLIP 的矩形」而不是「整页不同」（见 ANALYSIS-IR §5 V2-1）。
 *
 * ★ 判据与实现**不同源**：序列化走 `PaintFingerprint`（按值比、未知槽显性红），
 *   与录制器 `DisplayList` / `RecordingCanvas` 是两套代码 ⇒ 录制器写错了，
 *   这里看到的是**错的命令流**，而不是「期望与实现一起错」。
 *
 * ★ 退出码：0 一致 / 1 有差异（判据红）/ **2 没测到**（一帧没跑、基线缺失）。
 *   「没测到」绝不写成「一致」—— 这是 V2 全体系的红线。
 *
 * ★ 基线缺失时**不自动生成**：那会让「忘了写基线」变成「永远绿」。必须显式 `--write`。
 */

require dirname(__DIR__, 2) . '/tools/autoload.php';

use Drift\Dev\Bench\FrameTaxApp;
use Drift\Dev\Bench\PaintFingerprint;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Verify\IrFixture;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;

$root     = \dirname(__DIR__, 2);
$baseline = $root . '/tests/Verify/baseline/ir.txt';
$write    = \in_array('--write', \array_slice($argv, 1), true);

// ── 渲染：与 PageFrame 同一条挂载路径（attach 自己跑 mount + settle + layout + paint）
$app = new FrameTaxApp(static fn() => IrFixture::widget());
$surface = new SurfaceProbe();
$surface->resize(1200, 800, 1.0);
$app->attach($surface, BoxConstraints::tight(new Size(1200.0, 800.0)));

$list = $surface->list();
if ($surface->frames === 0 || $list === null) {
    \fwrite(STDERR, "没测到：夹具一帧都没跑（frames={$surface->frames}）\n");
    exit(2);
}

$lines  = PaintFingerprint::detail($list);
$actual = \implode("\n", $lines) . "\n";

if ($lines === []) {
    \fwrite(STDERR, "没测到：命令流为空（夹具没画出任何东西）\n");
    exit(2);
}

if ($write) {
    $dir = \dirname($baseline);
    if (!\is_dir($dir)) {
        \mkdir($dir, 0777, true);
    }
    \file_put_contents($baseline, $actual);
    echo '写入基线：' . \count($lines) . " 条命令 → {$baseline}\n";
    exit(0);
}

if (!\is_file($baseline)) {
    \fwrite(STDERR, "基线缺失：{$baseline}（先跑 `php tests/Verify/ir-snapshot.php --write`）\n");
    exit(2);
}

$expected = (string) \file_get_contents($baseline);
// 行尾归一：IR 是命令流，`\r\n` 与 `\n` 不是两种 IR。`.gitattributes` 已把基线钉成
// LF，这里再归一一次是防御 —— Windows 上 `core.autocrlf=true` 的检出不该让判据永远红。
$expected = \str_replace("\r\n", "\n", $expected);
if ($actual === $expected) {
    echo 'IR 快照一致（' . \count($lines) . " 条命令）\n";
    exit(0);
}

// ── 定位第一条不同的命令（命令流有序，一条错会让它之后整段不同）
$exp = \explode("\n", $expected);
$act = \explode("\n", $actual);
$n   = \max(\count($exp), \count($act));
for ($i = 0; $i < $n; $i++) {
    $e = $exp[$i] ?? '<缺>';
    $a = $act[$i] ?? '<缺>';
    if ($e !== $a) {
        \fwrite(STDERR, "IR 快照不一致（第 {$i} 行）：\n  基线: {$e}\n  实际: {$a}\n");
        break;
    }
}
exit(1);
