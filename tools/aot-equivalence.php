#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * **AOT 与解释器的几何等价** —— 一条命令，退出码说话。
 *
 * ★ 它回答的问题只有一个：**同一份 `src/`，编成 exe 与直接跑，算出的盒子一样吗？**
 *   这条判据存在的理由是一个真实缺陷：tpc 把 `int / int` 编成整数除法
 *   （`7 / 2 === 3`，见 `AOT-CONSTRAINTS` §1.2）。截断是静默的 ——
 *   不崩、不报错、编译期不警告，只有逐值比对能发现。
 *
 * ★ 退出码是三态，不是两态：
 *   `0` 等价 · `1` 不等价 · `2` 无法判定（AOT 产物不存在 / 跑不起来）
 *   —— `2` 不冒充 `0`：本仓反复栽在「跳过」被读成「通过」上。
 *
 * 用法：
 *   php tools/aot-equivalence.php                  # 默认 ./frame_tax_aot_probe.exe
 *   php tools/aot-equivalence.php --exe=<path>
 *   php tools/aot-equivalence.php --quiet          # 只出退出码
 *
 * ★ 构建不进这条命令：它要 1m20s，而本工具只跑 2.5s。
 *   构建另起一条：`bash probes/frame_tax_aot/build.sh`。
 *
 * @internal 量具专用
 */

require __DIR__ . '/autoload.php';

use Drift\Dev\Bench\AotEquivalence;

$root = \dirname(__DIR__);
$exe = $root . \DIRECTORY_SEPARATOR . 'frame_tax_aot_probe.exe';
$verbose = true;

foreach (\array_slice($argv, 1) as $arg) {
    if (\str_starts_with($arg, '--exe=')) {
        $exe = \substr($arg, 6);
    } elseif ($arg === '--quiet') {
        $verbose = false;
    }
}

$eq = new AotEquivalence($exe, $root);
$code = $eq->verify($verbose);

if (!$verbose) {
    $fail = 0;
    foreach ($eq->checks() as $c) {
        if (!$c['ok']) {
            $fail++;
        }
    }
    echo $code === 0 ? 'PASS' : ($code === 2 ? 'SKIP(no exe)' : 'FAIL ' . $fail);
    echo "\n";
}

exit($code);
