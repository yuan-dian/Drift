#!/usr/bin/env php
<?php
/**
 * build-grab —— 用 `cl.exe` 编 `tools/probe-grab.cc` 出 `tools/probe-grab.exe`。
 *
 * ```bash
 * php tools/build-grab.php
 * ```
 *
 * 为什么不把 `.cc` 塞进 `drift-build`：`drift-build` 编的是**应用**（走 tpc，二十几秒、
 * 产物 7.5MB），这里编的是**一个独立小工具**（直接 cl、1.7s、产物 175KB）。
 * 两者共用的只有"MSVC / SDK 三件套路径拼接"那二十行，抽出来的公共抽象会把差异
 * 藏进参数里 —— 与 `drift-build.php` 头注释里"有意不抽公共类"是同一个判断。
 *
 * ★ 判据是 **exit==0 且 exe 存在**：先删旧 exe，否则"cl 失败 + 上一轮的 exe 还在"
 *   会被读成成功（`drift-build` 踩过同一脚，见那边的注释）。
 */

declare(strict_types=1);

/** MSVC / SDK 的默认位置（与 drift-build.php 同源；换机器用环境变量覆盖） */
const DEFAULT_MSVC_VERSION = '14.51.36231';
const DEFAULT_VS_YEAR = '18';
const DEFAULT_SDK_VERSION = '10.0.26100.0';

function fail(string $message): never
{
    fwrite(STDERR, "[build-grab] ✗ {$message}\n");
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

$root = dirname(__DIR__);

if (PHP_OS_FAMILY !== 'Windows') {
    fail('probe-grab 只对 Win32 有意义（GetDC/BitBlt/GetDIBits）');
}

// ───────────────────────────────────── MSVC / SDK 三件套 ──

$msvc = envOr('DRIFT_MSVC', '');
if ($msvc === '') {
    $msvc = 'D:/Microsoft Visual Studio/' . DEFAULT_VS_YEAR
        . '/Community/VC/Tools/MSVC/' . DEFAULT_MSVC_VERSION;
}
$cl = "{$msvc}/bin/Hostx64/x64/cl.exe";
if (!is_file($cl)) {
    fail("找不到 cl.exe：{$cl}（设 DRIFT_MSVC 覆盖）");
}

$kits = envOr('DRIFT_KITS', 'D:/Windows Kits/10');
$sdkVersion = envOr('DRIFT_SDK_VERSION', DEFAULT_SDK_VERSION);
$sdkInclude = "{$kits}/Include/{$sdkVersion}";
$sdkLib = "{$kits}/Lib/{$sdkVersion}";
if (!is_dir("{$sdkInclude}/um") || !is_dir("{$sdkLib}/um/x64")) {
    fail("Windows SDK {$sdkVersion} 不完整：{$sdkInclude}（设 DRIFT_KITS / DRIFT_SDK_VERSION 覆盖）");
}

$environment = getenv();
if (!is_array($environment)) {
    $environment = [];
}
$environment['INCLUDE'] = implode(';', [
    win("{$msvc}/include"),
    win("{$sdkInclude}/ucrt"),
    win("{$sdkInclude}/shared"),
    win("{$sdkInclude}/um"),
]);
$environment['LIB'] = implode(';', [
    win("{$msvc}/lib/x64"),
    win("{$sdkLib}/ucrt/x64"),
    win("{$sdkLib}/um/x64"),
]);
$environment['PATH'] = implode(';', [
    win("{$msvc}/bin/Hostx64/x64"),
    win("{$kits}/bin/{$sdkVersion}/x64"),
    (string) ($environment['PATH'] ?? ''),
]);

// ─────────────────────────────────────────────────────────── 构建 ──

$source = "{$root}/tools/probe-grab.cc";
$exe = "{$root}/tools/probe-grab.exe";
$objectDir = "{$root}/_build/grab";
if (!is_file($source)) {
    fail("找不到源文件 {$source}");
}
if (!is_dir($objectDir) && !mkdir($objectDir, 0777, true) && !is_dir($objectDir)) {
    fail("建不出 {$objectDir}");
}

// ★ 先删旧 exe：判据要的是"这一次编出来了"
if (is_file($exe) && !unlink($exe)) {
    fail("删不掉旧产物 {$exe} —— 可能还有 probe-grab 进程没退");
}

// `/utf-8`：源文件注释是中文且无 BOM；不声明的话 cl 按系统代码页（GBK）读，
// 轻则 C4819 警告，重则注释尾字节吃掉换行符（行尾续行）—— 这种错查起来极费时间。
$command = [
    $cl,
    '/nologo',
    '/std:c++17',
    '/EHsc',
    '/O2',
    '/W3',
    '/utf-8',
    '/Fo:' . win("{$objectDir}/probe-grab.obj"),
    '/Fe:' . win($exe),
    win($source),
    '/link',
    '/SUBSYSTEM:CONSOLE',
];

fwrite(STDOUT, "[build-grab] 源 {$source}\n");
$started = microtime(true);

$process = proc_open(
    $command,
    [0 => ['file', 'nul', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root,
    $environment
);
if (!is_resource($process)) {
    fail('启动 cl.exe 失败');
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
    usleep(20000);
}
$output .= (string) stream_get_contents($pipes[1]);
$output .= (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);
$elapsed = microtime(true) - $started;

if ($exitCode !== 0 || !is_file($exe)) {
    fwrite(STDERR, sprintf("[build-grab] ✗ 构建失败（exit=%d，%.1fs）\n", $exitCode, $elapsed));
    foreach (preg_split('/[\r\n]+/', $output) ?: [] as $line) {
        $line = trim($line);
        if ($line !== '') {
            fwrite(STDERR, "  {$line}\n");
        }
    }
    exit(1);
}

fwrite(STDOUT, sprintf(
    "[build-grab] ✓ %s ｜ %.1fs ｜ %.1fKB\n",
    $exe,
    $elapsed,
    filesize($exe) / 1024
));
