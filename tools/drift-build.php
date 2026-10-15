<?php
declare(strict_types=1);

namespace Drift\Dev;

/**
 * `drift-build` —— **真机构建**一个应用（§16.2 工具链）。
 *
 * ```
 * php tools/drift-build.php            # 默认应用 apps/hello
 * php tools/drift-build.php hello      # 显式指定
 * ```
 *
 * ★ 与 `drift-codegen`（`--dry` 只生成 C++、用假 cl）的分工：
 *   本脚本**真的调用 cl.exe / link.exe**，产物是能双击运行的 exe。代价是慢
 *   （~1–3 分钟）且**需要本机有 MSVC + Windows SDK**，所以它不进日常内环
 *   （§16.3：内环不编译），只在"要出那个 exe"或"验证 AOT 专属问题"时跑。
 *
 * ★★ **为什么必须用它而不是手敲 tpc 命令**：Windows 上跑 `tpc` 有四个坑，
 *   每一个都表现为"看不出根因的失败"：
 *   ① `tpc.exe`（发行版）**不加载同目录的 `php.ini`**（`php.exe` 会）⇒ 必须 `PHPRC`，
 *      否则 `typephp_tpc` 模块因缺 `mbstring` 直接 Fatal；
 *   ② 发行版 ini 里的 `extension_dir` 是相对 `./ext/`，跟着 **cwd** 漂
 *      ⇒ 抄一份改成绝对路径（`_build/ini/php.ini`）；
 *   ③ `PHPX_HOME` 不设 ⇒ `phpx directory not found`；
 *   ④ `cl.exe` 要 `INCLUDE`/`LIB`/`PATH` 三件套（`vcvars64.bat` 干的就是这个），
 *      而 bash 里没法 `call` 它 ⇒ 这里直接按 MSVC/SDK 目录拼。
 *
 * ★ 与 `tools/drift-codegen.php` 有约 20 行重复（找 tpc 入口 / 抄私有 ini）。
 *   **有意不抽公共类**：两者的工具链、工作目录、失败语义都不同（一个是假 cl +
 *   只看翻译层，一个是真 cl + 出 exe）⇒ 抽出来的"公共部分"只剩两行路径拼接，
 *   而共享的抽象会把两边的差异藏进参数里。第三个消费者出现时再抽。
 */

require_once __DIR__ . '/autoload.php';

const ROOT = __DIR__ . '/..';

/** MSVC / SDK 的默认位置（本机实测；换机器用环境变量覆盖，见 `--help`） */
const DEFAULT_MSVC_VERSION = '14.51.36231';
const DEFAULT_VS_YEAR = '18';
const DEFAULT_SDK_VERSION = '10.0.26100.0';

function fail(string $message): never
{
    fwrite(STDERR, "[drift-build] ✗ {$message}\n");
    exit(2);
}

/** 反斜杠化的 Windows 路径（PHP 是原生 Windows 程序，不认 `/d/...`） */
function win(string $path): string
{
    return str_replace('/', '\\', $path);
}

function envOr(string $name, string $fallback): string
{
    $value = getenv($name);

    return is_string($value) && $value !== '' ? $value : $fallback;
}

function usage(): never
{
    fwrite(STDOUT, <<<TXT
    drift-build —— 真机构建一个应用（调用 cl.exe / link.exe 出 exe）

      用法： php tools/drift-build.php [应用名]

    环境变量（换机器时覆盖，都有本机实测的默认值）：
      DRIFT_TPC_ENTRY    tpc 入口（默认 ../typephp/bin/tpc.php —— **源码树**，见下）
      DRIFT_MSVC         MSVC 工具集目录
      DRIFT_KITS         Windows Kits 根（默认 D:\\Windows Kits\\10）
      DRIFT_SDK_VERSION  SDK 版本（默认 10.0.26100.0）
      DRIFT_PHPX_HOME    PHPX 根（默认 <PHP 发行版>/phpx）

    ★ 为什么默认用**源码树** `bin/tpc.php` 而不是发行版 `tpc.exe`：
      修复只进源码树，发行版二进制不含（详见 DESIGN.md §10.1.2）。

    TXT);
    exit(0);
}

// ────────────────────────────────────────────────────────────── 参数 ──

$argvRest = array_slice($argv, 1);
if (in_array('--help', $argvRest, true) || in_array('-h', $argvRest, true)) {
    usage();
}

$app = 'hello';
foreach ($argvRest as $argument) {
    if ($argument !== '' && $argument[0] !== '-') {
        $app = $argument;
        break;
    }
}

if (preg_match('/^[A-Za-z0-9_-]+$/', $app) !== 1) {
    fail("应用名不合法：{$app}");
}

$root = realpath(ROOT);
if ($root === false) {
    fail('解析仓库根失败');
}

$project = "{$root}/apps/{$app}/project.yml";
if (!is_file($project)) {
    fail("找不到工程文件：{$project}");
}

// ─────────────────────────────────────────────────── tpc 入口 / PHP ──

$entry = envOr('DRIFT_TPC_ENTRY', '');
if ($entry === '') {
    foreach ([dirname($root) . '/typephp/bin/tpc.php', 'D:/TypePHP/typephp/bin/tpc.php'] as $candidate) {
        if (is_file($candidate)) {
            $entry = $candidate;
            break;
        }
    }
}
if ($entry === '' || !is_file($entry)) {
    fail('找不到 tpc 入口 —— 设 DRIFT_TPC_ENTRY=D:/…/typephp/bin/tpc.php');
}

$phpBinary = PHP_BINARY;
if ($phpBinary === '') {
    fail('PHP_BINARY 为空 —— 无法定位 PHP 发行版根');
}
$distRoot = win(dirname(win($phpBinary)));

// ─────────────────────────────────────────────── 私有 ini（坑 ②） ──

$work = "{$root}/_build";
$iniDir = "{$work}/ini";
if (!is_dir($iniDir) && !mkdir($iniDir, 0777, true) && !is_dir($iniDir)) {
    fail("建不了 {$iniDir}");
}

$iniSource = "{$distRoot}/php.ini";
if (!is_file($iniSource)) {
    fail("找不到发行版 php.ini：{$iniSource}（PHPRC 无从派生）");
}
$iniText = (string) file_get_contents($iniSource);
$patched = preg_replace(
    '/^\s*;?\s*extension_dir\s*=\s*.*$/mi',
    'extension_dir = "' . $distRoot . '/ext/"',
    $iniText
);
if ($patched === null || $patched === $iniText) {
    fail("patch extension_dir 失败（{$iniSource}）—— 宁可构建失败，也别悄悄用错的 ini");
}
file_put_contents("{$iniDir}/php.ini", $patched);

// ───────────────────────────────────────────────────── PHPX（坑 ③） ──

$phpxHome = envOr('DRIFT_PHPX_HOME', $distRoot . '/phpx');
if (!is_dir($phpxHome)) {
    fail("PHPX_HOME 不存在：{$phpxHome}");
}

// ───────────────────────────────────── MSVC / SDK 三件套（坑 ④） ──

$msvcVersion = DEFAULT_MSVC_VERSION;
$msvc = envOr('DRIFT_MSVC', '');
if ($msvc === '') {
    $msvc = "D:/Microsoft Visual Studio/" . DEFAULT_VS_YEAR . '/Community/VC/Tools/MSVC/' . $msvcVersion;
}
if (!is_file("{$msvc}/bin/Hostx64/x64/cl.exe")) {
    fail("找不到 cl.exe：{$msvc}/bin/Hostx64/x64/cl.exe（设 DRIFT_MSVC 覆盖）");
}

$kits = envOr('DRIFT_KITS', 'D:/Windows Kits/10');
$sdkVersion = envOr('DRIFT_SDK_VERSION', DEFAULT_SDK_VERSION);
$sdk = "{$kits}/Include/{$sdkVersion}";
$sdkLib = "{$kits}/Lib/{$sdkVersion}";
if (!is_dir("{$sdk}/um") || !is_dir("{$sdkLib}/um/x64")) {
    fail("Windows SDK {$sdkVersion} 不完整：{$sdk}（设 DRIFT_KITS / DRIFT_SDK_VERSION 覆盖）");
}

$env = getenv();
if (!is_array($env)) {
    $env = [];
}
$env['PHP_HOME'] = $distRoot;
$env['PHPX_HOME'] = $phpxHome;
$env['PHPRC'] = win($iniDir);
$env['INCLUDE'] = implode(';', [
    win("{$msvc}/include"),
    win("{$sdk}/ucrt"),
    win("{$sdk}/shared"),
    win("{$sdk}/um"),
]);
$env['LIB'] = implode(';', [
    win("{$msvc}/lib/x64"),
    win("{$sdkLib}/ucrt/x64"),
    win("{$sdkLib}/um/x64"),
]);
// ★ PATH 里必须同时有 cl.exe（MSVC bin）与 rc.exe（SDK bin）—— 后者只有声明了
//   `resource:` / `manifest:` 时才用得上，但少了它 tpc 报的是"找不到 rc.exe"，
//   看不出是 PATH 的问题。
$env['PATH'] = implode(';', [
    win("{$msvc}/bin/Hostx64/x64"),
    win("{$kits}/bin/{$sdkVersion}/x64"),
    (string) ($env['PATH'] ?? ''),
]);

// ───────────────────────────────────────────────────────────── 构建 ──

$command = [$phpBinary, $entry, '--project', $project, '-O2', '-m', 'bin'];

fwrite(STDOUT, sprintf(
    "[drift-build] 应用 %s ｜ 入口 %s ｜ MSVC %s ｜ SDK %s\n",
    $app,
    $entry,
    $msvcVersion,
    $sdkVersion
));

$started = microtime(true);

// ★ 先删旧 exe：否则"tpc 失败 + 上一轮的 exe 还在"会被下面的判据读成成功
//   （判据是 exit==0 **且** exe 存在，两个条件都必要）。
$exe = "{$root}/{$app}.exe";
if (is_file($exe) && !unlink($exe)) {
    fail("删不掉旧产物 {$exe} —— 可能还有上一次的进程没退（Windows 会锁住正在运行的 exe）");
}

// ★ cwd 用**仓库根**：`cxx-flags` / `ld-flags` 不过 tpc 的路径解析（直接拼进命令行），
//   相对的就是这里的 cwd；输出 exe 也落在 cwd（`name:` 只决定文件名）。
$process = proc_open(
    $command,
    [0 => ['file', 'nul', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root,
    $env
);
if (!is_resource($process)) {
    fail('启动 tpc 失败');
}

stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$output = '';
while (true) {
    $status = proc_get_status($process);
    $output .= (string) stream_get_contents($pipes[1]);
    $output .= (string) stream_get_contents($pipes[2]);
    if (!$status['running']) {
        break;
    }
    usleep(50000);
}
$output .= (string) stream_get_contents($pipes[1]);
$output .= (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

$elapsed = microtime(true) - $started;

// 进度条（`Compiling [███…]`）会把日志刷成一整行，按 `\r` 切开只留最后一段
$lines = preg_split('/[\r\n]+/', $output) ?: [];
$interesting = [];
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, 'Compiling [')) {
        continue;
    }
    $interesting[] = $line;
}

$exe = "{$root}/{$app}.exe";
$success = $exitCode === 0 && is_file($exe);

if (!$success) {
    fwrite(STDERR, "[drift-build] ✗ 构建失败（exit={$exitCode}，{"
        . number_format($elapsed, 1) . "s}）\n");
    // 只回显有信息量的行：编译/链接错误 + tpc 自己的 Fatal
    foreach ($interesting as $line) {
        if (preg_match('/error C\d|error LNK|fatal error|Fatal error|Uncaught/', $line) === 1) {
            fwrite(STDERR, "  {$line}\n");
        }
    }
    exit(1);
}

fwrite(STDOUT, sprintf(
    "[drift-build] ✓ %s（%.1f MB，%.1fs）\n",
    $exe,
    (float) filesize($exe) / 1048576.0,
    $elapsed
));
