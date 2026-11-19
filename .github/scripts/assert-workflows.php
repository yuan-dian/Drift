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
$windowsDepsFile = $root . '/.github/workflows/windows-deps.yml';
$linuxFile = $root . '/.github/workflows/linux-build.yml';
$linuxDepsFile = $root . '/.github/workflows/linux-deps.yml';
$crossDepsFile = $root . '/.github/workflows/deps.yml';

foreach (['windows-build.yml' => $buildFile, 'verify.yml' => $verifyFile,
          'release.yml' => $releaseFile, 'windows-deps.yml' => $windowsDepsFile,
          'linux-build.yml' => $linuxFile,
          'linux-deps.yml' => $linuxDepsFile,
          'deps.yml' => $crossDepsFile] as $name => $path) {
    if (!is_file($path)) {
        fail("{$name} 不存在 —— workflow 通道缺失");
    }
}
$build = (string) file_get_contents($buildFile);
$verify = (string) file_get_contents($verifyFile);
$release = (string) file_get_contents($releaseFile);
$windowsDeps = (string) file_get_contents($windowsDepsFile);
$linux = (string) file_get_contents($linuxFile);
$linuxDeps = (string) file_get_contents($linuxDepsFile);
$crossDeps = (string) file_get_contents($crossDepsFile);

foreach (['workflow' => ['.github/workflows/windows-build.yml', $build],
          'verify' => ['.github/workflows/verify.yml', $verify],
          'release' => ['.github/workflows/release.yml', $release],
          'windows-deps' => ['.github/workflows/windows-deps.yml', $windowsDeps],
          'linux' => ['.github/workflows/linux-build.yml', $linux],
          'linux-deps' => ['.github/workflows/linux-deps.yml', $linuxDeps],
          'deps' => ['.github/workflows/deps.yml', $crossDeps]] as $file => [$path, $text]) {
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
// 取包失败的错误提示必须指向现行发布通道 deps.yml；指回已退役的
// windows-deps.yml 会让人手动触发一个 build-only 作业，永远等不到资产。
if (!str_contains($build, '先手动触发 deps.yml（勾 publish）')) {
    fail('windows-build.yml 取包失败的提示仍没指向 deps.yml —— 照旧提示操作永远发不出包');
}
if (str_contains($build, 'windows-deps.yml 并置 publish')) {
    fail('windows-build.yml 又指回已退役的 windows-deps.yml publish —— 它是 build-only，不发布');
}

// 发版链路：deps 先跑；但 build-only 的临时产物不能覆盖已发布的 deps 锁。
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

// Windows 构建通道：只产 win-x64 包，发布统一交给 deps.yml。
// 让它自己 commit/tag 会在 Linux 包还没就绪时写出半份锁（deps-v2 必须双包齐全）。
$stripComments = static fn (string $text): string => (string) preg_replace('/^\s*#.*$/m', '', $text);

// ★ 红窗守卫：提升 DEPS_VERSION 的那笔 push 不能触发 AOT —— v2 资产要等
//   deps.yml 两岸真编后才发布，提前 fetch 必然红一段。AOT 改由 deps 成功后的
//   workflow_run 补跑；只在 push 侧加 paths-ignore 而不接补跑，会让版本提升后
//   完全没有真机门，同样是静默缺口。两侧必须成对存在。
$buildLive = $stripComments($build);
if (!str_contains($buildLive, 'paths-ignore: [DEPS_VERSION]')) {
    fail('windows-build.yml 的 push / pull_request 必须 paths-ignore: [DEPS_VERSION] —— 否则版本提升会去 fetch 尚未发布的资产');
}
if (!str_contains($buildLive, 'workflows: ["Cross-platform deps"]')) {
    fail('windows-build.yml 缺 workflow_run 订阅 Cross-platform deps —— 版本提升后不会有真机门补跑');
}
if (!str_contains($buildLive, 'types: [completed]')) {
    fail('windows-build.yml 的 workflow_run 缺 types: [completed]');
}
if (!str_contains($buildLive, "github.event.workflow_run.conclusion == 'success'")) {
    fail('windows-build.yml 的 aot job 缺 conclusion == success 条件 —— deps 失败时也会拿旧包白编');
}
if (!str_contains($crossDeps, 'name: Cross-platform deps')) {
    fail('deps.yml 的 name 必须是 Cross-platform deps —— workflow_run 按 name 匹配，改名会让补跑永久失联');
}
fwrite(STDOUT, "  ok     依赖发布与 AOT 红窗隔离（版本提升不提前触发、发布成功补跑）\n");
$windowsDepsAnchors = [
    'workflow_dispatch:' => '手动兜底',
    'workflow_call:' => '发布编排与产品发版可复用',
    'tools/skia/build-skia.sh --rev="$REV"' => '从固定 commit 编 Skia',
    'php tools/pack-deps.php --platform=win-x64' => '只打 Windows 包',
    'actions/upload-artifact@v4' => '产物交给发布编排',
    'dist/drift-deps-win-x64.zip' => '上传 Windows 资产',
    'Ensure fonts are present' => '构建前确认字体在树里',
];
foreach ($windowsDepsAnchors as $needle => $label) {
    if (!str_contains($windowsDeps, $needle)) {
        fail('windows-deps.yml 缺环节：' . $label . '（' . $needle . '）');
    }
}
$windowsDepsLive = $stripComments($windowsDeps);
foreach (['git tag', 'php tools/release-deps.php', 'php tools/fetch-deps.php',
          'contents: write', 'commit_lock', 'publish='] as $needle) {
    if (str_contains($windowsDepsLive, $needle)) {
        fail('windows-deps.yml 又碰发布链路 ' . $needle . ' —— 双平台包必须先合成一份锁再发');
    }
}

// 发布编排：两个构建作业各出各包，唯一一个 publish 作业写锁 / tag / Release。
$crossDepsAnchors = [
    'paths: [DEPS_VERSION]' => '依赖版本变化才触发发布',
    'uses: ./.github/workflows/windows-deps.yml' => '消费 Windows 构建通道',
    'uses: ./.github/workflows/linux-deps.yml' => '消费 Linux 构建通道',
    'needs: [windows, linux]' => '等两个平台都构建完',
    'actions/download-artifact@v4' => '取回两个平台资产',
    'php tools/pack-deps.php --platform=win-x64' => '合成锁里的 win-x64 条目',
    'php tools/pack-deps.php --platform=linux-x64' => '合成锁里的 linux-x64 条目',
    'php tools/sync-lock-anchors.php' => '同步 README 两个平台哈希',
    'php tools/gen-version.php' => '同步 DEPS_VERSION 派生产物（漏了 version --check 必红）',
    'git add deps.lock.json README.md src/Core/Version.php apps/*/project.yml' => '提交派生产物（否则 main 的 DEPS_TAG 永远停旧版）',
    'php tools/version.php --check' => '发布前版本锚点全绿',
    'git tag "$TAG"' => '唯一写 tag 的地方',
    'php tools/release-deps.php' => '统一上传双平台资产',
    '--platform=win-x64 --force' => '从已发布资产回验 Windows 包',
    '--platform=linux-x64 --force' => '从已发布资产回验 Linux 包',
];
foreach ($crossDepsAnchors as $needle => $label) {
    if (!str_contains($crossDeps, $needle)) {
        fail('deps.yml 缺环节：' . $label . '（' . $needle . '）');
    }
}

// ★ 构建通道必须能在托管 runner 上真跑。self-hosted 在仓库没有注册 runner 时
//   不会报错，只会静默排队到超时取消 —— 那种"YAML 里有、实际从不执行"的通道
//   是最贵的一种假绿。所以出现 self-hosted 一律红，改为托管镜像 + 自动探测工具链。
//   只看生效行：注释里讨论这件事本身是需要的，不该被自己拦住。
foreach (['windows-build.yml' => $build, 'verify.yml' => $verify,
          'release.yml' => $release, 'windows-deps.yml' => $windowsDeps,
          'linux-deps.yml' => $linuxDeps, 'deps.yml' => $crossDeps] as $name => $text) {
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
    fail('build-skia.sh 调 gn gen 时没把工作目录切到 skia_dir —— gn 会报 Can\'t find source root');
}

if (str_contains($windowsDepsLive, 'depot_tools')) {
    fail('windows-deps.yml 又装回 depot_tools —— gn.exe 在 CIPD 子目录里，bootstrap 改的 PATH 传不出子进程');
}
// SDK 版本必须按 x.y.z.w 过滤：Include 下还有 wdf / um / shared 这些目录，
// 按名字排序取最后一个会拿到 "wdf"，喂给 gn 之后报的错跟 SDK 八竿子打不着。
if (!str_contains($windowsDepsLive, '^\d+\.\d+\.\d+\.\d+$')) {
    fail('windows-deps.yml 探测 Windows SDK 版本时没有按 x.y.z.w 过滤 —— 会取到 wdf 之类的目录');
}

$windowsDepsJob = substr($windowsDeps, (int) strpos($windowsDeps, 'jobs:'));
if (!str_contains($windowsDepsJob, 'runs-on: windows-2022')) {
    fail('windows-deps.yml 的 build job 必须跑在托管 windows-2022 上');
}
if (preg_match('/^\s{4}timeout-minutes:\s*(\d+)\s*$/m', $windowsDepsJob, $m) !== 1 || (int) $m[1] < 180) {
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
    "- 'cpp-src/skia/**'" => 'Skia 桥改动也必须触发 X11 门',
    'libfontconfig1' => 'Skia 的 Fc* 外部符号需要 fontconfig 运行时库',
    'name: Download Linux Skia artifact' => 'X11 门必须消费 build-only 产物，不自己重编 Skia',
    'gh run download' => '从最近的 linux-deps 成功 run 取 artifact，不硬编码 run id',
    'DRIFT_SKIA_HOME' => '把下载的产物路径交给 build.sh',
    'skia_run_id' => '手动重跑时可钉住具体 Skia 构建 run',
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
    'skia_enable_ganesh = false' => '两岸共用：关掉 Drift 不用的 Ganesh/GL（软件光栅零 GPU 消费者）',
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
// ★ deps-v2 起 Ganesh 也进两岸共用裁剪循环：drift_skia.cc 全部走
//   SkCanvas::MakeRasterDirectN32 软件光栅，零 GrDirectContext / GL / D3D
//   上下文调用，Windows 编 src/gpu/ganesh/gl/ 纯属白编。正向判据（共用循环
//   含 Ganesh）并入下方 $sharedTrimFlags；这里守反向：平台分支不得单独再写。
foreach (['Linux' => $between, 'Windows' => $winBlock] as $branchName => $branch) {
    if (str_contains($branch, 'skia_enable_ganesh')) {
        fail('build-skia.sh 的 ' . $branchName . ' 分支单独出现 Ganesh 开关 —— 共用循环才是唯一写点');
    }
}
// ★ deps-v2 两岸共用裁剪：Drift 只链接 core 软件光栅 + 图片解码，
//   build/base.yml 明确不链 pdf / skottie / svg / sksg / skparagraph /
//   skshaper / skunicode / icu / perfetto / piex / dng_sdk，也零 GPU 后端。
//   十个开关（含 Ganesh）必须写在平台分支之前（两平台都吃到），且不能只在
//   注释里。任何一条漏出共用区 = 某平台又白编死库。
$sharedTrimFlags = [
    'skia_enable_pdf = false',
    'skia_enable_skottie = false',
    'skia_enable_svg = false',
    'skia_enable_skparagraph = false',
    'skia_enable_skshaper = false',
    'skia_use_icu = false',
    'skia_use_perfetto = false',
    'skia_use_piex = false',
    'skia_use_dng_sdk = false',
    'skia_enable_ganesh = false',
];
// 只认 for 循环体里的带引号字面量，注释里写字面量不算。用位置截取而不是
// 正则匹配 `; do` —— shell 的 `; do` / 换行 do 都合法，正则太脆。
$trimStart = strpos($fnBody, 'for flag in ');
$trimEnd = $trimStart === false ? false : strpos($fnBody, "\n  done", $trimStart);
if ($trimStart === false || $trimEnd === false) {
    fail('build-skia.sh 找不到共用的 for flag in 裁剪循环');
}
$trimLoopBody = substr($fnBody, $trimStart, $trimEnd - $trimStart);
$commonBlock = substr($fnBody, 0, $linuxBlockStart);
if ($trimStart >= $linuxBlockStart) {
    fail('build-skia.sh 的裁剪循环落在平台分支内 —— Windows 没吃到 deps-v2 的裁剪');
}
foreach ($sharedTrimFlags as $flag) {
    $quoted = "'" . $flag . "'";
    if (!str_contains($trimLoopBody, $quoted)) {
        fail('build-skia.sh 的共用裁剪循环缺 ' . $quoted . ' —— 该模块会被白编进 deps-v2 产物');
    }
    if (str_contains($winBlock, $flag) || str_contains($between, $flag)) {
        fail('build-skia.sh 的 ' . $flag . ' 漏进平台分支 —— 共用区才是唯一写点');
    }
}
if (!str_contains($trimLoopBody, 'args+=')) {
    fail('build-skia.sh 的裁剪循环没有把开关追加进 args —— 参数不会进 gn gen');
}
if (!str_contains($commonBlock, 'for flag in ')) {
    fail('build-skia.sh 的共用裁剪循环不在 build_gn_args() 平台分支之前');
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
fwrite(STDOUT, '  ok     Windows 依赖构建通道：' . count($windowsDepsAnchors) . " 个环节（不发布）\n");
fwrite(STDOUT, '  ok     双平台依赖发布编排：' . count($crossDepsAnchors) . " 个环节\n");
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

// ★ deps 产物下限必须与链接清单对账，不能写死数字：deps-v1 时 win 有 24 个
//   .lib，minLibs 写成 20；deps-v2 裁掉 9 个开关后真编只剩 13 个，CI 直接报
//   "只有 13 个 *.lib，少于 20" 而 pack 失败 —— 那是发布链的硬阻塞，包根本
//   发不出来。真正的下界是 base.yml 实际链接的 skia .lib 数（12）；少一个就
//   链接不过，多出来的是构建附带产物。让 Platforms.php 的 win minLibs 跟着
//   链接清单走，两边漂了就红，不再拍脑袋。
preg_match_all('#cpp/skia/out/Release-x64/([A-Za-z0-9_]+)\.lib#', $baseYml, $linkedLibs);
$winLinkedCount = count(array_unique($linkedLibs[1] ?? []));
$platformsPhp = (string) @file_get_contents($root . '/tools/Deps/Platforms.php');
if ($winLinkedCount < 1 || !preg_match("/'minLibs'\s*=>\s*(\d+)/", $platformsPhp, $mm)) {
    fail('无法从 base.yml / Platforms.php 读出 win 链接库数与 minLibs');
}
if ((int) $mm[1] !== $winLinkedCount) {
    fail('Platforms.php 的 win minLibs=' . $mm[1] . ' 与 build/base.yml 实际链接的 '
        . $winLinkedCount . ' 个 skia .lib 不一致 —— 写死的下界会随裁剪漂移，卡死 deps 发布');
}

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
foreach (['shown', 'viewport', 'surface ready', 'attached', '[BACKEND]', '[FONT]'] as $stage) {
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

// ── X11 侧真正消费 Skia 产物 ─────────────────────────────────────────
// 只编出 .a 不算接上渲染后端。这里守住四段链路：surface 用 SkiaCanvas、
// provider 有桥就切过去、C++ 桥跨平台、工程真的链 13 个 .a。
$x11SkiaSurfacePhp = (string) @file_get_contents($root . '/src/Platform/X11/X11SkiaSurface.php');
$skiaBridgeCc = (string) @file_get_contents($root . '/cpp-src/skia/drift_skia.cc');
$x11PresentCc = (string) @file_get_contents($root . '/cpp-src/x11/drift_x11.cc');
// toRgba() 给的是逐字节 RGBA，XImage 的 32bpp masks 是 R=0xff0000/G=0xff00/B=0xff。
// 打包时必须把 R 放进 bits 16-23；照抄 Win32 的 BGRA 口径会得到整体红蓝互换
// （#009688 显示成 #889600）—— 几何、字体、事件全对，只有颜色错，极难归因。
if (!str_contains($x11PresentCc, '0xff000000u | ((uint32_t)src[i*4] << 16)')) {
    fail('X11 贴屏没把 RGBA 的 R 打包进 XImage 的 bits 16-23 —— 整套配色会红蓝互换');
}
if (str_contains($x11PresentCc, '((uint32_t)src[i*4+2] << 16)')) {
    fail('X11 贴屏又按 BGRA 打包了 —— 那是 Win32 SetDIBitsToDevice 的口径，Xlib 不是');
}
$x11SkiaAnchors = [
    [$x11SkiaSurfacePhp, 'use Drift\\Rendering\\SkiaCanvas;', 'X11 Skia surface 必须用 SkiaCanvas'],
    [$x11SkiaSurfacePhp, "drift_x11_present(\n                \$this->windowHandle,", 'X11 Skia surface 必须走 X11 贴屏桥'],
    [$x11SkiaSurfacePhp, '->toRgba()', 'X11 Skia surface 必须取回像素（Xlib 没有零拷贝直贴）'],
    [$x11SkiaSurfacePhp, '->dispose()', 'X11 Skia surface 每帧必须释放原生画布'],
    [$x11ProviderPhp, "function_exists('drift_skia_canvas_create')", 'provider 必须运行期探测 Skia 桥（纯 PHP 单测仍走软光栅）'],
    [$x11ProviderPhp, 'new X11SkiaSurface(', 'provider 有桥时必须切到 Skia 表面'],
    [$x11ProviderPhp, 'new X11RasterSurface(', 'provider 必须保留无桥时的软光栅回退'],
    [$skiaBridgeCc, "#ifdef _WIN32\n// ★ 必须在 phpx.h", 'Skia 桥的 Windows 专属头必须被平台宏包住'],
    [$skiaBridgeCc, '#include <windows.h>', 'Windows 分支仍要包含 windows.h（不能删了 Windows 支持）'],
    [$skiaBridgeCc, 'readlink("/proc/self/exe"', 'Linux 字体定位必须走 /proc/self/exe，而不是 cwd'],
    [$linuxProjYml, '- -Icpp/skia-linux', 'Linux 工程必须把 Skia artifact 根作为 include 根'],
    [$linuxProjYml, '- -lskia', 'Linux 工程必须链 libskia.a'],
    [$linuxProjYml, '- -lfontconfig', 'libskia.a 引用 Fc*，必须链 fontconfig'],
    [$linuxProjYml, '- -Wl,--start-group', '13 个静态库必须成组链接，避开手写顺序的反向依赖'],
    [$linuxProjYml, '- -Wl,--end-group', '静态库组必须闭合'],
    [$linuxBuildSh, 'skia_home="${DRIFT_SKIA_HOME:-$repo/cpp/skia-linux}"', 'build.sh 必须接收 artifact 路径'],
    [$linuxBuildSh, 'cpp-src/skia/drift_skia.cc', 'build.sh 必须把 Skia 桥复制进隔离工作区'],
    [$linuxBuildSh, "ldconfig -p | awk '/libfontconfig\\.so\\.1/", 'build.sh 必须从运行时库现场建 linker 软链'],
    [$linuxBuildSh, 'Linux Skia 产物缺失', '缺产物必须响亮失败，不能静默退回软光栅'],
    [$linuxBuildSh, "grep -q '\\[BACKEND\\] skia'", 'build.sh 必须断言 probe 真用了 Skia 后端'],
    [$linuxBuildSh, "grep -q '\\[FONT\\] ready=1'", 'build.sh 必须断言目录字体已就绪（否则文字静默消失）'],
    [$linuxBuildSh, '找不到目录字体', 'build.sh 必须把 Skia 的字体缺失诊断当成失败'],
    [$linux, '--workflow linux-deps.yml', 'CI 必须从 linux-deps 成功 run 取产物'],
    [$linux, '--status success', '只取成功构建的 artifact，失败 run 的包不能用'],
];
foreach ($x11SkiaAnchors as [$text, $needle, $label]) {
    if (!str_contains($text, $needle)) {
        fail('X11 接 Skia 链路断裂：' . $label . '（' . $needle . '）');
    }
}
// 这些库是裁剪时被判定为零引用的；一旦有人把它们写回 Linux 链接清单，
// 就是在给不需要的模块重新付体积，且会绕开 13 个 .a 的产物契约。
foreach (['- -lharfbuzz', '- -licu', '- -lskottie', '- -lsksg', '- -lskparagraph', '- -lskshaper'] as $needle) {
    if (str_contains($linuxProjYml, $needle)) {
        fail('Linux project.yml 又链回已裁剪的库 ' . $needle . ' —— 与 base.yml 的零引用裁决冲突');
    }
}
if (str_contains($x11SkiaSurfacePhp, 'drift_win_present')) {
    fail('X11 Skia surface 混进了 Win32 直贴桥 —— 两套 present ABI 不通用');
}
fwrite(STDOUT, '  ok     X11 真正消费 Skia：' . count($x11SkiaAnchors) . " 个链路环节 + 裁剪库反向判据\n");

// ── X11 输入桥：char / wheel 必须真到框架 ───────────────────────────────
// Windows 桥有 WM_CHAR → KeyEvent::character() 与 WM_MOUSEWHEEL → ScrollEvent；
// X11 桥若只吐 key/pointer，TextField 就打不了字、滚轮只会造成假点击 ——
// 这两样几何和颜色全对也看不出来，必须有端到端锚点。
$x11SendInputPy = (string) @file_get_contents($root . '/probes/linux_x11/send_input.py');
$x11InputAnchors = [
    // C++ 桥：文本来自 KeySym，不用随 locale 漂移的 XLookupString
    [$x11PresentCc, 'utf8FromKeySym', 'X11 桥缺 KeySym→UTF-8 映射'],
    [$x11PresentCc, 'g_pendingChar', 'X11 桥缺 key→char 暂存（一次 XNextEvent 只吐一条事件）'],
    [$x11PresentCc, '0x01000000UL', 'X11 桥缺 Unicode keysym 映射（输入法上屏会丢字）'],
    [$x11PresentCc, 'out.set("type", "char")', 'X11 桥不吐 char 事件 —— TextField 永远打不了字'],
    [$x11PresentCc, 'out.set("type", "wheel")', 'X11 桥不吐 wheel 事件 —— 滚轮退化成假点击'],
    [$x11PresentCc, 'ev.button.button >= 4 && ev.button.button <= 7', 'X11 桥没拦滚轮按钮 —— 会产生 pointer down/up'],
    [$x11PresentCc, 'case 0xffbe: return "F1"', 'keyName 缺 F1..F12 映射（功能键做不了加速键）'],
    // PHP 翻译
    [$x11PlatformPhp, "if (\$type === 'char')", 'X11Platform 没接 char 事件'],
    [$x11PlatformPhp, 'KeyEvent::character(', 'char 没翻译成可插入文本'],
    [$x11PlatformPhp, "if (\$type === 'wheel')", 'X11Platform 没接 wheel 事件'],
    [$x11PlatformPhp, 'ScrollEvent::make(', 'wheel 没翻译成 ScrollEvent'],
    [$x11PlatformPhp, 'Axis::Horizontal', 'wheel 没处理横轴'],
    // probe 与构建脚本
    [$linuxMainPhp, 'dispatchInput(InputEvent $event', 'probe 没观测派发到框架的输入'],
    [$linuxMainPhp, '[INPUT] char=', 'probe 没打印 char 标记 —— 断言没有锚点'],
    [$linuxMainPhp, '[INPUT] scroll delta=', 'probe 没打印 wheel 标记 —— 断言没有锚点'],
    [$linuxBuildSh, 'send_input.py', 'build.sh 没注入真实键鼠事件'],
    [$linuxBuildSh, "grep -q '\\[INPUT\\] char=a'", 'build.sh 没断言文本输入到框架'],
    [$linuxBuildSh, "grep -q '\\[INPUT\\] scroll delta=-1 axis=vertical'", 'build.sh 没断言竖轮前推及符号'],
    [$linuxBuildSh, "grep -q '\\[INPUT\\] scroll delta=1 axis=vertical'", 'build.sh 没断言竖轮后推及符号'],
    [$x11SendInputPy, 'XSendEvent', '注入脚本必须直接投递原生事件（XTest 会被 WM 抢走焦点）'],
    [$x11SendInputPy, 'XKeysymToKeycode', '按键注入必须用 keysym→keycode 映射'],
    [$x11SendInputPy, 'struct.pack_into', '必须按 XEvent 布局填字段（state@72 / keycode@76）'],
];
foreach ($x11InputAnchors as [$text, $needle, $label]) {
    if (!str_contains($text, $needle)) {
        fail('X11 输入桥断裂：' . $label . '（' . $needle . '）');
    }
}
// 反向锚点：ButtonPress/Release 一旦合并处理，滚轮就同时产生 pointer down/up。
if (str_contains($x11PresentCc, 'case 4: case 5:')) {
    fail('X11 桥又把 ButtonPress/Release 合并处理 —— 滚轮会退化成假点击');
}
fwrite(STDOUT, '  ok     X11 输入桥 char/wheel：' . count($x11InputAnchors) . " 个链路环节 + 滚轮反向判据\n");

// ── X11 IO 池：core 池体 + 自管道唤醒 + ThreadTaskHost 端到端 ──────────
// Windows 侧 ThreadTaskHost 靠 PostMessageW 唤醒；X11 侧若只剩空实现，
// ThreadTaskHost 会静默不可用（submit 恒 false、poll 恒空）—— 界面照常渲染，
// 只有异步任务永不结算，属于"不报错、只是没效果"的静默缺口。这里从五个文件
// 交叉锚定整条链路：池体编进工程、钩子在 start 之前装、wait 吐 wake、
// PHP 翻译 wake→Woken、probe 与 build.sh 有真判据。
$x11IoWakeCc = (string) @file_get_contents($root . '/cpp-src/x11/io_wake.cc');
$x11IoWakeH = (string) @file_get_contents($root . '/cpp-src/x11/io_wake.h');
$coreIoPoolCc = (string) @file_get_contents($root . '/cpp-src/core/io_pool.cc');
$coreIoPoolH = (string) @file_get_contents($root . '/cpp-src/core/io_pool.h');
$ioPoolProjYml = (string) @file_get_contents($root . '/probes/linux_x11/project.yml');
$x11IoAnchors = [
    // 池体是平台无关的那一份（不许在 x11 里另写一套线程/队列）
    [$coreIoPoolH, 'using WakeFn = void (*)(void *ctx)', 'core 池体缺 WakeFn 钩子声明'],
    [$coreIoPoolCc, 'setWakeHook(', 'core 池体缺 setWakeHook 实现'],
    [$x11PresentCc, '../core/io_pool.h', 'drift_x11.cc 没引 core 池体头'],
    [$x11PresentCc, 'drift::io::setWakeHook', 'X11 桥没装唤醒钩子'],
    // 自管道：非阻塞 pipe，worker 写、UI drain
    [$x11IoWakeH, 'ioWakeReadFd', '自管道缺读端 fd 暴露（select 无法监听）'],
    [$x11IoWakeCc, 'O_NONBLOCK', '管道未设非阻塞 —— 满时 worker 会卡死'],
    [$x11IoWakeCc, '::pipe(fds)', '自管道没真建 pipe'],
    [$x11PresentCc, 'drift::x11::ioWakeNotify()', '唤醒钩子没写管道'],
    // wait 吐 wake + 真事件优先 + 不丢唤醒
    [$x11PresentCc, 'out.set("type", "wake")', 'wait 不吐 wake 事件 —— X11Platform 翻译分支永不被触发'],
    [$x11PresentCc, 'g_wakePending', '缺唤醒暂存 —— 被真事件抢先时那条唤醒会丢'],
    [$x11PresentCc, 'XPending(d) > 0', 'wait 没让真事件优先于 IO 唤醒'],
    [$x11PresentCc, 'FD_SET(wakeFd, &fds)', 'select 没监听唤醒管道读端'],
    // ABI 转发：不再是空实现
    [$x11PresentCc, 'drift::io::submit(', 'drift_io_submit 没转发到池体'],
    [$x11PresentCc, 'drift::io::poll()', 'drift_io_poll 没转发到池体'],
    [$x11PresentCc, 'drift::io::stop()', 'drift_io_stop 没转发到池体'],
    // 工程接线：core 编进 probe，build.sh 复制两份源
    [$ioPoolProjYml, '- core', 'probe 工程没把 core/ 列入 sources —— io_pool.cc 不会被编译'],
    [$linuxBuildSh, 'cpp-src/core/io_pool.cc', 'build.sh 没复制 core/io_pool.* —— 链接不到池体'],
    [$linuxBuildSh, 'cpp-src/x11/io_wake.cc', 'build.sh 没复制 x11/io_wake.* —— 链接不到唤醒钩子'],
    // PHP 翻译（已存在的分支，锚住不许被删）
    [$x11PlatformPhp, "if (\$type === 'wake')", 'X11Platform 没接 wake 事件'],
    [$x11PlatformPhp, 'WakeReason::Woken', 'wake 没翻译成 Woken'],
    // probe 与构建脚本的真判据
    [$linuxMainPhp, 'probeIoPool(', 'probe 没跑 IO 池判据'],
    [$linuxMainPhp, '[IO] wake woken=', 'probe 没打唤醒计数锚点'],
    [$linuxMainPhp, 'ThreadTaskHost', 'probe 没验 ThreadTaskHost 端到端'],
    [$linuxBuildSh, "grep -q '\\[IO\\] wake woken=8/8'", 'build.sh 没断言 8/8 唤醒'],
    [$linuxBuildSh, "grep -q '\\[IO\\] e2e sleep=20 echo=hi errors=none'", 'build.sh 没断言 ThreadTaskHost 结算值'],
    [$linuxBuildSh, "grep -q '\\[IO\\] threads_after_stop=0'", 'build.sh 没断言关池'],
];
foreach ($x11IoAnchors as [$text, $needle, $label]) {
    if (!str_contains($text, $needle)) {
        fail('X11 IO 池链路断裂：' . $label . '（' . $needle . '）');
    }
}
// 反向锚点：X11 桥里不许再现空实现（旧 submit 的 `(void)id; (void)handler;`
// 吞参 + 恒 false、旧 poll 的恒空串）。用函数体形状而非裸 `return false;`
// ——后者会命中 is_alive 的合法空指针检查。
if (str_contains($x11PresentCc, '(void)id; (void)handler;')) {
    fail('drift_x11.cc 里又有 submit 吞参恒 false 的空实现 —— ThreadTaskHost 会静默不可用');
}
if (preg_match('/php_drift_io_poll\s*\([^)]*\)\s*\{\s*return\s+String\(""\)/', $x11PresentCc)) {
    fail('drift_x11.cc 里又有 poll 恒空串的空实现 —— IO 完成结果会被吞掉');
}
fwrite(STDOUT, '  ok     X11 IO 池 + ThreadTaskHost：' . count($x11IoAnchors) . " 个链路环节 + 空实现反向判据\n");

// ── X11 图片解码：Skia codec 解 PNG/JPEG/WebP（与 win32/WIC 同契约）─────
// 缺了它 Linux 只能走纯 PHP PNG 路径：AI 出图多是 jpg ⇒ 界面显示占位图，
// 而几何/布局/事件全对，属"不报错、只是图出不来"的静默缺口。锚定整条链路：
// 解码实现用 SkCodec + RGBA 直出、ABI 包装、stub 声明、工程复制、probe 判据。
$x11DecodeCc = (string) @file_get_contents($root . '/cpp-src/x11/image_decode.cc');
$x11DecodeH = (string) @file_get_contents($root . '/cpp-src/x11/image_decode.h');
$x11StubPhp = (string) @file_get_contents($root . '/cpp-src/x11/drift_x11.stub.php');
$imageDecodeAnchors = [
    // 解码实现：Skia codec + 解码期缩放 + RGBA 直出
    [$x11DecodeH, 'decodeImageToRgba', '解码接口缺失'],
    [$x11DecodeCc, 'include/codec/SkCodec.h', '没引 Skia codec 头'],
    [$x11DecodeCc, 'SkCodec::MakeFromData', '没用 SkCodec 建解码器'],
    [$x11DecodeCc, 'std::lround(', '没自己算精确目标尺寸（依赖 codec 粒度会让 maxWidth 静默失效）'],
    [$x11DecodeCc, 'SkCodec::kInvalidScale', '缺缩放回退分支（codec 拒绝小图缩放时会静默不缩）'],
    [$x11DecodeCc, 'kRGBA_8888_SkColorType', '没用 RGBA 输出格式'],
    [$x11DecodeCc, 'kUnpremul_SkAlphaType', '用了预乘 alpha —— 颜色会偏暗'],
    [$x11DecodeCc, 'codec->getPixels(', '没取像素'],
    [$x11DecodeCc, 'outRgba.assign(', '没把字节写回输出（assign 保留 \0）'],
    // ABI 包装 + stub 声明
    [$x11PresentCc, 'php_drift_image_decode', 'drift_x11.cc 没做解码 ABI 包装'],
    [$x11PresentCc, 'drift::x11::decodeImageToRgba(', 'ABI 没转发到解码实现'],
    [$x11StubPhp, 'function drift_image_decode(', 'x11 stub 没声明解码函数 —— php 侧静态调用编不过'],
    // 工程接线
    [$linuxBuildSh, 'cpp-src/x11/image_decode.cc', 'build.sh 没复制解码实现到隔离工程'],
    // probe 判据（三格式 + 通道序 + 缩放 + 失败路径）
    [$linuxMainPhp, 'probeImageDecode(', 'probe 没跑解码判据'],
    [$linuxMainPhp, "const PNG_8X8", 'probe 没内嵌 PNG 测试图'],
    [$linuxMainPhp, "const WEBP_8X8", 'probe 没内嵌 WebP 测试图'],
    [$linuxMainPhp, "const JPG_8X8", 'probe 没内嵌 JPEG 测试图'],
    [$linuxMainPhp, 'drift_image_decode($paths[\'png\'], 4)', 'probe 没验 maxWidth 缩放'],
    [$linuxBuildSh, "grep -q '\\[DEC\\] jpg 8x8 bytes=256'", 'build.sh 没断言 JPEG 解码（核心价值）'],
    [$linuxBuildSh, "grep -q '\\[DEC\\] png red(R=255,B=0) blue(R=0,B=255)'", 'build.sh 没断言通道序'],
    [$linuxBuildSh, "grep -q '\\[DEC\\] scaled maxWidth=4 => 4x4 bytes=64'", 'build.sh 没断言缩放'],
    [$linuxBuildSh, "grep -q '\\[DEC\\] missing=empty'", 'build.sh 没断言失败路径'],
];
foreach ($imageDecodeAnchors as [$text, $needle, $label]) {
    if (!str_contains($text, $needle)) {
        fail('X11 图片解码链路断裂：' . $label . '（' . $needle . '）');
    }
}
fwrite(STDOUT, '  ok     X11 图片解码 Skia codec：' . count($imageDecodeAnchors) . " 个链路环节\n");

// ── X11 窗口管理：ICCCM 最小尺寸 / EWMH 最大化 / ICCCM 最小化 ──────────
// 这三项此前在 X11WindowHost 是空实现/常量 false —— 应用读回永远"不支持"，
// 属静默缺口。锚定整条读写闭环：桥函数 + 读回 + stub + WindowHost 去 no-op +
// probe 判据 + build.sh grep。反向锚点禁止空实现回潮。
$x11HostPhp = (string) @file_get_contents($root . '/src/Platform/X11/X11WindowHost.php');
$x11WmAnchors = [
    // 桥：写侧 ICCCM/EWMH + 读侧属性
    [$x11PresentCc, 'XSetWMNormalHints(', 'setMinSize 没写 XSizeHints'],
    [$x11PresentCc, 'XMoveResizeWindow(', 'place 没调 XMoveResizeWindow'],
    [$x11PresentCc, '_NET_WM_STATE_MAXIMIZED_VERT', '最大化没用 EWMH 状态'],
    [$x11PresentCc, 'CLIENT_MESSAGE', '最大化/最小化没发 ClientMessage 给 WM'],
    [$x11PresentCc, 'XIconifyWindow(', 'minimize 没调 XIconifyWindow'],
    [$x11PresentCc, 'php_drift_x11_window_state', '缺读回函数 —— probe 无法验真实 X 属性'],
    [$x11PresentCc, 'XGetWindowProperty(', '读回没从 X server 取属性'],
    [$x11PresentCc, 'WM_STATE', '读回没验最小化状态'],
    [$x11PresentCc, 'X11_SUBSTRUCTURE_REDIRECT', 'ClientMessage 没发对事件掩码（WM 收不到）'],
    // stub 声明（AOT 静态调用要声明）
    [$x11StubPhp, 'function drift_x11_set_min_size(', 'stub 缺 set_min_size'],
    [$x11StubPhp, 'function drift_x11_place_window(', 'stub 缺 place_window'],
    [$x11StubPhp, 'function drift_x11_maximize_toggle(', 'stub 缺 maximize_toggle'],
    [$x11StubPhp, 'function drift_x11_minimize(', 'stub 缺 minimize'],
    [$x11StubPhp, 'function drift_x11_window_state(', 'stub 缺 window_state'],
    // WindowHost 去 no-op
    [$x11HostPhp, 'drift_x11_set_min_size(', 'setMinSize 还是 no-op'],
    [$x11HostPhp, 'drift_x11_place_window(', 'place 还是 no-op'],
    [$x11HostPhp, 'drift_x11_maximize_toggle(', 'maximizeToggle 还是 no-op'],
    [$x11HostPhp, 'drift_x11_minimize(', 'minimize 还是 no-op'],
    [$x11HostPhp, 'drift_x11_window_position(', 'position 还是恒 null'],
    [$x11HostPhp, "state['max_v'] ?? false", 'isMaximized 没读真实状态'],
    // probe + build.sh
    [$linuxMainPhp, 'probeWindowManagement(', 'probe 没跑窗口管理判据'],
    [$linuxMainPhp, 'createWindow(\'WM Probe\'', '判据没用独立临时窗口（会干扰主窗口关窗）'],
    [$linuxMainPhp, "\$win->setMinSize(\$tmp, 400, 300)", 'probe 没设最小尺寸'],
    [$linuxMainPhp, 'drift_x11_window_state($tmp)', 'probe 没读回 X 属性'],
    [$linuxBuildSh, "grep -q '\\[WM\\] min 400x300'", 'build.sh 没断言最小尺寸'],
    [$linuxBuildSh, "grep -q '\\[WM\\] max_v=1 max_h=1 isMaximized=1'", 'build.sh 没断言 EWMH 最大化'],
    [$linuxBuildSh, "grep -q '\\[WM\\] iconic=1'", 'build.sh 没断言最小化'],
    [$linuxBuildSh, "grep -q '\\[WM\\] RESULT: PASS'", 'build.sh 没断言窗口管理整体通过'],
];
foreach ($x11WmAnchors as [$text, $needle, $label]) {
    if (!str_contains($text, $needle)) {
        fail('X11 窗口管理链路断裂：' . $label . '（' . $needle . '）');
    }
}
// 反向锚点：WindowHost 里不许再有 position 恒 null / isMaximized 恒 false。
if (preg_match('/function position\([^)]*\): \?Point\s*\{\s*return null;/s', $x11HostPhp)) {
    fail('X11WindowHost::position() 又变回恒 null —— 窗口位置持久化会静默失效');
}
if (preg_match('/function isMaximized\([^)]*\): bool\s*\{\s*return false;/s', $x11HostPhp)) {
    fail('X11WindowHost::isMaximized() 又变回恒 false —— 最大化状态永远读不到');
}
fwrite(STDOUT, '  ok     X11 窗口管理 ICCCM/EWMH：' . count($x11WmAnchors) . " 个链路环节 + 空实现反向判据\n");

// ── X11 窗口失活：FocusOut → deactivate → WindowDeactivatedEvent ──────
// 这个类 + 消费者在框架层早已就绪（Win32 WM_ACTIVATE 已通），但 X11 桥此前
// 既没订阅 FocusChangeMask 也不处理 FocusOut ⇒ 用户 Alt+Tab 切走时
// capture/gesture/drag 手势收口信号全断（拖到一半切走 ⇒ 控件永久卡在拖动中）。
// 锚定整条链：桥订阅 + case + detail 过滤 + translate + probe 注入 + build.sh grep。
$x11DeactivateAnchors = [
    // 桥：订阅 FocusChangeMask（不订阅 ⇒ 收不到 FocusOut，整条链空转）
    [$x11PresentCc, 'FOCUS_CHANGE_MASK', '建窗没订阅 FocusChangeMask —— 桥收不到 FocusOut'],
    [$x11PresentCc, '| FOCUS_CHANGE_MASK', 'FocusChangeMask 没加进 XSelectInput 掩码'],
    // 桥：case + "真的切走"过滤（mode=Normal && detail!=Inferior）
    [$x11PresentCc, 'case FOCUS_OUT:', '桥没处理 FocusOut 事件'],
    [$x11PresentCc, '"deactivate"', 'FocusOut 没翻译成 deactivate 事件类型'],
    [$x11PresentCc, 'ev.focus.mode == NOTIFY_NORMAL', '没过滤 Grab/Ungrab 临时失焦'],
    [$x11PresentCc, 'ev.focus.detail != NOTIFY_INFERIOR', '没过滤窗口内部焦点移动（会误收口手势）'],
    [$x11PresentCc, 'NOTIFY_INFERIOR', '缺 NotifyInferior 常量（内部移动判据）'],
    // PHP：translate 分支 + 事件类型
    [$x11PlatformPhp, "if (\$type === 'deactivate')", 'X11Platform 没接 deactivate 事件'],
    [$x11PlatformPhp, 'WindowDeactivatedEvent', 'deactivate 没翻译成 WindowDeactivatedEvent'],
    // probe：打标 + 注入脚本 + 调用
    [$linuxMainPhp, 'WindowDeactivatedEvent', 'probe 没识别 WindowDeactivatedEvent'],
    [$linuxMainPhp, '[EVENT] deactivate received', 'probe 没打失活事件标 —— build.sh 无从断言'],
    [$linuxBuildSh, 'send_focus.py', 'build.sh 没复制/调用焦点注入脚本'],
    [$linuxBuildSh, "python3 \"\$ws/app/send_focus.py\"", 'build.sh 没注入 FocusOut'],
    [$linuxBuildSh, "grep -q '\\[EVENT\\] deactivate received'", 'build.sh 没断言失活事件到框架'],
];
foreach ($x11DeactivateAnchors as [$text, $needle, $label]) {
    if (!str_contains($text, $needle)) {
        fail('X11 窗口失活链路断裂：' . $label . '（' . $needle . '）');
    }
}
// 反向锚点 1：deactivate 分支不许退化成 no-op（push 事件被删）。
// 用 strpos 切片（不用正则，避开引号/反斜杠转义陷阱）：从分支起点到下一个同缩进闭合 }。
$dBeg = strpos($x11PlatformPhp, "if (\$type === 'deactivate') {");
$dEnd = $dBeg === false ? false : strpos($x11PlatformPhp, "\n        }", $dBeg);
$dBody = ($dBeg !== false && $dEnd !== false)
    ? substr($x11PlatformPhp, $dBeg, $dEnd - $dBeg)
    : '';
if (!str_contains($dBody, 'push(new WindowDeactivatedEvent())')
    || !str_contains($dBody, 'return 1;')) {
    fail('X11Platform deactivate 分支没 push WindowDeactivatedEvent —— 手势收口信号断');
}
// 反向锚点 2：桥不许把 FocusOut 无脑吐 deactivate（删掉 detail 过滤 = 内部控件焦点移动误收口）。
if (!str_contains($x11PresentCc, 'ev.focus.detail != NOTIFY_INFERIOR')) {
    fail('桥的 FocusOut detail 过滤被删 —— 窗口内部焦点移动会误触发失活');
}
fwrite(STDOUT, '  ok     X11 窗口失活 deactivate：' . count($x11DeactivateAnchors) . " 个链路环节 + 过滤反向判据\n");

// ── X11 剪贴板：xclip 子进程 + UTF8_STRING + 有界超时 + 同步三件套 ────
// 剪贴板是 X11 上最容易"看起来有、实际读不到"的壳服务：owner 异步 selection
// 往返 + headless 无 manager + STRING/Latin-1 默认 target 让中文变问号。
// 这里从实现 TU / ABI / stub / PHP 实现 / 接线 / probe / build.sh / CI 装包
// 八处交叉锚定整条链，任一环断就红。
$x11ClipboardCc = (string) @file_get_contents($root . '/cpp-src/x11/clipboard.cc');
$x11ClipboardH = (string) @file_get_contents($root . '/cpp-src/x11/clipboard.h');
$x11ClipboardPhp = (string) @file_get_contents($root . '/src/Platform/X11/X11Clipboard.php');
$x11ClipAnchors = [
    // 实现 TU：独立进程隔离异步 + 三条硬纪律
    [$x11ClipboardCc, 'execvp("xclip", argv)', '剪贴板没用 xclip 子进程 —— 桥内自实现会撞同 connection 自死锁'],
    [$x11ClipboardCc, 'fork()', '没有 fork ⇒ 不是独立进程，同步 readText 会自死锁'],
    [$x11ClipboardCc, 'const_cast<char *>("UTF8_STRING")', '没显式指定 UTF8_STRING target —— 中文会按 Latin-1 变问号'],
    [$x11ClipboardCc, 'kClipTimeoutMs', '缺读取超时预算 —— owner 不回通知会永久 hang（Chromium 571343363）'],
    [$x11ClipboardCc, 'kill(pid, SIGKILL)', '超时没 kill 子进程 —— 挂死的 xclip 会留着'],
    [$x11ClipboardCc, 'waitpid(pid, &status, 0)', '没回收子进程 —— 每次 Ctrl+V 留一个僵尸'],
    [$x11ClipboardCc, 'signal(SIGPIPE, SIG_IGN)', '没忽略 SIGPIPE —— xclip 提前退出会杀掉宿主'],
    [$x11ClipboardCc, 'std::chrono::steady_clock', '超时用了会被系统调时的时钟 —— 与 now() 单调纪律冲突'],
    [$x11ClipboardH, 'clipboardReadText', '剪贴板 TU 缺读接口声明'],
    // ABI + stub
    [$x11PresentCc, 'php_drift_x11_clipboard_read_text', 'drift_x11.cc 没做剪贴板 ABI 包装'],
    [$x11PresentCc, 'drift::x11::clipboardReadText()', 'ABI 没转发到剪贴板实现'],
    [$x11StubPhp, 'function drift_x11_clipboard_read_text(): string', 'x11 stub 没声明读函数'],
    [$x11StubPhp, 'function drift_x11_clipboard_write_text(string $text): void', 'x11 stub 没声明写函数'],
    [$x11StubPhp, 'function drift_x11_clipboard_has_text(): bool', 'x11 stub 没声明 hasText'],
    // PHP 实现 + 接线（不许再恒 null）
    [$x11ClipboardPhp, 'implements Clipboard', 'X11Clipboard 没实现 Clipboard 端口'],
    [$x11ClipboardPhp, 'drift_x11_clipboard_write_text($text)', 'X11Clipboard 没接写桥'],
    [$x11PlatformPhp, 'function_exists(\'drift_x11_clipboard_read_text\')', '平台没做桥函数探测 —— 纯 PHP 单测会调不存在的函数'],
    [$x11PlatformPhp, 'new X11Clipboard()', 'X11Platform::clipboard() 没返回真实现'],
    // probe + build.sh + CI 装包
    [$linuxMainPhp, 'probeClipboard($platform)', 'probe 没跑剪贴板判据'],
    [$linuxMainPhp, 'external-剪贴板-X11', 'probe 没用外部写入的跨进程文本'],
    [$linuxBuildSh, 'printf \'%s\' \'external-剪贴板-X11\'', 'build.sh 没在应用启动前用外部 xclip 写剪贴板'],
    [$linuxBuildSh, "grep -q '\\[CLIP\\] roundtrip got=drift-剪贴板-中文-ok'", 'build.sh 没断言中文往返'],
    [$linuxBuildSh, 'XCLIP_BIN', 'build.sh 没提供本地免 root xclip 路径开关'],
    [$linuxBuildSh, 'cpp-src/x11/clipboard.cc', 'build.sh 没复制剪贴板 TU —— AOT 会链接失败'],
    [$linux, 'gdb xclip', 'CI 没 apt 装 xclip —— 真机门必然失败'],
];
foreach ($x11ClipAnchors as [$text, $needle, $label]) {
    if (!str_contains($text, $needle)) {
        fail('X11 剪贴板链路断裂：' . $label . '（' . $needle . '）');
    }
}
// 反向锚点 1：X11Platform::clipboard() 不许退回恒 null（那是本轮要闭合的缺口）。
$cBeg = strpos($x11PlatformPhp, 'public function clipboard(): ?Clipboard');
$cEnd = $cBeg === false ? false : strpos($x11PlatformPhp, "\n    }", $cBeg);
$cBody = ($cBeg !== false && $cEnd !== false)
    ? substr($x11PlatformPhp, $cBeg, $cEnd - $cBeg)
    : '';
if (!str_contains($cBody, 'new X11Clipboard()')) {
    fail('X11Platform::clipboard() 又退回恒 null —— Ctrl+C/V/X 在 Linux 上静默失效');
}
// 反向锚点 2：不许用裸 popen/system 拼命令（注入风险 + 无法设超时 + 无法可靠回收）。
if (preg_match('/\b(popen|system)\s*\(/', $x11ClipboardCc) === 1) {
    fail('剪贴板 TU 用了裸 popen/system —— 无法设超时 / 回收子进程，且 xclip 路径注命令');
}
// 反向锚点 3：读写两端都必须显式 target（缺任一个 ⇒ 中文按 Latin-1 变问号）。
if (substr_count($x11ClipboardCc, '"UTF8_STRING"') < 2) {
    fail('剪贴板读写两端没都显式 UTF8_STRING target —— 中文会变问号');
}
fwrite(STDOUT, '  ok     X11 剪贴板 xclip/UTF8/超时：' . count($x11ClipAnchors) . " 个链路环节 + 3 条反向判据\n");

// ── Linux Skia 构建通道（build-only，不发布）────────────────────────────
// 目的：在公开 runner 上真编 Linux .a，并产出 linux-x64 包交给 deps.yml。
// 它不提交锁、不打 tag、不发 Release —— 发布统一由双平台编排作业完成。
$linuxDepsAnchors = [
    'runs-on: ubuntu-24.04' => '托管 Linux runner（不用会静默排队的 self-hosted）',
    'workflow_dispatch:' => '手动兜底',
    'workflow_call:' => '发布编排可复用',
    'clang --version' => '显式验 clang 并留版本在日志里',
    'libfontconfig1-dev' => 'Linux 独有：SkFontConfigInterface_direct 需要 fontconfig 头',
    "test -f /usr/include/fontconfig/fontconfig.h || {" => '装完必须复核头文件真的在（否则 apt 报成功但头缺失时仍会编译失败）',
    'apt-get install --yes --no-install-recommends' => 'apt 关 recommends（24.04 的加固配方）',
    '--platform=linux' => '显式钉 Linux 分支（不靠 uname 猜）',
    'tools/skia/build-skia.sh' => '复用同一份 Skia 构建配方',
    'out/Release-x64/*.a' => '产物是 Linux 静态库',
    'cpp/skia-linux/include' => '公共头必须随 .a 一起交（消费侧按 include/core/... 引用）',
    'cpp/skia-linux/modules' => '私有头必须随 .a 一起交（SkGradient.h 引用 modules/skcms/skcms.h）',
    'cpp/skia-linux/src' => 'src/ 私有头也在 headers.txt 里，漏传会在编译中途才炸',
    'php tools/pack-deps.php --platform=linux-x64' => '打包 Linux 资产',
    'dist/drift-deps-linux-x64.zip' => '上传 Linux 资产',
    'actions/upload-artifact@v4' => '产物交给发布编排与 X11 门',
    "grep -q -- '-MT'" => '拦截 MSVC 静态 CRT 标志泄漏到 Linux 构建',
];
foreach ($linuxDepsAnchors as $needle => $label) {
    if (!str_contains($linuxDeps, $needle)) {
        fail('linux-deps.yml 缺环节：' . $label . '（' . $needle . '）');
    }
}
// 反向锚点只查生效行：注释里解释"为什么不发布"是必要的，不该被自己拦住。
$linuxDepsLive = $stripComments($linuxDeps);
foreach ([
    'php tools/release-deps.php' => '发布依赖资产',
    'php tools/fetch-deps.php' => '发布后回验（本通道没有发布）',
    'git tag' => '打 tag（deps 版本是不可变的）',
    'git commit' => '提交锁（发布编排是唯一写者）',
    'git push' => '推提交（发布编排是唯一写者）',
    'publish:' => '发布开关（build-only 通道不该有）',
    'contents: write' => '写权限（build-only 只需 contents: read）',
] as $needle => $label) {
    if (str_contains($linuxDepsLive, $needle)) {
        fail('linux-deps.yml 出现 ' . $label . ' —— 这是 build-only 通道，不许碰发布链路（' . $needle . '）');
    }
}
fwrite(STDOUT, '  ok     Linux Skia 构建通道：' . count($linuxDepsAnchors) . " 个 build-only 环节\n");
