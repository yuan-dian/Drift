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
    'php tools/drift-dev.php hello --write-fingerprint' => 'dev 壳指纹写入（与启动器同源）',
    "Copy-Item 'assets/cacert.pem'" => 'TLS 根证书取自受版本管理的原件',
    'python tools/probe-window.py $target' => '真机五判据',
    'php tools/doctor.php --scope=build' => '编译前环境体检',
    'workflow_call:' => 'Release 可复用',
    'python tools/probe-window.py hello --dev' => 'dev 壳真机判据',
    "\$env:PATH = (Join-Path \$env:DRIFT_PHPX_HOME 'build')" => 'dev.exe 探针的 phpx.dll 搜索路径',
    'Compress-Archive -Path' => 'dev 壳打包',
    'gh release upload' => 'Release 资产上传',
    'name: Restore Skia artifact' => '发版复用前置 Skia 产物',
];
foreach ($buildAnchors as $needle => $label) {
    if (!str_contains($build, $needle)) {
        fail('windows-build.yml 缺环节：' . $label . '（' . $needle . '）');
    }
}

// 发版链路：deps 先跑；但 publish=false 的临时产物不能覆盖已发布的 deps 锁。
$releaseAnchors = [
    'uses: ./.github/workflows/windows-deps.yml' => '发版先构建 Skia',
    'needs: deps' => 'verify 等依赖就绪',
    'needs: [verify, deps]' => 'dev 壳等依赖就绪',
    'uses: ./.github/workflows/windows-build.yml' => 'Release 复用构建通道',
    'target: dev' => '发布目标是 dev 壳',
    'release_tag:' => 'Release tag 传递',
    "deps_artifact: ''" => '发版显式传空 artifact（只用已发布的 deps-vN）',
];
foreach ($releaseAnchors as $needle => $label) {
    if (!str_contains($release, $needle)) {
        fail('release.yml 缺环节：' . $label . '（' . $needle . '）');
    }
}
if (str_contains($release, 'name: Restore Skia artifact')) {
    fail('release.yml 又恢复 deps 临时产物 —— 它的 lock/zip 指向未发布的包，必然覆盖已发布 deps 的锚点');
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
    'Ensure fonts are present' => '构建前确认字体在树里',
];
foreach ($depsAnchors as $needle => $label) {
    if (!str_contains($deps, $needle)) {
        fail('windows-deps.yml 缺环节：' . $label . '（' . $needle . '）');
    }
}

// ★ 构建通道必须能在托管 runner 上真跑。self-hosted 在仓库没有注册 runner 时
//   不会报错，只会静默排队到超时取消 —— 那种"YAML 里有、实际从不执行"的通道
//   是最贵的一种假绿。所以出现 self-hosted 一律红，改为托管镜像 + 自动探测工具链。
//   只看生效行：注释里讨论这件事本身是需要的，不该被自己拦住。
$stripComments = static fn (string $text): string => (string) preg_replace('/^\s*#.*$/m', '', $text);
foreach (['windows-build.yml' => $build, 'verify.yml' => $verify,
          'release.yml' => $release, 'windows-deps.yml' => $deps] as $name => $text) {
    if (str_contains($stripComments($text), 'self-hosted')) {
        fail("{$name} 声明了 self-hosted —— 没有注册 runner 时任务只会静默排队，不会失败");
    }
}
// ★ gn / ninja 不再走 depot_tools：它的 gn.exe 装在 CIPD 子目录里，而 bootstrap 只在
//   自己那个 cmd 进程内改 PATH，父进程拿不到；CI 上连踩三次，最后一次才确认目录布局。
//   改为 build-skia.sh 先查 PATH，再调 Skia 自带的 bin/fetch-gn / bin/fetch-ninja 取
//   官方预编译包（版本由 Skia 自己的脚本与 DEPS 钉住）。
$skiaScript = (string) @file_get_contents($root . '/tools/skia/build-skia.sh');
foreach (['bin/fetch-gn', 'bin/fetch-ninja'] as $needle) {
    if (!str_contains($skiaScript, $needle)) {
        fail('tools/skia/build-skia.sh 缺 ' . $needle . ' —— 没有 depot_tools 时要靠它取预编译 gn / ninja');
    }
}
// ★ gn 靠「当前目录或任一父目录里有 .gn」确定 source root，从仓库根调它会直接
//   "Can't find source root."，与 --args 对不对毫无关系。这一步失败时 Skia 已经
//   clone + sync 完，一次白跑十几分钟。
if (preg_match('/(?:--root="?\$skia_dir|cd\s+"\$skia_dir"\s*&&\s*"\$GN"\s+gen)/', $skiaScript) !== 1) {
    fail('tools/skia/build-skia.sh 调 gn gen 时没把工作目录切到 skia_dir —— gn 会报 Can\'t find source root');
}

$depsLive = $stripComments($deps);
if (str_contains($depsLive, 'depot_tools')) {
    fail('windows-deps.yml 又装回 depot_tools —— gn.exe 在 CIPD 子目录里，bootstrap 改的 PATH 传不出子进程');
}
// ★ reusable workflow 的 github.event_name 继承调用方：产品发版是 tag push，
//   若用它推断「独立发布」，release 传的 publish=false 会被覆盖成 true，
//   deps job 白编 16 分钟后在 Preflight（deps-v1 已存在）挂掉。
//   publish / commit_lock 必须先取 inputs，只有空值（原生 push 触发）才回退事件。
if (!str_contains($depsLive, "publish='\${{ inputs.publish }}'")
    || !str_contains($depsLive, "commit_lock='\${{ inputs.commit_lock }}'")) {
    fail('windows-deps.yml 的 publish/commit_lock 必须先取 inputs —— 事件推断会吃掉 release 的显式 false');
}
if (preg_match('/\$\{\{\s*github\.event_name\s*\}\}\s*=\s*"push"\s*\]\s*\|\|/', $depsLive) === 1) {
    fail('windows-deps.yml 又用 event_name=push 直接推导 publish —— reusable workflow 里它继承 tag push');
}
// SDK 版本必须按 x.y.z.w 过滤：Include 下还有 wdf / um / shared 这些目录，
// 按名字排序取最后一个会拿到 "wdf"，喂给 gn 之后报的错跟 SDK 八竿子打不着。
if (!str_contains($depsLive, '^\d+\.\d+\.\d+\.\d+$')) {
    fail('windows-deps.yml 探测 Windows SDK 版本时没有按 x.y.z.w 过滤 —— 会取到 wdf 之类的目录');
}

$depsJob = substr($deps, (int) strpos($deps, 'jobs:'));
if (!str_contains($depsJob, 'runs-on: windows-2022')) {
    fail('windows-deps.yml 的 build job 必须跑在托管 windows-2022 上');
}
if (preg_match('/^\s{4}timeout-minutes:\s*(\d+)\s*$/m', $depsJob, $m) !== 1 || (int) $m[1] < 180) {
    fail('windows-deps.yml 的 timeout 至少 180 分钟 —— Skia 全量编译 + git-sync-deps 超过两小时属正常');
}

// 托管 runner 没有持久盘：字体必须在树里，否则构建前就会缺字。
$gitignore = (string) @file_get_contents($root . '/.gitignore');
foreach (['NotoSansSC-Regular.ttf', 'NotoSansSC-Bold.ttf'] as $font) {
    if (!str_contains($gitignore, '!/cpp/fonts/' . $font)) {
        fail('.gitignore 缺字体白名单 ' . $font . ' —— cpp/* 会把它挡在树外，托管 runner 就凑不齐字体');
    }
}
if (!str_contains($verify, 'assert-workflows.php')) {
    fail('verify.yml 没跑 workflow 结构判据 —— 通道退化不会被发现');
}

fwrite(STDOUT, '  ok     真 AOT 通道完整：' . count($buildAnchors) . " 个环节\n");
fwrite(STDOUT, '  ok     发版先构建 Skia 再编译：' . count($releaseAnchors) . " 个环节\n");
fwrite(STDOUT, '  ok     依赖自动构建通道完整：' . count($depsAnchors) . " 个环节\n");
fwrite(STDOUT, "  ok     构建通道全在托管 runner 上（无 self-hosted 静默排队）\n");
fwrite(STDOUT, "  ok     gn / ninja 走 Skia 自带预编译包（不装 depot_tools）\n");
fwrite(STDOUT, "  ok     gn gen 在 Skia 源码目录内执行（source root 靠 .gn 定位）\n");
