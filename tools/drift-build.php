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

use Drift\Dev\Abi\Contract;
use Drift\Dev\Build\StalePolicy;
use Drift\Dev\Doctor\Environment;
use Drift\Dev\Doctor\EnvironmentException;

require_once __DIR__ . '/autoload.php';

const ROOT = __DIR__ . '/..';

// MSVC / SDK 的版本默认值住 `Doctor\Environment`（单一真相源），这里不再各存一份。

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

/**
 * 目录内所有指定后缀文件的内容哈希（确定性：按相对路径排序）。
 *
 * ★ 用于「输入清单」：判断自上次构建以来源文件是否变了。
 *   ★ 只认**内容**（`hash_file`），不认 mtime —— `git checkout` / `touch` 会改
 *     mtime 但内容不变，按 mtime 判会误报"变了"从而白清一次缓存。
 *
 * @param string $suffix 只要这个后缀；'' ⇒ 全部文件
 */
function hashTree(string $dir, string $suffix = '.php'): string
{
    if (!is_dir($dir)) {
        return '';
    }

    $files = [];
    $walker = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walker as $file) {
        if (!$file instanceof \SplFileInfo || !$file->isFile()) {
            continue;
        }
        if ($suffix !== '' && !str_ends_with($file->getFilename(), $suffix)) {
            continue;
        }
        $files[] = $file->getPathname();
    }
    sort($files, SORT_STRING);

    $ctx = hash_init('sha256');
    foreach ($files as $path) {
        // 相对路径进哈希：文件改名也算「输入变了」
        hash_update($ctx, str_replace('\\', '/', substr($path, strlen($dir))));
        hash_update($ctx, "\0");
        hash_update($ctx, (string) hash_file('sha256', $path));
        hash_update($ctx, "\n");
    }

    return hash_final($ctx);
}

/**
 * 清空 tpc 缓存目录，**保留**受版本控制的 `base.yml` / `app.manifest`。
 *
 * ★ 为什么不直接 `rm -rf build`：那两个文件是 tpc 的配置与 Windows 清单，
 *   删了下次构建直接失败（`manifest: ../../build/app.manifest` 找不到）——
 *   而失败现场看起来像"清缓存把构建弄坏了"，把排查方向带偏。
 */
function wipeCache(string $buildDir): void
{
    if (!is_dir($buildDir)) {
        return;
    }

    $keep = ['base.yml', 'app.manifest'];
    foreach (new \DirectoryIterator($buildDir) as $item) {
        if ($item->isDot()) {
            continue;
        }
        if (in_array($item->getFilename(), $keep, true)) {
            continue;
        }

        $path = $item->getPathname();
        if ($item->isDir()) {
            $inner = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($inner as $child) {
                if ($child->isDir()) {
                    rmdir($child->getPathname());
                } else {
                    unlink($child->getPathname());
                }
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }
}

/**
 * 随 exe 分发运行时 ini 与 TLS 信任根。构建成功与「跳过编译」都走这一份，
 * 保证 `drift-build` 的调用语义不因短路而缺半截。
 */
function distributeRuntime(string $root, string $distRoot): void
{
    // ★ AOT 运行时靠同目录 php.ini 加载扩展：不随附则 `\mb_substr` 等未定义。
    //   mbstring 是编译产物必需；openssl + curl 是应用侧 HTTP 必需（FetchUsersJob）。
    //   仍不启 zip / gd 等无消费者的重扩展。
    $runtimeExtensions = ['mbstring', 'openssl', 'curl'];
    $runtimeIni = "{$root}/php.ini";
    $runtimeText = "extension_dir = \"" . win($distRoot) . "/ext/\"\n";
    foreach ($runtimeExtensions as $ext) {
        $runtimeText .= "extension={$ext}\n";
    }
    file_put_contents($runtimeIni, $runtimeText);
    fwrite(STDOUT, "[drift-build] ✓ 运行时 ini → {$runtimeIni}（启用 " . implode(' / ', $runtimeExtensions) . "）\n");

    // ★ 信任根（2026-11-02）：ini 只写 extension 时 curl 信任库为空 ⇒ 真 HTTPS 报
    //   "unable to get local issuer certificate"。拷到 exe 旁边由 Http 自己找；
    //   找不到时 Http 退到系统证书库。不把绝对路径写进 ini（换机器就失效）。
    $trustBundle = "{$root}/assets/cacert.pem";
    if (is_file($trustBundle)) {
        $runtimeBundle = "{$root}/cacert.pem";
        if (!copy($trustBundle, $runtimeBundle)) {
            fwrite(STDERR, "[drift-build] ✗ 证书包拷贝失败：{$trustBundle} → {$runtimeBundle}\n");
            exit(1);
        }
        fwrite(STDOUT, "[drift-build] ✓ TLS 信任根 → {$runtimeBundle}（" . number_format((int) filesize($runtimeBundle)) . " 字节）\n");
    } else {
        // 不静默：少了它 HTTPS 只能靠系统证书库。
        fwrite(STDOUT, "[drift-build] ! 没有 assets/cacert.pem ⇒ 运行期只能靠系统证书库（HTTPS 可能失败）\n");
    }
}

function usage(): never
{
    fwrite(STDOUT, <<<TXT
    drift-build —— 真机构建一个应用（调用 cl.exe / link.exe 出 exe）

      用法： php tools/drift-build.php [应用名或工程路径] [--full] [--dry]

      应用名    ⇒ `apps/<名字>/project.yml`（四个应用）
      工程路径  ⇒ 目录或 `.yml` 文件（`probes/io_pool`）—— 真机探针用同一个入口，
                   ★ 约定：目录名 == `name:`（产物按目录名算）

      --incremental  显式放行 tpc 增量（约 30–60s）。**默认不走增量**：只要输入指纹
                     变了 / 首次构建 / 产物不在，就自动清缓存全量（门不能信缓存，
                     见 Drift\Dev\Build\StalePolicy）。仅在你明知改动可安全增量时用，
                     代价可能是 stale 且源码层面全绿。
      --full         强制全量（不受短路影响）。默认已会自动全量，保留为显式写法。
      --dry          只算输入清单与**这次的决策**，打印 SKIP / FULL / INCREMENTAL，
                     **不构建、不清缓存**。
      ★ 输入未变且产物仍在 ⇒ 直接跳过编译（仍分发运行时 ini / 证书）。

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

// `--full`：强制全量。默认已按 StalePolicy 自动全量，保留为显式写法 / 兼容。
$full = in_array('--full', $argvRest, true);

// `--incremental`：唯一能走增量的开关。默认「门不能信缓存」——输入一变就自动全量
// （2026-11-10 对齐 T1 drift-codegen 的既有裁决），增量必须显式 opt-in。
$incremental = in_array('--incremental', $argvRest, true);

// `--dry`：只算输入清单与决策，**不构建、不清缓存**（廉价诊断）。
$dry = in_array('--dry', $argvRest, true);

// 位置参数：**应用名或工程路径**（2026-10-30）。
//   · 名字（`hello`）⇒ `apps/hello/project.yml` —— 四个应用的老路，逐字节不变；
//   · 含分隔符或以 `.yml` 结尾 ⇒ 当成路径（`probes/io_pool`）—— **真机探针**用。
//   ★ 为什么要合一：探针自己写一份 `build.sh` 就是第 4 份 MSVC 环境配方
//     （`INCLUDE` / `LIB` / `PHPRC` 三件套），而它**复制不了**增量缓存、输入指纹、
//     运行时 ini 与这套诊断格式 ⇒ 按"能替代就只留一套"，让它走同一个入口。
$target = 'hello';
foreach ($argvRest as $argument) {
    if ($argument !== '' && $argument[0] !== '-') {
        $target = $argument;
        break;
    }
}

$root = realpath(ROOT);
if ($root === false) {
    fail('解析仓库根失败');
}

$looksLikePath = str_contains($target, '/') || str_contains($target, '\\')
    || str_ends_with($target, '.yml') || str_ends_with($target, '.yaml');

if ($looksLikePath) {
    $project = $target;
    if (!str_starts_with($project, '/') && preg_match('#^[A-Za-z]:#', $project) !== 1) {
        $project = $root . '/' . $project;   // 相对**仓库根**（与"从仓库根调用"的约定一致）
    }
    if (is_dir($project)) {
        $project .= '/project.yml';
    }
    $resolved = realpath($project);
    if ($resolved === false || !is_file($resolved)) {
        fail("找不到工程文件：{$target}");
    }
    $project = $resolved;
    $projectDir = dirname($project);

    // ★ 约定：**目录名 == `name:`**。产物是 `{$root}/{$app}.exe`（tpc 把 exe 落在
    //   cwd，文件名取自 `name:`），而这里按目录名算路径 ⇒ 两边不一致时症状是
    //   "构建成功但找不到产物"，排查成本远高于一条命名约定。
    $app = basename($projectDir);
} else {
    $app = $target;
    if (preg_match('/^[A-Za-z0-9_-]+$/', $app) !== 1) {
        fail("应用名不合法：{$app}");
    }
    $projectDir = "{$root}/apps/{$app}";
    $project = "{$projectDir}/project.yml";
    if (!is_file($project)) {
        fail("找不到工程文件：{$project}");
    }
}

// ─────────────────────── 环境前置（单一真相源：`Doctor\Environment`）──
//
// ★ 定位逻辑（tpc 入口 / 私有 ini / PHPX / MSVC / SDK / env 拼装）全在 Environment 里：
//   `doctor` 体检与这次构建走**同一套**推导 —— 此前这里自己写一份，`drift-codegen`
//   再写一份，两处一漂就出现"体检说没事、构建却失败"。第三个消费者到了，按本文件
//   原注释的约定抽出来（`drift-codegen` 也改成消费它）。
// ★ 这里只负责把"不满足"翻译成 drift-build 自己的 `fail()` 口径（消息文本不变）。

$phpBinary = PHP_BINARY;

try {
    $prepared = Environment::prepare($root);
} catch (EnvironmentException $e) {
    fail($e->getMessage());
}

$entry = $prepared->tpcEntry;
$distRoot = $prepared->distRoot;
$env = $prepared->env;
$msvcVersion = $prepared->msvcVersion;
$sdkVersion = $prepared->sdkVersion;
$work = "{$root}/_build";

// PHPX / MSVC / SDK / env 拼装已移入 `Environment::prepare()`（见上）。

// ─────────────────────── 输入清单 + 缓存策略（M2）───────────────────────
//
// 目的：把"要不要全量重建"从**人工直觉**变成**可核对的事实**。
//   · 输入清单（`_build/inputs.<应用>.sha`）记录上次成功构建的指纹；
//   · 指纹相同且产物还在 ⇒ SKIP（跳过编译）；
//   · 指纹变了 / 首次 / 产物不在 ⇒ FULL，自动清根 + app 级缓存；
//   · 只有 `--incremental` 显式放行才走增量（门不能信缓存，详见 StalePolicy）。
//   ★ 裁决与 IO 分离：`StalePolicy::decide()` 是纯函数，判据在 `tests/Unit/Build`
//     与 `tools/build-stale-check.php`，两处都不读这段 IO 代码。
//
// ★ 清单落 `_build/`（drift-build 自己的 scratch，.gitignore 已忽略），不落
//   `build/`：后者会被 `--full` 清空。
// ★ 按应用分文件：dev / hello / showcase 交替构建也不会互相覆盖清单 ⇒
//   「连续构建同一应用」的短路对每一个都成立。
$manifestFile = "{$work}/inputs.{$app}.sha";

$inputs = hash_init('sha256');
hash_update($inputs, 'src:' . hashTree("{$root}/src") . "\n");
// ★ 指纹算的是**工程目录**（不是 `apps/{$app}`）：应用与探针走同一条路，
//   而且探针的目录名与 `name:` 一致是约定，两者等价。
hash_update($inputs, 'app:' . hashTree($projectDir) . "\n");
hash_update($inputs, 'cpp:' . hashTree("{$root}/cpp-src") . "\n");
// 配置与清单是**受控文件**：改了它们同样影响构建结果
hash_update($inputs, 'base:' . (is_file("{$root}/build/base.yml") ? (string) hash_file('sha256', "{$root}/build/base.yml") : '') . "\n");
hash_update($inputs, 'manifest:' . (is_file("{$root}/build/app.manifest") ? (string) hash_file('sha256', "{$root}/build/app.manifest") : '') . "\n");
// tpc 编译器本身（源码树入口）变了 ⇒ 旧缓存可能不兼容
hash_update($inputs, 'tpc:' . (string) hash_file('sha256', $entry) . "\n");
$inputsHash = hash_final($inputs);

// ─────────────────────── 跨层 ABI 契约 preflight ───────────────────────
// ★ 任何构建路径（含 --dry / 短路）都先过这一关：stub 与 C++ 实现签名不一致时
//   tpc 链接期静默失败 —— 产物能构建、能起窗，但行为不对。gate 是日常入口，
//   这里是「直接跑 drift-build」那条路径的兜底。
$abi = Contract::check($root);
if (!$abi['ok']) {
    fwrite(STDERR, "[drift-build] ABI 契约校验失败：\n");
    foreach ($abi['problems'] as $p) {
        fwrite(STDERR, '  - ' . $p . "\n");
    }
    exit(1);
}

$previous = is_file($manifestFile) ? trim((string) file_get_contents($manifestFile)) : '';
$exe = "{$root}/{$app}.exe";

// 决策只认事实：策略是纯函数（`StalePolicy`），IO 全在本文件。判据在
// `tests/Unit/Build/StalePolicyTest.php` 验行为、`tools/build-stale-check.php` 验接线。
$decision = StalePolicy::decide(
    $previous,
    $inputsHash,
    is_file($exe),
    $full,
    $incremental,
);

fwrite(STDOUT, "[drift-build] {$decision}：" . StalePolicy::label($decision) . "\n");
fwrite(STDOUT, sprintf(
    "[drift-build] 输入指纹 上次=%s 本次=%s（%s）\n",
    $previous === '' ? '无' : substr($previous, 0, 12),
    substr($inputsHash, 0, 12),
    $previous === $inputsHash ? '相同' : '已变',
));

// ★ --dry 必须在 wipeCache 之前退出：否则 `--dry --full` 会「只算决策」却真清了缓存。
if ($dry) {
    fwrite(STDOUT, "[drift-build] --dry：只算决策，不构建、不清缓存\n");
    exit(0);
}

if ($decision === StalePolicy::FULL) {
    fwrite(STDOUT, "[drift-build] 清空 tpc 缓存（保留 base.yml / app.manifest）\n");
    wipeCache("{$root}/build");
    // ★ app 级缓存不在根 build 里：只清根会让旧 .obj / 生成 .cc 把旧签名常量带进
    //   新 exe（2026-11-10 watermark 实证），而那一刻源码层面已经全绿。
    $appBuild = "{$projectDir}/build";
    if (is_dir($appBuild)) {
        fwrite(STDOUT, "[drift-build] 清空 app 级缓存 {$projectDir}/build\n");
        wipeCache($appBuild);
    }
}

if ($decision === StalePolicy::INCREMENTAL) {
    fwrite(STDOUT, "[drift-build] 走 tpc 增量（未清缓存）—— 产物行为异常就去掉 --incremental 重跑\n");
}

// ─────────────────────── 短路：输入未变且产物仍在 ───────────────────────
// ★ 清单只在「构建成功且产物存在」之后写，所以「决策为 SKIP」就足以证明它是
//   上次成功构建的那一版；产物被删自然不短路。
// ★ 不能用 mtime 比产物与清单：清单写在产物之后，mtime 永远更晚 ⇒ 短路永不生效。
// ★ 分发 ini 与 TLS 信任根照做 ⇒ 跳过编译不改变一次调用的语义。
if ($decision === StalePolicy::SKIP) {
    fwrite(STDOUT, sprintf(
        "[drift-build] 输入未变且产物仍在 ⇒ 跳过编译（%s，%.1f MB）\n",
        basename($exe),
        (float) filesize($exe) / 1048576.0
    ));
    distributeRuntime($root, $distRoot);
    exit(0);
}

// ───────────────────────────────────────────────────────────── 构建 ──

$command = [$phpBinary, $entry, '--project', $project, '-O2', '-m', 'bin'];

fwrite(STDOUT, sprintf(
    "[drift-build] 工程 %s ｜ 入口 %s ｜ MSVC %s ｜ SDK %s\n",
    $app,
    $entry,
    $msvcVersion,
    $sdkVersion
));

$started = microtime(true);

// ★ 先删旧 exe：否则"tpc 失败 + 上一轮的 exe 还在"会被下面的判据读成成功
//   （判据是 exit==0 **且** exe 存在，两个条件都必要）。
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

// 记下本次构建的输入清单：下次构建据此判断"变没变"
file_put_contents($manifestFile, $inputsHash . "\n");

distributeRuntime($root, $distRoot);
