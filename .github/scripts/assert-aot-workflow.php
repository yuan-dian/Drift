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
$releaseFile = $root . '/.github/workflows/release.yml';

if (!is_file($aotFile)) {
    fail('aot.yml 不存在 —— AOT 通道缺失');
}
if (!is_file($releaseFile)) {
    fail('release.yml 不存在 —— dev 壳发布通道缺失');
}
$aot = (string) file_get_contents($aotFile);
$ci = (string) file_get_contents($ciFile);
$release = (string) file_get_contents($releaseFile);

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
    'php tools/drift-build.php $target --full' => '全量 AOT 编译',
    'python tools/probe-window.py $target' => '真机五判据',
    'php tools/doctor.php --scope=build' => '编译前环境体检',
    'workflow_call:' => 'Release 可复用',
    'python tools/probe-window.py hello --dev' => 'dev 壳真机判据',
    'Compress-Archive -Path' => 'dev 壳打包',
    'gh release upload' => 'Release 资产上传',
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

// Release 必须真的调用这条通道，并选中 dev 目标；否则下载链接指向的空气。
foreach ([
    'uses: ./.github/workflows/aot.yml' => 'Release 复用 AOT 工作流',
    'target: dev' => '发布目标是 dev 壳',
    'release_tag:' => 'Release tag 传递',
] as $needle => $label) {
    if (!str_contains($release, $needle)) {
        fail('release.yml 缺环节：' . $label . '（' . $needle . '）');
    }
}

fwrite(STDOUT, '  ok     AOT 通道在公开 runner 上完整：' . count($anchors) . " 个环节\n");
fwrite(STDOUT, "  ok     Release 复用同一通道发布 dev 壳：3 个环节\n");
