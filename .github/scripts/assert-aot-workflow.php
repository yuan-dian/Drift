<?php
declare(strict_types=1);

/**
 * 守着 AOT 通道不退回假绿：aot.yml 必须仍是公开 runner 上的真机 job。
 *
 * 背景：ci.yml 曾有 `if: false` 的 aot job —— 表面有 AOT，实际从不执行。
 * 本判据从 workflow 文本里独立核对关键环节；缺任一环就红，不靠人记得看。
 *
 * 用法：php .github/scripts/assert-aot-workflow.php
 */

function fail(string $msg): never
{
    fwrite(STDERR, '  FAIL  ' . $msg . "\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$aotFile = $root . '/.github/workflows/aot.yml';
$ciFile = $root . '/.github/workflows/ci.yml';

if (!is_file($aotFile)) {
    fail('aot.yml 不存在 —— AOT 通道缺失');
}
$aot = (string) file_get_contents($aotFile);
$ci = (string) file_get_contents($ciFile);

if (preg_match('/^\s*if:\s*false\s*$/m', $aot)) {
    fail('aot.yml 存在 if: false —— AOT job 被关掉，等于没有');
}
if (preg_match('/^\s*if:\s*false\s*$/m', $ci)) {
    fail('ci.yml 仍有 if: false 的死 job —— 表面有 AOT，实际不跑');
}
if (preg_match('/^\s{2}aot:\s*$/m', $ci)) {
    fail('ci.yml 仍定义 aot job —— 真 AOT 只在 aot.yml 里，两处会漂');
}

// 每个锚点对应一个不可省的环节：只编译 C++ / 只跑 dev 壳都不算真 AOT。
$anchors = [
    'runs-on: windows-2022' => '公开 runner',
    'repository: swoole/typephp' => 'TypePHP 编译器源码',
    'repository: swoole/phpx' => 'PHPX 运行时',
    'cache-primary-key' => '工具链缓存',
    'cmake --build $phpxBuild --target phpx' => 'PHPX 真编译',
    'php tools/fetch-deps.php' => 'Skia / 字体依赖',
    'php tools/drift-build.php hello --full' => '全量 AOT 编译',
    'python tools/probe-window.py hello' => '真机五判据',
    'php tools/doctor.php --scope=build' => '编译前环境体检',
];

$missing = [];
foreach ($anchors as $needle => $label) {
    if (!str_contains($aot, $needle)) {
        $missing[] = $label . '（' . $needle . '）';
    }
}
if ($missing !== []) {
    fail('aot.yml 缺环节：' . implode('、', $missing));
}

fwrite(STDOUT, '  ok     AOT 通道在公开 runner 上完整：' . count($anchors) . " 个环节\n");
