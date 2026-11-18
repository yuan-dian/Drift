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

// ★ 表达式上下文是有作用域的：`runner` / `steps` / `job` / `env` 只在 **step 级**可用。
//   写进 workflow 级或 job 级 `env:`（以及 job 级 `runs-on:`）时，GitHub 会把整份
//   workflow 判为无效 —— 只留一条 "Invalid workflow file ... unrecognized named-value"，
//   run 里 0 个 job，等于这条通道静默消失。本地 YAML 语法检查对此完全无感（语法合法），
//   所以必须在结构判据里独立拦一道。
function assertNoStepOnlyContext(string $file, int $lineNo, string $line): void
{
    if (preg_match_all('/\$\{\{\s*([A-Za-z_][A-Za-z0-9_-]*)\./', $line, $m) === 0) {
        return;
    }
    foreach ($m[1] as $ctx) {
        if (in_array($ctx, ['runner', 'steps', 'job', 'env'], true)) {
            fail("{$file}:{$lineNo} 在 job/workflow 级用了 step 专有上下文 {$ctx}. —— GitHub 会判整份 workflow 无效");
        }
    }
}

function assertContextScope(string $file, string $text): void
{
    $lines = explode("\n", $text);
    $n = count($lines);
    for ($i = 0; $i < $n; $i++) {
        $line = $lines[$i];
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        $indent = strlen($line) - strlen($trimmed);

        // 注意用 $trimmed 匹配：job 级这两行的真实文本带 4 空格缩进，
        // 拿 $line 去匹 `^env:` 会一格都匹不上（写成 $line 时判据静默失效）。
        if ($indent === 4 && preg_match('/^runs-on:/', $trimmed) === 1) {
            assertNoStepOnlyContext($file, $i + 1, $line);
            continue;
        }
        // 缩进 0 = workflow 级 env；缩进 4 = job 级 env。两者都不许出现 step 专有上下文。
        $isEnv = ($indent === 0 || $indent === 4) && preg_match('/^env:\s*$/', $trimmed) === 1;
        if (!$isEnv) {
            continue;
        }
        for ($j = $i + 1; $j < $n; $j++) {
            $inner = $lines[$j];
            $innerTrim = ltrim($inner);
            if ($innerTrim === '') {
                continue;
            }
            if (strlen($inner) - strlen($innerTrim) <= $indent) {
                break;
            }
            if (str_starts_with($innerTrim, '#')) {
                continue;
            }
            assertNoStepOnlyContext($file, $j + 1, $inner);
        }
    }
}

$root = dirname(__DIR__, 2);
$buildFile = $root . '/.github/workflows/windows-build.yml';
$verifyFile = $root . '/.github/workflows/verify.yml';
$releaseFile = $root . '/.github/workflows/release.yml';
$depsFile = $root . '/.github/workflows/windows-deps.yml';
$linuxFile = $root . '/.github/workflows/linux-build.yml';
$linuxDepsFile = $root . '/.github/workflows/linux-deps.yml';

foreach (['windows-build.yml' => $buildFile, 'verify.yml' => $verifyFile,
          'release.yml' => $releaseFile, 'windows-deps.yml' => $depsFile,
          'linux-build.yml' => $linuxFile,
          'linux-deps.yml' => $linuxDepsFile] as $name => $path) {
    if (!is_file($path)) {
        fail("{$name} 不存在 —— workflow 通道缺失");
    }
}
$build = (string) file_get_contents($buildFile);
$verify = (string) file_get_contents($verifyFile);
$release = (string) file_get_contents($releaseFile);
$deps = (string) file_get_contents($depsFile);
$linux = (string) file_get_contents($linuxFile);
$linuxDeps = (string) file_get_contents($linuxDepsFile);

foreach (['workflow' => ['.github/workflows/windows-build.yml', $build],
          'verify' => ['.github/workflows/verify.yml', $verify],
          'release' => ['.github/workflows/release.yml', $release],
          'deps' => ['.github/workflows/windows-deps.yml', $deps],
          'linux' => ['.github/workflows/linux-build.yml', $linux],
          'linux-deps' => ['.github/workflows/linux-deps.yml', $linuxDeps]] as $file => [$path, $text]) {
    assertContextScope($path, $text);
}
fwrite(STDOUT, "  ok     表达式上下文作用域（job/workflow 级 env 不碰 runner/steps/job/env）\n");

foreach (['windows-build.yml' => $build, 'verify.yml' => $verify,
          'linux-build.yml' => $linux, 'linux-deps.yml' => $linuxDeps] as $name => $text) {
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
    'MSVC runtime DLL missing' => 'VC 运行库取自 PHP 目录或 VS redist，缺了就红',
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

// Linux 门：工具链必须钉死到本地验证过的 commit，且必须真跑端到端脚本（不是只编一下）。
$linuxAnchors = [
    'ref: c5599c5c9e8597e7124dd79db94739cabe39c526' => 'TypePHP 工具链必须钉 commit',
    'ref: 90e6d22a9c3f4d5dbd2c75d62e90bf5adfe58c89' => 'PHPX 工具链必须钉 commit',
    'phpts: ts' => '必须装 ZTS PHP（AOT 需要 Embed SAPI）',
    'runs-on: ubuntu-24.04' => '镜像必须与本地 GCC 同代（22.04 是 GCC 11 且已弃用）',
    "- 'cpp-src/x11/**'" => 'push/PR 必须按路径过滤，不能空跑每个分支',
    'Xvfb :99' => '必须有 X server，否则窗口门恒假',
    'fluxbox' => '必须有窗口管理器：裸 Xvfb 不 map/expose，Qt 官方 GUI CI 同为 Xvfb + fluxbox',
    'bash probes/linux_x11/build.sh' => '必须真跑 X11 端到端脚本',
    'name: Upload X11 failure logs' => '失败必须上传现场日志（build/run/watch + X 日志）',
    'if: failure()' => '日志上传必须在失败时才触发，成功不占 artifact 配额',
];
foreach ($linuxAnchors as $needle => $label) {
    if (!str_contains($linux, $needle)) {
        fail('linux-build.yml 缺环节：' . $label . '（' . $needle . '）');
    }
}
// 同一份判据在两个 job 里跑两遍：失败信息还得跨 job 找，且第二次执行不可能有新信息。
if (str_contains($linux, 'assert-workflows.php')) {
    fail('linux-build.yml 又跑了一遍结构判据 —— 它归 verify.yml 的 gate-fast 管');
}

// ★ build-skia.sh 平台化（Linux 接 Skia 的第 1 步）：Windows 产物必须逐字不变，
//   Linux 独立分支编 .a。判据既守"两个分支都在"，也守"Windows 参数不漏进 Linux"。
$skiaPlatformAnchors = [
    '--platform=*) platform="${arg#--platform=}" ;;' => '参数解析里真读 --platform（不是在 usage 文本里写着）',
    'MINGW*|MSYS*|CYGWIN*|Windows_NT*) platform="windows"' => '自动识别 Git Bash/MSYS 为 Windows',
    'Linux) platform="linux"' => '自动识别 Linux',
    '--dry-run) dry_run=1 ;;' => '参数解析里真设 dry_run（不是在 usage 文本里写着）',
    'dry_run=0' => 'dry-run 默认关闭',
    'args="$(cat "$HERE/args.gn.tpl")"' => '两平台共用同一份平台无关策略',
    'sed \'/extra_cflags = \\[ "-MT" \\]/d\'' => 'Linux 必须摘掉 MSVC 专用的 -MT',
    'skia_enable_ganesh = false' => 'Linux 关掉 Drift 不用的 Ganesh/GLX（否则要装 GL/gl.h 还白编一半）',
    'lib_ext="lib"' => 'Windows 收 .lib',
    'lib_ext="a"' => 'Linux 收 .a',
    '*."$lib_ext"' => '收库时用平台后缀变量，不是写死 .lib',
    '--args="$gn_args"' => 'gn gen 用按平台构造的 args',
];
foreach ($skiaPlatformAnchors as $needle => $label) {
    if (!str_contains($skiaScript, $needle)) {
        fail('build-skia.sh 缺平台化环节：' . $label . '（' . $needle . '）');
    }
}
// Windows 专属 gn 参数必须在 windows 分支内，不能无条件追加（否则 Linux 的 clang 会因 clang_win/win_vc 报错）。
// ★ 必须限定在 build_gn_args() 函数体内找分支：文件前面「平台决策」那里也有一个
//   `if [[ "$platform" == "windows" ]]`（决定 lib_ext），直接 strpos 会命中它，
//   截出来的块横跨整个函数，把 Linux 注释里的关键字也算进 Windows 块（踩过一次）。
// ★ 用正则抓整个函数体（`^build_gn_args\(\) \{` 到下一行顶格的 `}`），而不是
//   strpos("\n}\n")——后者会被函数内任意插入/缩进变化打乱定位，误报"找不到函数体"。
if (preg_match('/^build_gn_args\(\) \{\n([\s\S]*?)^\}\n/m', $skiaScript, $fnMatch) !== 1) {
    fail('build-skia.sh 找不到 build_gn_args() 函数体');
}
$fnBody = $fnMatch[1];
$winBlockStart = strpos($fnBody, "\n  else\n"); // linux 分支的 else 即 windows 块起点
$linuxBlockStart = strpos($fnBody, 'if [[ "$platform" == "linux" ]]; then');
if ($winBlockStart === false || $linuxBlockStart === false) {
    fail('build-skia.sh 的 build_gn_args() 缺 linux 分支或 else（windows 块）');
}
// windows 是 linux 的 else 分支：从 else 到函数末尾即其全部内容。
$winBlock = substr($fnBody, $winBlockStart);
$between = substr($fnBody, $linuxBlockStart, $winBlockStart - $linuxBlockStart);
foreach (['clang_win = ', 'win_vc = ', 'win_sdk = ', 'win_sdk_version = '] as $needle) {
    if (!str_contains($winBlock, $needle)) {
        fail('build-skia.sh 的 Windows args 分支缺 ' . $needle . ' —— Windows 产物会变');
    }
    if (str_contains($skiaScript, $needle) && !str_contains($winBlock, $needle)) {
        fail('build-skia.sh 的 ' . $needle . ' 漏在 Windows 分支外 —— Linux clang 会吃到 Windows 参数');
    }
}
// ★ Ganesh 开关必须只在 Linux 分支内：Windows 包（deps-v1）带 Ganesh/GL 是历史产物，
//   绝不能被这里的 Linux 优化改到 —— 否则已发布资产的复现性就破了。
if (!str_contains($between, 'skia_enable_ganesh = false')) {
    fail('build-skia.sh 的 Linux args 分支没关 Ganesh —— 会白编 GLX 后端并需要 GL/gl.h');
}
if (str_contains($winBlock, 'skia_enable_ganesh')) {
    fail('build-skia.sh 的 Windows 分支出现了 Ganesh 开关 —— 会改变已发布 deps-v1 的构建参数');
}
// dry-run 必须在任何网络动作之前：否则"验证一下平台决策"会先 clone 1.1GB 的 Skia。
$dryRunPos = strpos($skiaScript, 'if [[ "$dry_run" -eq 1 ]]');
$clonePos = strpos($skiaScript, 'git clone --no-checkout');
if ($dryRunPos === false || $clonePos === false || $dryRunPos > $clonePos) {
    fail('build-skia.sh 的 --dry-run 没放在 clone 之前 —— 本地验证会先拉 1.1GB 源码');
}

fwrite(STDOUT, '  ok     Skia 构建平台化：' . count($skiaPlatformAnchors) . " 个环节，Windows args 参数隔离\n");
fwrite(STDOUT, '  ok     真 AOT 通道完整：' . count($buildAnchors) . " 个环节\n");
fwrite(STDOUT, '  ok     发版先构建 Skia 再编译：' . count($releaseAnchors) . " 个环节\n");
fwrite(STDOUT, '  ok     依赖自动构建通道完整：' . count($depsAnchors) . " 个环节\n");
fwrite(STDOUT, "  ok     构建通道全在托管 runner 上（无 self-hosted 静默排队）\n");
fwrite(STDOUT, "  ok     gn / ninja 走 Skia 自带预编译包（不装 depot_tools）\n");
fwrite(STDOUT, "  ok     gn gen 在 Skia 源码目录内执行（source root 靠 .gn 定位）\n");
fwrite(STDOUT, '  ok     Linux X11 端到端门：' . count($linuxAnchors) . " 个环节\n");

// 平台桥按 OS 在 sources 里选（参考 typephp/examples/qt-taskboard）：
// 每份工程显式列 win32 / x11 桥，不用 ignore 从共享目录里“剪掉”另一半。
// 变异测试：把任一 `if:` 删掉或改回整目录 cpp-src ⇒ 两套 stub 同名，tpc 翻译期红。
function hasIgnoreEntry(string $text, string $path): bool
{
    return preg_match('/^\s*-\s+' . preg_quote($path, '/') . '\s*$/m', $text) === 1;
}
function hasConditionalSource(string $text, string $path, string $condition): bool
{
    $pattern = '/^\s*-\s*path:\s*' . preg_quote($path, '/')
        . '\s*\n\s*if:\s*' . preg_quote($condition, '/') . '\s*$/m';
    return preg_match($pattern, $text) === 1;
}

$baseYml = (string) @file_get_contents($root . '/build/base.yml');
$linuxProjYml = (string) @file_get_contents($root . '/probes/linux_x11/project.yml');
$linuxBuildSh = (string) @file_get_contents($root . '/probes/linux_x11/build.sh');

$bridgeProjects = [
    'build/base.yml' => $baseYml,
    'apps/hello/project.yml' => (string) @file_get_contents($root . '/apps/hello/project.yml'),
    'apps/showcase/project.yml' => (string) @file_get_contents($root . '/apps/showcase/project.yml'),
    'apps/tutorial/project.yml' => (string) @file_get_contents($root . '/apps/tutorial/project.yml'),
    'apps/users/project.yml' => (string) @file_get_contents($root . '/apps/users/project.yml'),
    'apps/watermark/project.yml' => (string) @file_get_contents($root . '/apps/watermark/project.yml'),
    'apps/pacing/project.yml' => (string) @file_get_contents($root . '/apps/pacing/project.yml'),
    'apps/bench/project.yml' => (string) @file_get_contents($root . '/apps/bench/project.yml'),
    'apps/dev/project.yml' => (string) @file_get_contents($root . '/apps/dev/project.yml'),
    'probes/io_pool/project.yml' => (string) @file_get_contents($root . '/probes/io_pool/project.yml'),
];
foreach ($bridgeProjects as $name => $text) {
    if ($name === 'build/base.yml') {
        foreach (['../../src/Platform/X11', '../../src/Platform/Win32', '../../cpp-src/x11', '../../cpp-src/win32'] as $path) {
            if (hasIgnoreEntry($text, $path)) {
                fail("{$name} 还在 ignore 平台目录 {$path} —— 已改为 sources 条件选择");
            }
        }
        continue;
    }
    if (preg_match('/^\s*-\s+\.\.\/\.\.\/cpp-src\s*$/m', $text) === 1) {
        fail("{$name} 仍在 sources 里整目录扫 ../../cpp-src —— 两套 stub 会混编");
    }
    if (!hasConditionalSource($text, '../../cpp-src/win32', "PHP_OS_FAMILY == 'Windows'")) {
        fail("{$name} 缺 Windows 条件 sources（cpp-src/win32）");
    }
    if (!hasConditionalSource($text, '../../cpp-src/x11', "PHP_OS_FAMILY == 'Linux'")) {
        fail("{$name} 缺 Linux 条件 sources（cpp-src/x11）");
    }
    foreach (['../../src/Platform/X11', '../../cpp-src/x11'] as $path) {
        if (hasIgnoreEntry($text, $path)) {
            fail("{$name} 还在 ignore {$path} —— 改用条件 sources 后应删掉");
        }
    }
}
if (!preg_match('/^cp\s+"\$probe_dir\/project\.yml"\s+"\$ws\/project\.yml"\s*$/m', $linuxBuildSh)) {
    fail('build.sh 没复制正式 project.yml —— 又退回 heredoc 临时配置，判据管不到');
}
if (!str_contains($linuxBuildSh, 'timeout --signal=TERM')) {
    fail('build.sh 的 wait 没有超时上界 —— 应用卡住会把 job 挂到 workflow 上限');
}
// 失败文本要能变成 Actions 注解，否则 [FAIL] 埋在几百行编译输出里没人看见。
if (!str_contains($linuxBuildSh, '::error title=')) {
    fail('build.sh 失败没挂 ::error 注解 —— CI 里只能翻日志找 [FAIL]');
}
// 后台进程必须走 trap 收尸：提前退出时残留的 ELF 会一直占 DISPLAY 与 CPU。
if (!str_contains($linuxBuildSh, 'trap cleanup EXIT')) {
    fail('build.sh 缺 trap cleanup EXIT —— 异常退出会留下孤儿进程');
}
if (!str_contains($linuxBuildSh, 'tail -f "$ws/run.log"')) {
    fail('build.sh 没把应用输出同步到 job 日志 —— XSendEvent 之后无法定位卡点');
}
if (!str_contains($linuxBuildSh, '${DRIFT_X11_TIMEOUT:-90}')) {
    fail('build.sh 的超时门限没有默认值 —— 无法在 CI 90s 与本地快测间切换');
}
if (!str_contains($linuxBuildSh, '/proc/$target/wchan') || !str_contains($linuxBuildSh, 'watch.log')) {
    fail('build.sh 缺内核态 watchdog —— 应用卡住时无法区分死循环/锁死锁/等事件');
}
// ★ 采样的必须是应用进程，不是 timeout 包装进程：GNU timeout 会 fork+exec，
//   `$!` 拿到的是包装进程，它的 utime 恒接近 0 ⇒ 用旧口径判"死循环"永远判不出来。
if (!str_contains($linuxBuildSh, 'app_pid_of') || !str_contains($linuxBuildSh, '/task/$1/children')) {
    fail('watchdog 没解析出应用子进程 —— 采的是 timeout 包装进程，utime 恒 0，判不出死循环');
}
if (!str_contains($linuxBuildSh, 'VmRSS')) {
    fail('watchdog 没采 VmRSS —— 区分不了「纯 CPU 空转」与「分配失控」');
}
// 栈才是归因依据：utime 只说在烧 CPU，bt 说烧在哪一行。
if (!str_contains($linuxBuildSh, 'thread apply all bt')
    || !str_contains($linuxBuildSh, 'DRIFT_X11_GDB')) {
    fail('build.sh 卡死时不抓 C++ 栈 —— 只能知道卡了，不知道为什么卡');
}
if (!str_contains($linux, 'kernel.yama.ptrace_scope=0')) {
    fail('linux-build.yml 没放开 ptrace_scope —— gdb 无法 attach 非后代进程，栈永远是空的');
}
// 24.04 镜像自带 needrestart：apt 升级到它管的包时会弹交互式对话框挂死整步。
// 同时 apt 必须有上界，否则卡住就是 45 分钟静默超时（实测踩到过 9 分钟无输出）。
if (!str_contains($linux, 'NEEDRESTART_SUSPEND')) {
    fail('linux-build.yml 的 apt 没关 needrestart —— 24.04 上会弹交互式对话框挂死');
}
if (!str_contains($linux, 'timeout 600 sudo env $APT_ENV apt-get install')) {
    fail('linux-build.yml 的 apt-get install 没有超时上界 —— 卡住会烧到 job 超时');
}
// 不加 --no-install-recommends 时 apt 会为 fluxbox 拉 ghostscript/feh/menu 等 44 个包、
// 67.3 MB，在镜像抖动时单步就能吃掉十分钟以上。
// 锚点必须带命令行前缀：只写 `--no-install-recommends` 会被上面那段注释满足（判据静态失效）。
if (!str_contains($linux, 'apt-get install --yes --no-install-recommends')) {
    fail('linux-build.yml 的 apt 没关 recommends —— fluxbox 会拖进 ghostscript 等无关包');
}
// GITHUB_ENV 只对后续 step 生效：本 step 内起的 fluxbox 必须靠 export 拿到 DISPLAY，
// 否则它 "Couldn't connect to XServer passing null display" 立刻退出，而门继续跑 ——
// 于是"加了 WM"的对照实验其实是空跑（这个坑真实发生过一轮）。
if (!str_contains($linux, 'export DISPLAY=:99')) {
    fail('linux-build.yml 的 Xvfb 步没有 export DISPLAY —— 同 step 里的 fluxbox 会连不上 X 直接退出');
}
if (!str_contains($linux, 'Acquire::Retries=5')) {
    fail('linux-build.yml 的 apt 没有重试/短超时 —— 镜像抖动时会干等');
}
$linuxMainPhp = (string) @file_get_contents($root . '/probes/linux_x11/app/main.php');
$x11ProviderPhp = (string) @file_get_contents($root . '/src/Platform/X11/X11SurfaceProvider.php');
$x11PlatformPhp = (string) @file_get_contents($root . '/src/Platform/X11/X11Platform.php');
foreach (['shown', 'viewport', 'surface ready', 'attached'] as $stage) {
    if (!str_contains($linuxMainPhp, $stage)) {
        fail("probe 缺少 {$stage} 阶段标记 —— 初始化卡住时无法定位到具体调用");
    }
}
// [PROBE] 细分打标已完成使命（真凶最终靠 WM 就绪检查 + 失败 artifact 定位），留着只是噪声。
if (str_contains($linuxMainPhp, '[PROBE]')) {
    fail('probe 又塞回 [PROBE] 打标 —— 已完成使命，留着只是噪声');
}
// ★ 反向锚点：probe 的"attach 前手动 resize"是绕过，会让 probe PASS 掩盖真实应用首帧空白。
//   尺寸必须由框架侧自己取，probe 只验证框架行为。
if (preg_match('/->resize\s*\(/', $linuxMainPhp)) {
    fail('probe 又在 attach 前手动 resize —— 会掩盖 X11RasterSurface 初始 0×0 的真实缺陷');
}
if (!str_contains($x11ProviderPhp, 'clientSize(') || !str_contains($x11ProviderPhp, '->resize(')) {
    fail('X11SurfaceProvider 创建表面时没按客户区定尺寸 —— 真实应用首帧会是 0×0 空白');
}
if (!str_contains($x11PlatformPhp, 'new X11SurfaceProvider($windowHandle, $this->displayHost())')) {
    fail('X11Platform 没把窗口宿主传给 surface provider —— 尺寸来源与 screen 不是单点');
}
if (hasIgnoreEntry($linuxProjYml, 'src/Platform/Win32') || hasIgnoreEntry($linuxProjYml, 'src/Platform/X11')) {
    fail('Linux project.yml 还在 ignore 平台目录 —— native/ 只含 X11 桥，无需剪枝');
}
fwrite(STDOUT, '  ok     平台桥按 OS 在 sources 里选择：' . count($bridgeProjects) . " 份工程无混编/无平台 ignore\n");

// ── Linux Skia 构建通道（build-only，不发布）────────────────────────────
// 目的：在公开 runner 上回答「gn gen 认不认 Linux args / .a 能不能真编出来」。
// 它不消费 deps-v1、不改锁、不发 tag —— 所以加反向锚点防止有人把 windows-deps
// 的发布段复制过来，把未接消费者的 Linux 包钉进不可变 tag。
$linuxDepsAnchors = [
    'runs-on: ubuntu-24.04' => '托管 Linux runner（不用会静默排队的 self-hosted）',
    'workflow_dispatch:' => '手动兜底',
    'clang --version' => '显式验 clang 并留版本在日志里',
    'libfontconfig1-dev' => 'Linux 独有：SkFontConfigInterface_direct 需要 fontconfig 头',
    "test -f /usr/include/fontconfig/fontconfig.h || {" => '装完必须复核头文件真的在（否则 apt 报成功但头缺失时仍会编译失败）',
    'apt-get install --yes --no-install-recommends' => 'apt 关 recommends（24.04 的加固配方）',
    '--platform=linux' => '显式钉 Linux 分支（不靠 uname 猜）',
    'tools/skia/build-skia.sh' => '复用同一份 Skia 构建配方',
    'out/Release-x64/*.a' => '产物是 Linux 静态库',
    'actions/upload-artifact@v4' => '产物交给下游评估',
    "grep -q -- '-MT'" => '拦截 MSVC 静态 CRT 标志泄漏到 Linux 构建',
];
foreach ($linuxDepsAnchors as $needle => $label) {
    if (!str_contains($linuxDeps, $needle)) {
        fail('linux-deps.yml 缺环节：' . $label . '（' . $needle . '）');
    }
}
// 反向锚点只查生效行：注释里解释"为什么不打包"是必要的，不该被自己拦住。
$linuxDepsLive = $stripComments($linuxDeps);
foreach ([
    'php tools/pack-deps.php' => '打包（会产出写死 win-x64 的包名）',
    'php tools/release-deps.php' => '发布依赖资产',
    'php tools/fetch-deps.php' => '发布后回验（本通道没有发布）',
    'git tag' => '打 tag（deps 版本是不可变的）',
    'deps.lock.json' => '改依赖锁（Linux 包还没有消费者）',
    'publish:' => '发布开关（build-only 通道不该有）',
    'contents: write' => '写权限（build-only 只需 contents: read）',
] as $needle => $label) {
    if (str_contains($linuxDepsLive, $needle)) {
        fail('linux-deps.yml 出现 ' . $label . ' —— 这是 build-only 通道，不许碰发布链路（' . $needle . '）');
    }
}
fwrite(STDOUT, '  ok     Linux Skia 构建通道：' . count($linuxDepsAnchors) . " 个 build-only 环节\n");
