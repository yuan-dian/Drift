<?php
declare(strict_types=1);

namespace Drift\Dev;

/**
 * `drift-build` —— 真机构建一个应用，出 exe。
 *
 * 只做三件事：
 *   ① **把 bash 变成 MSVC 环境** —— tpc 自己找不到 cl.exe / ini / PHPX；
 *   ② **跑 tpc**，stdout/stderr 直接接终端（不经管道 ⇒ 实时，没有缓冲坑）；
 *   ③ **判产物** —— `exit==0` **且** exe 是新写出来的，缺一不可。
 *
 * 编译决策全归 tpc：它按 `build-dir/cache` 做增量。`--full` 只透传 `--force`。
 * 这里没有输入指纹、没有 SKIP、没有清缓存 —— 那些是重复 tpc 已有的能力。
 *
 * ★ 为什么不能直接手敲 `php tpc.php --project ... -O2 -m bin`：四个实测坑，
 *   每个都表现为"看不出根因的失败"：
 *   ① tpc **不加载同目录 php.ini** ⇒ 缺 mbstring 直接 Fatal（必须 PHPRC）；
 *   ② 发行版 ini 的 `extension_dir` 相对 cwd ⇒ 抄一份改成绝对路径；
 *   ③ `PHPX_HOME` 不设 ⇒ `phpx directory not found`；
 *   ④ `cl.exe` 要 `INCLUDE`/`LIB`/`PATH`（`vcvars64.bat` 干的事），bash 里没法 `call`。
 *   ⇒ 这四条的定位与拼装都在 `Doctor\Environment`，本文件只消费。
 */

use Drift\Dev\Doctor\Environment;
use Drift\Dev\Doctor\EnvironmentException;

require_once __DIR__ . '/autoload.php';

const ROOT = __DIR__ . '/..';

function fail(string $message): never
{
    fwrite(STDERR, "[drift-build] ✗ {$message}\n");
    exit(1);
}

/** 反斜杠化：PHP 是原生 Windows 程序，不认 `/d/...` */
function win(string $path): string
{
    return str_replace('/', '\\', $path);
}

/**
 * 运行时 ini + 信任根，落在 exe 旁边。
 *
 * ★ 必需：AOT exe 靠**同目录** `php.ini` 加载扩展，缺了就是 `\mb_substr` 未定义；
 *   证书缺了 HTTPS 报 "unable to get local issuer certificate"。
 * ★ 这里写构建机 `extension_dir` 的绝对路径 —— 真机探针以仓库根为 cwd 派生临时
 *   ini，相对的 `ext/` 会解析到不存在的目录。分发包不用这份（CI 另生成）。
 */
function distributeRuntime(string $root, string $distRoot): void
{
    $ini = "{$root}/php.ini";
    $text = 'extension_dir = "' . win($distRoot) . "/ext/\"\n"
        . "extension=mbstring\nextension=openssl\nextension=curl\n";
    file_put_contents($ini, $text);
    fwrite(STDOUT, "[drift-build] ✓ 运行时 ini → {$ini}（mbstring / openssl / curl）\n");

    $bundle = "{$root}/assets/cacert.pem";
    if (is_file($bundle)) {
        copy($bundle, "{$root}/cacert.pem");
        fwrite(STDOUT, "[drift-build] ✓ TLS 信任根 → {$root}/cacert.pem\n");
    } else {
        fwrite(STDOUT, "[drift-build] ! 没有 assets/cacert.pem ⇒ HTTPS 只能靠系统证书库\n");
    }
}

function usage(): never
{
    fwrite(STDOUT, <<<TXT
    drift-build —— 真机构建一个应用，出 exe

      用法： php tools/drift-build.php [应用名或工程路径] [--full] [--jobs N]

      应用名    ⇒ `apps/<名字>/project.yml`（默认 hello）
      工程路径  ⇒ 目录或 `.yml`（`probes/io_pool`；约定目录名 == `name:`）

      --full       给 tpc 传 `--force`，重新翻译全部源码（怀疑产物 stale 时用）
      --jobs N     透传 tpc 的 `-j`。**默认 1**：本机 cl.exe 并发会偶发挂死
      --help       本帮助

    ★ 默认走 tpc 的增量（不清缓存、不删 build）。改一行 PHP 约 1 分钟；
      全量约 8 分钟。想彻底重建：`--full`，或手动删 `build/` 与 `apps/<app>/build/`。

    ★ 环境变量：DRIFT_BUILD_JOBS 同 --jobs；DRIFT_TPC_ENTRY / DRIFT_MSVC /
      DRIFT_KITS / DRIFT_SDK_VERSION / DRIFT_PHPX_HOME 换机器时覆盖。

    TXT);
    exit(0);
}

// ────────────────────────────────────────────────────────────── 参数 ──

$argvRest = array_slice($argv, 1);
if (in_array('--help', $argvRest, true) || in_array('-h', $argvRest, true)) {
    usage();
}

$full = false;
$jobsRaw = (string) (getenv('DRIFT_BUILD_JOBS') ?: '1');
$target = null;

for ($i = 0; $i < count($argvRest); $i++) {
    $arg = (string) $argvRest[$i];
    if ($arg === '--full') {
        $full = true;
        continue;
    }
    if ($arg === '--jobs' && isset($argvRest[$i + 1])) {
        $jobsRaw = (string) $argvRest[++$i];
        continue;
    }
    if (str_starts_with($arg, '--jobs=')) {
        $jobsRaw = substr($arg, 7);
        continue;
    }
    if ($arg !== '' && $arg[0] === '-') {
        fail("未知选项：{$arg}（见 --help）");
    }
    if ($target !== null) {
        fail("只收一个位置参数，多给了：{$arg}");
    }
    $target = $arg;
}

if (!ctype_digit($jobsRaw) || (int) $jobsRaw < 1) {
    fail("--jobs 只收正整数（收到 {$jobsRaw}）");
}
$jobs = (int) $jobsRaw;
$target ??= 'hello';

// ──────────────────────────────────────────────────────── 工程与产物 ──
//
// ★ 为什么名字和路径合一：探针自己写一份构建脚本，就是第 4 份 MSVC 环境配方，
//   而它复制不了运行时 ini 与这套诊断格式。

$root = realpath(ROOT);
if ($root === false) {
    fail('解析仓库根失败');
}

$looksLikePath = str_contains($target, '/') || str_contains($target, '\\')
    || str_ends_with($target, '.yml') || str_ends_with($target, '.yaml');

if ($looksLikePath) {
    $project = $target;
    if (!str_starts_with($project, '/') && preg_match('#^[A-Za-z]:#', $project) !== 1) {
        $project = $root . '/' . $project;   // 相对仓库根（与"从仓库根调用"的约定一致）
    }
    if (is_dir($project)) {
        $project .= '/project.yml';
    }
    $resolved = realpath($project);
    if ($resolved === false || !is_file($resolved)) {
        fail("找不到工程文件：{$target}");
    }
    $project = $resolved;
    // ★ 约定：目录名 == `name:`。tpc 把 exe 落在 cwd、文件名取自 `name:`，
    //   这里按目录名算路径 ⇒ 不一致时的症状是"构建成功但找不到产物"。
    $app = basename(dirname($project));
} else {
    $app = $target;
    if (preg_match('/^[A-Za-z0-9_-]+$/', $app) !== 1) {
        fail("应用名不合法：{$app}");
    }
    $project = "{$root}/apps/{$app}/project.yml";
    if (!is_file($project)) {
        fail("找不到工程文件：{$project}");
    }
}

$exe = "{$root}/{$app}.exe";

// ───────────────────────────────────────────────────────────── 环境 ──

try {
    $prepared = Environment::prepare($root);
} catch (EnvironmentException $e) {
    fail($e->getMessage());
}

// 宿主注入的变量可能把环境块顶过 Windows 上限（32767 字节），Environment 已裁过。
// 说出来，免得有人以为自己设的变量"没生效"。
if ($prepared->envPruned !== []) {
    $names = array_keys($prepared->envPruned);
    fwrite(STDOUT, sprintf(
        "[drift-build] 环境已裁剪 %d 项（%d 字节）：%s\n",
        count($names),
        array_sum($prepared->envPruned),
        implode(', ', array_slice($names, 0, 3)) . (count($names) > 3 ? ' …' : ''),
    ));
}

// ───────────────────────────────────────────────────────────── 构建 ──

$command = [
    PHP_BINARY,
    $prepared->tpcEntry,
    '--project', $project,
    '-O2', '-m', 'bin',
    '-j', (string) $jobs,
];
if ($full) {
    $command[] = '--force';
}

// 先打印再跑：构建失败时第一问永远是"到底调了什么、用了哪套 env"。
$shown = array_map(
    static fn(string $part): string => str_contains($part, ' ') ? '"' . $part . '"' : $part,
    $command,
);
fwrite(STDOUT, '[drift-build] argv：' . implode(' ', $shown) . "\n");
fwrite(STDOUT, "[drift-build] cwd ：{$root}\n");

// ★ 先删旧产物：否则"tpc 失败 + 上一轮的 exe 还在"会被下面的判据读成成功
//   （判据是 exit==0 **且** exe 存在，两个条件都必要）。
if (is_file($exe) && !@unlink($exe)) {
    fail("删不掉旧产物 {$exe} —— 可能还有进程在跑（Windows 会锁住运行中的 exe）");
}

$started = microtime(true);

// ★ stdout/stderr **直接接终端**（传 STDOUT / STDERR 资源），不经管道：
//   Windows 匿名管道设不了非阻塞（`stream_set_blocking` 返回 false），读管道会
//   阻塞到进程退出 ⇒ 构建期间零输出，"真卡"与"只是慢"在屏幕上一模一样。
// ★ 数组形态 + bypass_shell：不经 cmd.exe，父进程被截断时不会多一个孤儿层。
$process = proc_open(
    $command,
    [0 => ['file', 'nul', 'r'], 1 => STDOUT, 2 => STDERR],
    $pipes,
    $root,
    $prepared->env,
    ['bypass_shell' => true],
);
if (!is_resource($process)) {
    fail('启动 tpc 失败');
}
$code = proc_close($process);

// 双判据：exit 只说明 tpc 自认为结束了，产物在才是真编出来了。
if ($code !== 0 || !is_file($exe)) {
    fail(sprintf('构建失败（exit=%d，%.1fs）', $code, microtime(true) - $started));
}

fwrite(STDOUT, sprintf(
    "[drift-build] ✓ %s（%.1f MB，%.1fs）\n",
    $exe,
    (float) filesize($exe) / 1048576.0,
    microtime(true) - $started,
));

distributeRuntime($root, $prepared->distRoot);
