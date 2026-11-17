<?php
declare(strict_types=1);

/**
 * 守住 workflow 的形状：真 AOT 在公开 runner 上跑，发版先构建 Skia 再编译。
 *
 * 背景：旧 ci.yml 曾有 `if: false` 的 aot job —— 表面有 AOT，实际从不执行。
 * 判据从 workflow 文本里独立核对关键环节；缺任一环就红，不靠人记得看。
 *
 * 用法：php .github/scripts/assert-workflows.php
 */

function fail(string $msg): never
{
    fwrite(STDERR, '  FAIL  ' . $msg . "\n");
    exit(1);
}

$root = dirname(__DIR__, 2);
$buildFile = $root . '/.github/workflows/windows-build.yml';
$verifyFile = $root . '/.github/workflows/verify.yml';
$releaseFile = $root . '/.github/workflows/release.yml';
$depsFile = $root . '/.github/workflows/windows-deps.yml';

foreach (['windows-build.yml' => $buildFile, 'verify.yml' => $verifyFile,
          'release.yml' => $releaseFile, 'windows-deps.yml' => $depsFile] as $name => $path) {
    if (!is_file($path)) {
        fail("{$name} 不存在 —— workflow 通道缺失");
    }
}
$build = (string) file_get_contents($buildFile);
$verify = (string) file_get_contents($verifyFile);
$release = (string) file_get_contents($releaseFile);
$deps = (string) file_get_contents($depsFile);

foreach (['windows-build.yml' => $build, 'verify.yml' => $verify] as $name => $text) {
    if (preg_match('/^\s*if:\s*false\s*$/m', $text)) {
        fail("{$name} 存在 if: false —— job 被关掉，等于没有");
    }
}
if (preg_match('/^\s{2}aot:\s*$/m', $verify)) {
    fail('verify.yml 仍定义 aot job —— 真 AOT 只在 windows-build.yml 里，两处会漂');
}

// 每个锚点对应一个不可省的环节：只编译 C++ / 只跑 dev 壳都不算真 AOT。
$buildAnchors = [
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
    'name: Restore Skia artifact' => '发版复用前置 Skia 产物',
];
foreach ($buildAnchors as $needle => $label) {
    if (!str_contains($build, $needle)) {
        fail('windows-build.yml 缺环节：' . $label . '（' . $needle . '）');
    }
}

// 发版链路：deps 先跑，verify / dev-shell 都接它的 artifact。
$releaseAnchors = [
    'uses: ./.github/workflows/windows-deps.yml' => '发版先构建 Skia',
    'needs: deps' => 'verify 等依赖就绪',
    'needs: [verify, deps]' => 'dev 壳等依赖就绪',
    'uses: ./.github/workflows/windows-build.yml' => 'Release 复用构建通道',
    'target: dev' => '发布目标是 dev 壳',
    'release_tag:' => 'Release tag 传递',
    'deps_artifact:' => 'Skia artifact 传递',
    'name: Restore Skia artifact' => 'verify 接收 Skia 产物',
];
foreach ($releaseAnchors as $needle => $label) {
    if (!str_contains($release, $needle)) {
        fail('release.yml 缺环节：' . $label . '（' . $needle . '）');
    }
}
$depsPos = strpos($release, '  deps:');
$verifyPos = strpos($release, '  verify:');
if ($depsPos === false || $verifyPos === false || $depsPos > $verifyPos) {
    fail('release.yml 的 deps job 必须在 verify 之前');
}

// Skia 构建通道：DEPS_VERSION 变化时自动发布，发版调用只产 artifact。
$depsAnchors = [
    'paths: [DEPS_VERSION]' => '依赖版本变化自动触发',
    'workflow_dispatch:' => '手动兜底',
    'workflow_call:' => '发版前置调用',
    'tools/skia/build-skia.sh --rev="$REV"' => '从固定 commit 编 Skia',
    'php tools/pack-deps.php' => '打包',
    'php tools/sync-lock-anchors.php' => '同步 README 校验锚点',
    'actions/upload-artifact@v4' => '产物交给下游',
    'php tools/release-deps.php' => '发布依赖资产',
    'php tools/fetch-deps.php --tag="$TAG" --force' => '发布后回验',
];
foreach ($depsAnchors as $needle => $label) {
    if (!str_contains($deps, $needle)) {
        fail('windows-deps.yml 缺环节：' . $label . '（' . $needle . '）');
    }
}
if (!str_contains($verify, 'assert-workflows.php')) {
    fail('verify.yml 没跑 workflow 结构判据 —— 通道退化不会被发现');
}

fwrite(STDOUT, '  ok     真 AOT 通道完整：' . count($buildAnchors) . " 个环节\n");
fwrite(STDOUT, '  ok     发版先构建 Skia 再编译：' . count($releaseAnchors) . " 个环节\n");
fwrite(STDOUT, '  ok     依赖自动构建通道完整：' . count($depsAnchors) . " 个环节\n");
