<?php
declare(strict_types=1);

// 双模语义等价的无头入口：DRIFT_DEV_ENTRY 指向本文件 ⇒ dev.exe 不开窗，只吐指纹 TSV。
// 与 tools/fingerprint.php --tsv 共用同一份 Fingerprint，行数/格式必须逐字相同。
//
// ★ 刻意放 tools/ 而不是 apps/dev/：drift-build.php 的输入指纹是 hashTree(apps/dev/)，
//   放那边会让「改一个纯运行期入口」也触发 dev 壳重编。这里既不在编译输入里，
//   也享受 toolchain 的 lint 豁免。
require getcwd() . '/tools/autoload.php';

// 解释器 CLI 默认 -1，dev/prod exe 默认 128M ⇒ 一次挂 27 页的量具工作负载会
// 在 TtfFace 加载字体时撞上限。这是资源上限、不是框架语义，按解释器对齐。
ini_set('memory_limit', '-1');

$snap = \Drift\Dev\Bench\Fingerprint::snapshot();
foreach (\Drift\Dev\Bench\Fingerprint::CHANNELS as $ch) {
    foreach ($snap[$ch] as $row) {
        echo \Drift\Dev\Bench\Fingerprint::row($ch, $row) . "\n";
    }
}
