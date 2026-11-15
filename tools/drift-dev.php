<?php
declare(strict_types=1);

namespace Drift\Dev;

/**
 * `drift-dev` —— 开发态：改 PHP 保存即自动重启窗口，**不重编译**。
 *
 *   php tools/drift-dev.php [应用名] [--rebuild] [--no-watch] [--selftest]
 *
 * 与 `drift-build` 的分工：build 出全 AOT 产物（1–3 分钟）；这里只编一次
 * 「壳 + C++ 桥」，业务 PHP 由壳内嵌的 ZendVM 运行期 require。壳输入
 * （cpp-src / dev-shell.php / project.yml / 两个桥接回调文件）不变就永不重编。
 */

function out(string $message): void
{
    fwrite(STDOUT, "[drift-dev] {$message}\n");
}

function fail(string $message): never
{
    fwrite(STDERR, "[drift-dev] ✗ {$message}\n");
    exit(2);
}

function usage(): never
{
    fwrite(STDOUT, <<<TXT
    drift-dev —— 开发态运行器（改 PHP 即重启，壳不重编）

      用法： php tools/drift-dev.php [应用名] [--rebuild] [--no-watch] [--hard-restart]

      应用名    默认 hello；须在 composer.json 有 App\<名字>\ PSR-4 前缀
      --rebuild 强制重编 dev 壳（改了 cpp-src / 壳配置时其实会自动检测）
      --no-watch  只跑一次，不监听文件变化
      --hard-restart  跳过优雅退出：不把本次会话的 dirty 状态写回
      --selftest  指纹 + 壳红线自检（含反例），不启动进程；gate 也跑它

    ★ 热更是「跨进程重启」：内嵌 ZendVM 对同一路径的 require 会缓存 opcode，
      同进程重刷读不到新代码。重启前先优雅关窗，让 WindowSet::run() 的 finally
      写回业务状态；新进程由 RestorationStore 构造即恢复。超时才强杀。
      强杀不投 WM_CLOSE ⇒ finally 不跑 ⇒ 半截输入会丢，属预期降级。

    TXT);
    exit(0);
}

/** 相对仓库根的路径：换机器 / 换盘符不该让指纹变化 */
function relative(string $root, string $path): string
{
    $normalized = str_replace('\\', '/', $path);
    $base = str_replace('\\', '/', $root);

    return str_starts_with($normalized, $base . '/')
        ? substr($normalized, strlen($base) + 1)
        : $normalized;
}

/** @return list<string> 归一化后的绝对路径（按相对路径排序，确定性） */
function collectFiles(string $dir, array $suffixes = ['.php'], bool $skipBuild = false): array
{
    if (!is_dir($dir)) {
        return [];
    }
    $found = [];
    $walker = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walker as $file) {
        if (!$file instanceof \SplFileInfo || !$file->isFile()) {
            continue;
        }
        $path = str_replace('\\', '/', $file->getPathname());
        // 构建缓存每次编译都变，算进业务快照会无限重启。
        if ($skipBuild && str_contains($path, '/build/')) {
            continue;
        }
        if ($suffixes !== [] && !array_filter($suffixes, static fn(string $s): bool => str_ends_with($path, $s))) {
            continue;
        }
        $found[$path] = $path;
    }
    ksort($found, SORT_STRING);

    return array_values($found);
}

/**
 * 壳输入指纹：只含「进 AOT 产物」的东西。
 * ★ 业务 PHP 刻意不在内 —— 它们变了不该触发重编，这正是本命令的意义。
 */
function shellFingerprint(string $root): string
{
    $files = [
        "{$root}/apps/dev/dev-shell.php",
        "{$root}/apps/dev/project.yml",
        "{$root}/build/base.yml",
        "{$root}/build/app.manifest",
        "{$root}/src/Platform/Win32/ResizeHook.php",
        "{$root}/src/Platform/Win32/ResizeHookState.php",
    ];
    foreach (collectFiles("{$root}/cpp-src", ['.php', '.cc', '.h', '.hpp']) as $path) {
        $files[] = $path;
    }

    $ctx = hash_init('sha256');
    foreach ($files as $path) {
        hash_update($ctx, relative($root, $path) . "\0");
        hash_update($ctx, is_file($path) ? (string) hash_file('sha256', $path) : 'missing');
        hash_update($ctx, "\n");
    }

    return hash_final($ctx);
}

/** 业务输入快照：mtime + size，便宜到可以每 400ms 算一次。 */
function businessSnapshot(string $root, string $app): string
{
    $files = array_merge(
        collectFiles("{$root}/src", ['.php'], true),
        collectFiles("{$root}/apps/{$app}", ['.php'], true),
        [
            "{$root}/apps/dev/dev-entry.php",
            "{$root}/tools/autoload.php",
            "{$root}/composer.json",
        ]
    );

    $ctx = hash_init('sha256');
    foreach ($files as $path) {
        hash_update($ctx, relative($root, $path) . "\0");
        $stat = is_file($path) ? stat($path) : false;
        hash_update($ctx, $stat === false ? 'missing' : $stat['mtime'] . ':' . $stat['size']);
        hash_update($ctx, "\n");
    }

    return hash_final($ctx);
}

/**
 * 运行期 ini：以构建产物的 ini 为底（否则缺 mbstring），再关 opcache。
 * ★ 关 opcache 有两个理由：ASLR 下它拒绝启动会让进程秒退；且它缓存 opcode
 *   会挡住「改 PHP 重启即生效」。
 */
function runtimeIni(string $root): string
{
    $dir = "{$root}/_build/dev-ini";
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fail("建不了 {$dir}");
    }
    $base = is_file("{$root}/php.ini") ? (string) file_get_contents("{$root}/php.ini") : '';
    file_put_contents("{$dir}/php.ini", rtrim($base) . "\nopcache.enable=0\n");

    return $dir;
}

/** @return array{0: resource, 1: array<int, resource>} */
function spawn(string $exe, string $root, array $env): array
{
    $process = proc_open(
        [$exe],
        [0 => ['file', 'nul', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        $env
    );
    if (!is_resource($process)) {
        fail('启动 dev.exe 失败');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    return [$process, $pipes];
}

/**
 * 进程是否还活着。
 * ★ Windows 上 `proc_get_status()['running']` 对带窗口的子进程会短暂误报 false
 *   （实测 watcher 因此把正常窗口当成「已关闭」杀掉）。以输出管道 EOF 兜底：
 *   写句柄全部关闭是 OS 级事实，不会抖。
 *
 * @param resource $process
 */
function running($process, array $pipes): bool
{
    if (proc_get_status($process)['running']) {
        return true;
    }
    foreach ($pipes as $pipe) {
        if (is_resource($pipe) && !feof($pipe)) {
            return true;
        }
    }

    return false;
}

/** 输出管道全 EOF = 写句柄已关（OS 级事实，比 proc_get_status 可靠）。 */
function exited(array $pipes): bool
{
    foreach ($pipes as $pipe) {
        if (is_resource($pipe) && !feof($pipe)) {
            return false;
        }
    }

    return true;
}

/**
 * 请求优雅关闭并等待；true = 进程已退出（清理钩子有机会跑）。
 * 不强杀：杀与不杀由调用方按结果决定，避免两条路径各调一次。
 */
function gracefulStop(callable $requestClose, callable $alive, int $timeoutMs = 1500): bool
{
    $requestClose();
    $deadline = microtime(true) + $timeoutMs / 1000;
    while ($alive()) {
        if (microtime(true) >= $deadline) {
            return false;
        }
        usleep(50000);
    }

    return true;
}

function drain(array $pipes): void
{
    foreach ($pipes as $pipe) {
        if (!is_resource($pipe)) {
            continue;
        }
        $text = stream_get_contents($pipe);
        if ($text !== false && $text !== '') {
            fwrite(STDOUT, $text);
        }
    }
}

/**
 * @param resource $process
 * @return bool 是否优雅退出（false = 已强杀，本次会话状态未写回）
 */
function stop($process, array $pipes, bool $graceful = true): bool
{
    // ★ 实测：Windows 上 proc_open 对 AOT 进程返回的 pid / running 都不可靠
    //   （pid 在 tasklist 里查不到，running 会误报 yes）⇒ 按固定映像名 dev.exe 操作。
    //   不带 /F 会投 WM_CLOSE ⇒ 应用走正常关窗 ⇒ WindowSet::run() 的 finally 写回状态。
    $requestClose = PHP_OS_FAMILY === 'Windows'
        ? static function (): void { exec('taskkill /IM dev.exe 2>&1'); }
        : static function () use ($process): void { proc_terminate($process, 15); };
    $forceKill = PHP_OS_FAMILY === 'Windows'
        ? static function (): void { exec('taskkill /IM dev.exe /F 2>&1'); }
        : static function () use ($process): void { proc_terminate($process, 9); };

    $elegant = $graceful ? gracefulStop(
        $requestClose,
        static function () use ($pipes): bool {
            drain($pipes);

            return !exited($pipes);
        },
    ) : false;

    if (!$elegant) {
        $forceKill();
    }
    drain($pipes);
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($process);

    return $elegant;
}

/** project.yml 的 sources 段；只认 `- xxx` 行，不引 yaml 依赖。 */
function shellSources(string $yml): array
{
    if (preg_match('/^sources:[^\n]*\n((?:[ \t]+-[ \t]+[^\n]*\n)+)/m', $yml, $m) !== 1) {
        return [];
    }
    preg_match_all('/^[ \t]+-[ \t]+(\S+)/m', $m[1], $mm);

    return $mm[1] ?? [];
}

/**
 * 壳的红线：sources 必须**精确等于**白名单。多一个目录（尤其 `../../src`）就会
 * 把业务 PHP 编进 AOT，动态 require 同名类直接 Fatal「already in use」；少一个
 * 回调则 C++ 侧 extern 链接不上（LNK2019）。新增条目必须是一次显式裁决。
 */
function shellSourcesOk(array $sources): bool
{
    $allowed = [
        'dev-shell.php',
        '../../cpp-src',
        '../../src/Platform/Win32/ResizeHook.php',
        '../../src/Platform/Win32/ResizeHookState.php',
    ];

    return $sources !== []
        && array_diff($sources, $allowed) === []
        && array_diff($allowed, $sources) === [];
}

/** 三条静态红线：无窗口、无编译，专供 gate 在「日常不碰 AOT」时守着壳。 */
function shellContract(string $root): array
{
    $sources = shellSources((string) @file_get_contents("{$root}/apps/dev/project.yml"));
    $shell = (string) @file_get_contents("{$root}/apps/dev/dev-shell.php");

    return [
        '壳 sources 精确等于白名单' => shellSourcesOk($sources),
        '壳用变量路径 require' => preg_match('/require\s+\$\w+/', $shell) === 1
            && preg_match('/require\s*\(?\s*[\'"]/', $shell) !== 1,
        '两个桥接回调文件存在' => is_file("{$root}/src/Platform/Win32/ResizeHook.php")
            && is_file("{$root}/src/Platform/Win32/ResizeHookState.php"),
    ];
}

/**
 * 跨进程：优雅关闭让 finally 真跑、强杀不跑、超时有界。
 * 子进程是独立 PHP 进程 + 独立文件路径，判据不读 stop() 的配置字段。
 */
function gracefulContract(string $dir): array
{
    $checks = [];
    $child = "{$dir}/child.php";
    file_put_contents($child, <<<'PHP'
<?php
$sentinel = $argv[1];
try {
    while (true) {
        $line = fgets(STDIN);
        if ($line === false || trim($line) === 'q') {
            break;
        }
    }
} finally {
    usleep(100000); // 模拟清理钩子耗时：不等就判不出「没等」
    file_put_contents($sentinel, 'finally');
}
PHP);

    /** @return array{0: resource, 1: array<int, resource>} */
    $spawn = static function (string $sentinel) use ($child): array {
        $pipes = [];
        $proc = proc_open(
            [PHP_BINARY, $child, $sentinel],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        return [$proc, $pipes];
    };
    $close = static function (array $p): void {
        foreach ($p as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    };

    // ① 优雅路径
    [$proc, $pipes] = $spawn("{$dir}/elegant.flag");
    $asked = 0;
    $ok = gracefulStop(
        static function () use (&$asked, $pipes): void {
            $asked++;
            fwrite($pipes[0], "q\n");
        },
        static fn(): bool => proc_get_status($proc)['running'],
        1000,
    );
    $checks['优雅关闭返回 true'] = $ok;
    $checks['优雅关闭后 finally 真的写盘'] = is_file("{$dir}/elegant.flag")
        && file_get_contents("{$dir}/elegant.flag") === 'finally';
    $checks['requestClose 恰好一次'] = $asked === 1;
    $close($pipes);
    proc_close($proc);

    // ② 强杀对照：TerminateProcess 不给 finally 机会
    [$forced, $forcedPipes] = $spawn("{$dir}/forced.flag");
    proc_terminate($forced);
    usleep(250000);
    $checks['强杀不跑 finally（对照组）'] = !is_file("{$dir}/forced.flag");
    $close($forcedPipes);
    proc_close($forced);

    // ③ 超时有界
    $start = microtime(true);
    $timedOut = gracefulStop(
        static function (): void {},
        static fn(): bool => true,
        120,
    );
    $checks['alive 恒 true ⇒ 返回 false'] = $timedOut === false;
    $checks['超时有界（< 800ms）'] = (microtime(true) - $start) * 1000 < 800;

    return $checks;
}

/** 判据自检：正反例都要有，否则「跳过编译」可能是一句永远不会兑现的承诺。 */
function selftest(): int
{
    $root = sys_get_temp_dir() . '/drift-dev-selftest-' . bin2hex(random_bytes(4));
    foreach (['apps/dev', 'src/build', 'cpp-src', 'apps/hello', 'build'] as $dir) {
        mkdir("{$root}/{$dir}", 0777, true);
    }
    $write = static function (string $path, string $body): void {
        file_put_contents($path, $body);
    };
    $write("{$root}/apps/dev/dev-shell.php", '<?php');
    $write("{$root}/apps/dev/project.yml", 'name: dev');
    $write("{$root}/build/base.yml", 'no-console: false');
    $write("{$root}/cpp-src/drift.cc", 'int a;');
    $write("{$root}/src/a.php", '<?php');
    $write("{$root}/src/build/cache.php", '<?php');
    $write("{$root}/apps/hello/main.php", '<?php');

    $checks = [];
    $shell = shellFingerprint($root);
    $checks['壳指纹可重复'] = $shell === shellFingerprint($root);

    $write("{$root}/cpp-src/drift.cc", 'int b;');
    $checks['改 C++ 桥 ⇒ 要重编壳'] = shellFingerprint($root) !== $shell;

    $shell = shellFingerprint($root);
    $business = businessSnapshot($root, 'hello');
    touch("{$root}/src/a.php", time() + 10);
    clearstatcache();
    $checks['改业务 PHP ⇒ 不重编壳'] = shellFingerprint($root) === $shell;
    $checks['改业务 PHP ⇒ 触发重启'] = businessSnapshot($root, 'hello') !== $business;

    $business = businessSnapshot($root, 'hello');
    touch("{$root}/src/build/cache.php", time() + 20);
    clearstatcache();
    $checks['构建缓存变化 ⇒ 不重启'] = businessSnapshot($root, 'hello') === $business;

    // 壳的红线读真实仓库（指纹那几条用的是上面的临时树）。
    $realRoot = dirname(__DIR__);
    foreach (shellContract($realRoot) as $name => $ok) {
        $checks[$name] = $ok;
    }
    $live = shellSources((string) file_get_contents("{$realRoot}/apps/dev/project.yml"));
    $checks['反例：多编一个 src 目录 ⇒ 红线响'] = !shellSourcesOk([...$live, '../../src']);
    $checks['反例：少一个桥接回调 ⇒ 红线响'] = !shellSourcesOk(array_values(
        array_filter($live, static fn(string $s): bool => $s !== '../../src/Platform/Win32/ResizeHookState.php')
    ));

    // 优雅重启的跨进程契约：子进程独立起，判据读文件不读配置。
    foreach (gracefulContract($root) as $name => $ok) {
        $checks[$name] = $ok;
    }

    // 清理临时树（只删本次自建目录，不碰任何用户文件）
    $items = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($root);

    $failed = 0;
    foreach ($checks as $name => $ok) {
        fwrite(STDOUT, ($ok ? '  ok    ' : '  FAIL  ') . $name . "\n");
        $failed += $ok ? 0 : 1;
    }
    fwrite(STDOUT, "--- {$failed} FAIL / " . count($checks) . " 条判据 ---\n");

    return $failed === 0 ? 0 : 1;
}

// ───────────────────────────────────────────────────────────── 主流程 ──

$rest = array_slice($argv, 1);
if (in_array('--help', $rest, true) || in_array('-h', $rest, true)) {
    usage();
}
if (in_array('--selftest', $rest, true)) {
    exit(selftest());
}

$rebuild = in_array('--rebuild', $rest, true);
$noWatch = in_array('--no-watch', $rest, true);
$hardRestart = in_array('--hard-restart', $rest, true);

$app = 'hello';
foreach ($rest as $argument) {
    if ($argument !== '' && $argument[0] !== '-') {
        $app = $argument;
        break;
    }
}
if (preg_match('/^[A-Za-z0-9_]+$/', $app) !== 1) {
    fail("应用名不合法：{$app}");
}

$root = dirname(__DIR__);
$appDir = strtolower($app);
if (!is_file("{$root}/apps/{$appDir}/bin/win32/Bootstrap.php")) {
    fail("找不到组合根：apps/{$appDir}/bin/win32/Bootstrap.php");
}

$exe = "{$root}/dev.exe";
$shaFile = "{$root}/_build/dev-shell.sha";
$hash = shellFingerprint($root);
$previous = is_file($shaFile) ? trim((string) file_get_contents($shaFile)) : '';

if ($rebuild || !is_file($exe) || $previous !== $hash) {
    out('壳输入有变或无产物 ⇒ 编译 dev.exe（只此一次，之后改 PHP 不再编）');
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$root}/tools/drift-build.php") . ' dev';
    passthru($command, $code);
    if ($code !== 0 || !is_file($exe)) {
        fail('壳编译失败');
    }
    if (!is_dir(dirname($shaFile))) {
        mkdir(dirname($shaFile), 0777, true);
    }
    file_put_contents($shaFile, $hash . "\n");
} else {
    out('壳未变 ⇒ 跳过编译');
}

$iniDir = runtimeIni($root);
$env = getenv();
if (!is_array($env)) {
    $env = [];
}
// php8ts.dll 在 PHP 发行版目录；从任意 shell 启动时它未必在 PATH。
$env['PATH'] = dirname(PHP_BINARY) . ';' . (string) ($env['PATH'] ?? '');
$env['PHPRC'] = $iniDir;
$env['DRIFT_DEV_ENTRY'] = "{$root}/apps/dev/dev-entry.php";
$env['DRIFT_DEV_APP'] = $app;

$process = null;
$pipes = [];
register_shutdown_function(static function () use (&$process, &$pipes, $hardRestart): void {
    if ($process !== null) {
        stop($process, $pipes, !$hardRestart);
    }
});

$start = static function () use (&$process, &$pipes, $exe, $root, $env): void {
    [$process, $pipes] = spawn($exe, $root, $env);
    out('启动 dev.exe（PID ' . proc_get_status($process)['pid'] . '）');
};
$stopProcess = static function () use (&$process, &$pipes, $hardRestart): void {
    if ($process === null) {
        return;
    }
    if (!stop($process, $pipes, !$hardRestart)) {
        out('优雅关闭未生效 ⇒ 已强杀，本次会话状态未写回');
    }
    $process = null;
    $pipes = [];
};

$start();
out("应用 {$app} ｜ 监听 src/、apps/{$appDir}/、apps/dev/dev-entry.php（Ctrl+C 退出）");

$last = businessSnapshot($root, $appDir);
$restarts = 0;
while (true) {
    drain($pipes);
    $alive = $process !== null && running($process, $pipes);

    // ★ 用户关窗后 watcher 继续等改动，下次改动仍能拉起新窗口；
    //   进程句柄留到重启 / 退出时统一 close，不因一次误报就杀进程。
    if ($noWatch) {
        if (!$alive) {
            break;
        }
        usleep(100000);
        continue;
    }

    $current = businessSnapshot($root, $appDir);
    if ($current !== $last) {
        $last = $current;
        $stopProcess();
        $start();
        $restarts++;
        out("检测到改动 ⇒ 已重启（第 {$restarts} 次，壳未重编）");
    }
    usleep(400000);
}

$stopProcess();
out('进程结束');
exit(0);
