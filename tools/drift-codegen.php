<?php
declare(strict_types=1);

use Drift\Dev\Doctor\Environment;
use Drift\Dev\Doctor\EnvironmentException;

require_once __DIR__ . '/autoload.php';

/**
 * drift-codegen —— tpc codegen 常驻门（T1 档，~3s）。
 *
 * ```bash
 * php tools/drift-codegen.php            # 全仓 src/ + 各 apps/<app>/src 走一遍 tpc 翻译
 * php tools/drift-codegen.php --verbose  # 失败时把完整日志倒出来
 * ```
 *
 * ★ 它补的是 lint 结构上给不了的那一层（§16.3「build(tpc)」的前半段）：
 *   `php -l` 只看语法、315 条断言只在解释器里跑 ⇒ 两个缺陷都能活得很好；
 *   **真正把 `src/` 交给 tpc 翻译一遍**才能暴露：
 *     · 隐式 nullable（`T $x = null`）—— tpc 禁、PHP 8.4+ 已 deprecated，但 `php -l` 不报
 *     · `Enum::Case->value` 作属性默认值 —— stub/arginfo 路径直接 throw
 *     · 命名空间内调 stub 函数没写前导 `\`
 *     · tpc 自己对该形态根本不支持 ⇒ 静默 skip（产物里少一个文件）
 *
 * ★ 为什么只用 `--dry`（生成 C++，不编译）：
 *   要的那一层叫**翻译**，编译/链接要真 `cl.exe`（沙箱拦、要提权、20–50s）—— 那是
 *   提交前的 T2 档。本门只花 ~3s、不碰真编译器、cwd 无关 ⇒ 每轮迭代都能跑。
 *
 * ★ 为什么必须用**源码树** `bin/tpc.php` 而不是发行版 `tpc.exe`：
 *   **修复只在源码树，发行版二进制不含**（D2/D3/D5/D6 已修、D4 待复验）⇒ 拿发行版
 *   跑只会得到"已经修好了的错误"（假警报），且它跑的不是我们要发的形态。
 *   入口可用 `DRIFT_TPC_ENTRY` 覆盖（`.php` 走 php，其它后缀直接执行）。
 *
 * ★ 三条环境硬事实（缺一条就 exit 2 = **门未跑**，不是门通过）：
 *   ① `PHPRC` 指向**私有 ini 副本**（`extension_dir` 已改成绝对路径）——
 *      0.9.4 起 `extension_dir` 必须是绝对路径，缺 `mbstring` 直接 Fatal，而发行版
 *      ini 里写的是相对 `./ext/`（跟着 cwd 漂）。
 *   ② `PHPX_HOME`（= `$PHP_HOME/phpx`），否则报 `phpx directory not found`。
 *   ③ 路径一律 `D:/…`（PHP 是原生 Windows 程序，不认 `/d/…`）。
 *
 * ★ `--dry` 也仍校验工具链（`SourcePipelineTrait.php:597`）⇒ 假 `cl.exe`/`link.exe`
 *   免不了。手法：`cmd.exe` 拷成 `cl.exe`（**必须有 `.exe` 后缀**）+ `link.exe`，
 *   tpc 只查它在不在、能不能起进程。
 *
 * ★ 退出码：0 = 门通过；1 = 门未通过（翻译/产物有问题）；2 = **门没跑成**（环境缺件）。
 *   三者必须分开 —— "没跑"和"通过"绝不能是同一个码（G2：不许静默）。
 */

const EXIT_GATE_FAILED = 1;
const EXIT_NO_TOOLCHAIN = 2;

/** 仓库根 */
$root = win(dirname(__DIR__));
$args = array_slice($argv, 1);
$verbose = in_array('--verbose', $args, true) || in_array('-v', $args, true);

/** 工作目录（已在 .gitignore：`/_codegen`）—— 假工具链 / 私有 ini / 生成的 yml / 日志 / 生成的 C++ */
$work = $root . '/_codegen';
$buildDir = $work . '/build';

// ─────────────────────────────────────────────────────────────────────────

/** Windows 风格路径（正斜杠）—— PHP 认，且不会被字符串转义吃掉 */
function win(string $path): string
{
    return str_replace('\\', '/', $path);
}

/** @return never */
function toolchainFail(string $message): void
{
    fwrite(STDERR, "[drift-codegen] ✗ 门未跑成（环境缺件，不是门通过）：{$message}\n");
    exit(EXIT_NO_TOOLCHAIN);
}

/** @return never */
function gateFail(string $message): void
{
    fwrite(STDERR, "[drift-codegen] ✗ 门未通过：{$message}\n");
    exit(EXIT_GATE_FAILED);
}

// tpc 入口 / 发行版根 / 私有 ini 的定位统一由 `Drift\Dev\Doctor\Environment` 提供：
// 三个消费者（本门 / drift-build / doctor）共用一套推导，不各写一份。

/** 假工具链：`cmd.exe` 垫成 `cl.exe` / `link.exe`（`--dry` 也校验工具链，免不了） */
function prepareFakeToolchain(string $work): string
{
    $dir = win($work) . '/fake-toolchain';
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        toolchainFail("建不了 {$dir}");
    }

    $cmd = getenv('COMSPEC');
    $cmd = is_string($cmd) && is_file($cmd) ? win($cmd) : 'C:/Windows/System32/cmd.exe';
    if (!is_file($cmd)) {
        toolchainFail("找不到 cmd.exe（COMSPEC={$cmd}）—— 假 cl.exe 无从拷贝");
    }

    foreach (['cl.exe', 'link.exe'] as $name) {
        $target = $dir . '/' . $name;
        if (!is_file($target) && !copy($cmd, $target)) {
            toolchainFail("拷贝假编译器失败：{$target}（源 {$cmd}）");
        }
    }

    return $dir . '/cl.exe';
}

/**
 * 源码枚举：`src/` + 每个 `apps/<app>/src`（有 .php 才算）。
 *
 * ★ 自己数一遍，而不是只信 tpc 的日志：tpc 对不支持的形态会**静默 skip**，
 *   两个数对不上就是要报的那件事。
 *
 * @return array{list: list<string>, ymlSources: list<string>} [仓库根相对的 .php 路径, yml 里的 sources]
 */
function collectSources(string $root): array
{
    $dirs = ['src'];
    foreach (glob(win($root) . '/apps/*/src') ?: [] as $appSrc) {
        if (is_dir($appSrc)) {
            $dirs[] = 'apps/' . basename(dirname($appSrc)) . '/src';
        }
    }

    $files = [];
    $ymlSources = [];
    foreach ($dirs as $dir) {
        $absolute = win($root) . '/' . $dir;
        $found = phpFiles($absolute);
        if ($found === []) {
            continue;
        }
        foreach ($found as $file) {
            $files[] = $dir . '/' . substr($file, strlen($absolute) + 1);
        }
        // yml 在 `_codegen/` 里 ⇒ 相对它要写 `../`
        $ymlSources[] = '../' . $dir;
    }

    if ($files === []) {
        toolchainFail("源码枚举为空（试过：" . implode(' , ', $dirs) . "）");
    }

    sort($files);

    return ['list' => $files, 'ymlSources' => $ymlSources];
}

/** @return list<string> 目录树下所有 .php（仓库根相对路径，正斜杠） */
function phpFiles(string $dir): array
{
    if (!is_dir($dir)) {
        return [];
    }

    $found = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if ($item->isFile() && $item->getExtension() === 'php') {
            $found[] = str_replace('\\', '/', $item->getPathname());
        }
    }
    sort($found);

    return $found;
}

/**
 * 生成 `_codegen/project.yml`。
 *
 * ★ `mode: lib`：框架 SDK 分发形态（§10.2）。**不能用 bin** —— bin 要求全局唯一
 *   `function main()`，而 Drift 的入口在 `apps/<app>/bin/<platform>/Bootstrap.php`
 *   （还没到那一步）⇒ 这里要的是"把 src/ 翻译一遍"，不是"造一个 exe"。
 * ★ `sources` **不收绝对路径**（会被拼到 build-dir 上）⇒ 一律写相对本文件的路径。
 */
function writeProjectYml(string $work, array $ymlSources): string
{
    $path = $work . '/project.yml';
    $body = "# 由 tools/drift-codegen.php 生成，勿手改（单一出处 = 那个脚本）\n"
        . "name: drift-lib\n"
        . "version: 0.1.0\n"
        . "mode: lib\n"
        . "\n"
        . "sources:\n";
    foreach ($ymlSources as $source) {
        $body .= "  - {$source}\n";
    }
    $body .= "\nbuild-dir: ./build\n";
    file_put_contents($path, $body);

    return $path;
}

/**
 * 递归删除**只属于本门**的 `_codegen/build`（tpc 的增量缓存在里面）。
 *
 * ★ 为什么每次都要删：留着缓存 ⇒ 第二遍全是 `[cached]`、`convert:` 行数是 0 ⇒
 *   "每个文件都被翻译过"这条断言就成了空话。**门不能信缓存。**
 * ★ 三道保险：只删 `$work` 底下、不跟随符号链接、路径不在 `$work` 内直接拒。
 */
function removeTree(string $dir, string $work): void
{
    // ★ realpath() 在 Windows 下给**反斜杠** ⇒ 拼接的尾分隔符会与正斜杠不一致，
    //   str_starts_with 就永远不成立（守卫自己变成永假）。两边都规整再比。
    $realDir = win(realpath($dir) ?: '');
    $realWork = win(realpath($work) ?: '');
    if ($realDir === '' || $realWork === '') {
        return;
    }
    if (!str_starts_with($realDir . '/', $realWork . '/')) {
        toolchainFail("拒绝删除 {$dir}（不在 {$work} 之内）");
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($realDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isLink()) {
            unlink($item->getPathname());
        } elseif ($item->isDir()) {
            rmdir($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($realDir);
}

/** 把 tpc 日志里的相对路径规整成仓库根相对、正斜杠（`.\` / `..\` 都剥掉） */
function normalizeRelPath(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    while (str_starts_with($path, './')) {
        $path = substr($path, 2);
    }
    while (str_starts_with($path, '../')) {
        $path = substr($path, 3);
    }

    return ltrim($path, '/');
}

// ── 主流程 ──────────────────────────────────────────────────────────────

if (!is_dir($work) && !mkdir($work, 0777, true) && !is_dir($work)) {
    toolchainFail("建不了工作目录 {$work}");
}
removeTree($buildDir, $work);

// 定位走 `Environment`（与 drift-build / doctor 同一套推导）。本门用宽松原语而非
// `prepare()`：`--dry` 只走翻译层、用假 cl.exe ⇒ 真 MSVC/SDK 不是它的前置。
try {
    $entry = Environment::tpcEntry($root);
    $distRoot = Environment::distributionRoot();
    $phpxHome = Environment::resolvePhpxHome();
    $iniDir = Environment::writePrivateIni($root) ?? '';
} catch (EnvironmentException $e) {
    toolchainFail($e->getMessage());
}
if (!is_dir($phpxHome)) {
    toolchainFail("PHPX_HOME 不存在：{$phpxHome}（设 DRIFT_PHPX_HOME 覆盖；发行版根下的 phpx/ 才对）");
}
$entryIsPhp = str_ends_with(strtolower($entry), '.php');

$cl = prepareFakeToolchain($work);
$sources = collectSources($root);
$yml = writeProjectYml($work, $sources['ymlSources']);
$logPath = $work . '/last-run.log';
$generatedList = $work . '/generated.cc.txt';

$command = [$entryIsPhp ? PHP_BINARY : $entry];
if ($entryIsPhp) {
    $command[] = $entry;
}
array_push($command, '--project', win($yml), '--dry', '--compiler', $cl);

$env = getenv();
if (!is_array($env)) {
    $env = [];
}
$env['PHPX_HOME'] = $phpxHome;
if ($iniDir !== '') {
    $env['PHPRC'] = $iniDir;
}
$env['TYPEPHP_GENERATED_SOURCE_LIST'] = $generatedList;

fwrite(STDOUT, sprintf(
    "[drift-codegen] 入口 %s（%s）｜cl %s｜PHPRC %s\n",
    $entry,
    $entryIsPhp ? '源码树' : '可执行',
    $cl,
    $iniDir !== '' ? $iniDir : '(发行版默认)'
));

$started = microtime(true);
// ★ cwd 用**工作目录**而不是仓库根：`drift_lib.stub.php` 是 tpc 按 cwd 落盘的产物，
//   放仓库根会弄脏源码树（已 gitignore 的 `_codegen/` 才是它的家）。
//   源码路径与 build-dir 都相对 **yml 所在目录**解析，与 cwd 无关（实测）。
$process = proc_open(
    $command,
    [1 => ['file', $logPath, 'w'], 2 => ['file', $logPath, 'a']],
    $unusedPipes,
    win($work),
    $env,
    ['bypass_shell' => true]
);
if (!is_resource($process)) {
    toolchainFail('proc_open 起不来');
}
$exitCode = proc_close($process);
$elapsed = microtime(true) - $started;

$log = is_file($logPath) ? (string) file_get_contents($logPath) : '';
$lines = preg_split('/\r\n|\r|\n/', $log) ?: [];

// ── 断言（每条都对应一种"静默失败"） ────────────────────────────────────

$problems = [];

if ($exitCode !== 0) {
    $problems[] = "tpc 退出码 {$exitCode}";
}

$ccCount = null;
$prepared = null;
$converted = [];
$skipped = [];
$unsupported = [];
$cached = 0;
$backendLine = null;

foreach ($lines as $line) {
    if ($line === '') {
        continue;
    }
    if (preg_match('/^Dry run completed:\s*(\d+)\s*C\+\+ source file/', $line, $m) === 1) {
        $ccCount = (int) $m[1];
    } elseif (preg_match('/^prepare completed:\s*(\d+)\s*source files in total/', $line, $m) === 1) {
        $prepared = (int) $m[1];
    } elseif (preg_match('/^convert:\s*(.+)$/', $line, $m) === 1) {
        $converted[normalizeRelPath($m[1])] = true;
    } elseif (preg_match('/^ skip:\s*(.+)$/', $line, $m) === 1) {
        $skipped[] = $m[1];
    } elseif (str_contains($line, 'unsupported syntax:')) {
        $unsupported[] = trim($line);
    } elseif (str_starts_with($line, '[cached]')) {
        $cached++;
    } elseif (str_starts_with($line, 'Initialized platform/backend:')) {
        $backendLine = $line;
    }

    // PHP 层噪音（tpc 自己的 warning / deprecation 也算 —— 它是用 php.exe 跑的）
    if (preg_match('/\bFatal error\b|\bUncaught\b|\bPHP Warning\b|\bPHP Notice\b|\bDeprecated:/', $line) === 1) {
        $problems[] = 'PHP 层噪音：' . trim($line);
    }
}

if ($ccCount === null) {
    $problems[] = '没有 "Dry run completed" —— 翻译根本没跑完（或输出格式变了）';
}
if ($prepared === null) {
    $problems[] = '没有 "prepare completed: N source files in total" —— 源码收集那一阶段就没有';
}
if ($cached > 0) {
    $problems[] = "出现 {$cached} 行 [cached] —— 增量缓存没清掉 ⇒ 「每个文件都翻译过」这条断言失效";
}
if ($skipped !== []) {
    $problems[] = 'tpc 静默跳过了 ' . count($skipped) . ' 个文件（产物里会少它们）：' . implode(' , ', $skipped);
}
if ($unsupported !== []) {
    $problems[] = '不支持的语法 ' . count($unsupported) . ' 处：' . implode(' | ', array_slice($unsupported, 0, 5));
}
if ($backendLine === null) {
    $problems[] = '没有 "Initialized platform/backend" —— 靠 `--compiler` 传进去的假 cl 没被认（参数名变了？）';
} elseif (!str_contains(str_replace('\\', '/', strtolower($backendLine)), strtolower($cl))) {
    $problems[] = "backend 行里的编译器不是我们传的：{$backendLine}";
}

$expected = [];
foreach ($sources['list'] as $file) {
    $expected[normalizeRelPath($file)] = true;
}

$missing = array_diff(array_keys($expected), array_keys($converted));
if ($missing !== []) {
    $problems[] = count($missing) . ' 个源码文件没被 convert（tpc 连看都没看）：' . implode(' , ', array_slice($missing, 0, 10));
}
$extra = array_diff(array_keys($converted), array_keys($expected));
if ($extra !== []) {
    $problems[] = 'convert 了枚举之外的 ' . count($extra) . ' 个文件（sources 配置与实况不符）：' . implode(' , ', array_slice($extra, 0, 10));
}
if ($prepared !== null && $prepared !== count($expected)) {
    $problems[] = "tpc 自己数到 {$prepared} 个源文件，我们枚举到 " . count($expected) . ' 个 —— 两边必须一致';
}

$generated = is_file($generatedList)
    ? array_values(array_filter(preg_split('/\r\n|\r|\n/', (string) file_get_contents($generatedList)) ?: []))
    : null;
if ($ccCount !== null) {
    if ($generated === null) {
        $problems[] = 'TYPEPHP_GENERATED_SOURCE_LIST 没写出文件 —— 产物清单无从交叉核对';
    } elseif (count($generated) !== $ccCount) {
        $problems[] = '产物清单 ' . count($generated) . ' 行 ≠ 日志声明的 ' . $ccCount . ' 个 .cc';
    }
}

// ── 结论 ────────────────────────────────────────────────────────────────

if ($problems !== []) {
    fwrite(STDERR, "[drift-codegen] ✗ 门未通过（" . count($problems) . " 条）\n");
    foreach ($problems as $problem) {
        fwrite(STDERR, "    - {$problem}\n");
    }
    fwrite(STDERR, "  日志：{$logPath}\n");
    if ($verbose) {
        fwrite(STDERR, "─── 完整日志 ───\n{$log}\n");
    } else {
        fwrite(STDERR, "  （加 --verbose 看完整日志）\n");
    }
    exit(EXIT_GATE_FAILED);
}

printf(
    "[drift-codegen] ✓ %d 个 .php 全部翻译 · %d 个 .cc · 0 skip · %.1fs\n",
    count($expected),
    $ccCount ?? 0,
    $elapsed
);
exit(0);
