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

// 只引进程监督这一个类：本文件其余部分是自足的纯函数，不值得为它拉起整套 autoload。
use Drift\Dev\Build\Supervisor;

require_once __DIR__ . '/Build/Supervisor.php';

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
                                    [--project=<目录>] [--entry=<路径>] [--prebuilt]
                                    [--print-runtime]

      应用名    默认 hello；须在 composer.json 有 App\<名字>\ PSR-4 前缀
      --project=目录  宿主项目根（Drift 作为 composer 包引入时指向你的项目；默认 Drift 包根）
      --entry=路径  动态入口（默认 apps/dev/dev-entry.php）；相对路径按 --project 解析
      --prebuilt    用现成 dev.exe，跳过壳指纹检查与编译（发布包场景）
      --print-runtime  只打印壳 / 运行时 / ini 指向，不启动窗口
      --write-fingerprint  只写 _build/dev-shell.sha 后退出（发版打包用）
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

/** 写包内指纹：发版打包与 dev 壳自编共用这一处，避免两边算法漂移。 */
function writeShellFingerprint(string $root): string
{
    $shaFile = "{$root}/_build/dev-shell.sha";
    if (!is_dir(dirname($shaFile)) && !mkdir(dirname($shaFile), 0777, true) && !is_dir(dirname($shaFile))) {
        fail("建不了 " . dirname($shaFile));
    }
    $hash = shellFingerprint($root);
    file_put_contents($shaFile, $hash . "\n");

    return $hash;
}

/**
 * 宿主项目根：Drift 作为 composer 包引入时 != Drift 包目录。
 * ★ 相对路径按调用时的工作目录解析 —— 开发者在自己的项目根敲命令最自然。
 */
function resolveProject(string $root, string $value): string
{
    if (trim($value) === '') {
        return str_replace('\\', '/', $root);
    }
    $path = str_replace('\\', '/', $value);
    if (!preg_match('/^[A-Za-z]:/', $path) && !str_starts_with($path, '/')) {
        $path = rtrim(str_replace('\\', '/', (string) getcwd()), '/') . '/' . $path;
    }
    if (!is_dir($path)) {
        fail("找不到宿主项目根：{$path}（--project 指向含 composer.json 的项目目录）");
    }
    $real = realpath($path);

    return $real === false ? $path : str_replace('\\', '/', $real);
}

/**
 * 动态入口：相对路径按**宿主项目根**解析，绝对路径原样用。
 * ★ 壳端只读 DRIFT_DEV_ENTRY，默认入口仍在 Drift 包内；这里让发布包的预编译壳
 *   能指向开发者自己的项目入口（composer 包场景下两者不是同一个目录）。
 */
function resolveEntry(string $root, string $project, string $value): string
{
    $normalized = str_replace('\\', '/', $value);
    if ($normalized === '') {
        return str_replace('\\', '/', $root) . '/apps/dev/dev-entry.php';
    }
    $base = str_replace('\\', '/', $project);
    $path = preg_match('/^[A-Za-z]:/', $normalized) || str_starts_with($normalized, '/')
        ? $normalized
        : $base . '/' . $normalized;
    if (!is_file($path)) {
        fail("找不到动态入口：{$path}（--entry 相对 --project 解析）");
    }

    return $path;
}

/** 业务输入快照：mtime + size，便宜到可以每 400ms 算一次。 */
function businessSnapshot(string $root, string $app, ?string $entry = null, ?string $project = null): string
{
    $project ??= $root;
    $defaultEntry = str_replace('\\', '/', $root) . '/apps/dev/dev-entry.php';
    $files = array_merge(
        collectFiles("{$project}/src", ['.php'], true),
        collectFiles("{$root}/apps/{$app}", ['.php'], true),
        [
            $defaultEntry,
            "{$root}/tools/autoload.php",
            "{$root}/composer.json",
            "{$project}/composer.json",
        ]
    );
    // 自定义入口不在 apps/ 下：把入口及其同级 PHP 也纳入热更，否则改了不重启。
    if ($entry !== null && $entry !== $defaultEntry) {
        $files[] = $entry;
        $files = array_merge($files, collectFiles(dirname($entry), ['.php'], true));
    }

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
 * 生成壳用的 ini，返回 ini 所在目录（喂给 `PHPRC`）。
 *
 * ★ 两条来源，判据是「壳旁边有没有自带运行时」：
 *   - 发布包（`_build/dev-shell/`，带 phpx.dll / php8ts.dll / ext/）：扩展目录
 *     必须指向**包内** ext。抄宿主 php.ini 是错的 —— 那里面是构建机的绝对路径，
 *     换台机器扩展就全加载失败，报的是 `Call to undefined function mb_substr()`。
 *   - 本地开发机（壳由 drift-build 编出、DLL 来自完整 TypePHP 发行版）：沿用
 *     宿主配置。两条路都要关 opcache：ASLR 下它拒绝启动会让进程秒退，且它缓存
 *     opcode 会挡住「改 PHP 重启即生效」。
 */
function runtimeIni(string $root, ?string $project, string $runtimeDir): string
{
    $project ??= $root;
    // ini 落在 Drift 包内（壳以它为 cwd），内容优先取宿主项目的配置。
    $dir = "{$root}/_build/dev-ini";
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fail("建不了 {$dir}");
    }

    $packedExt = "{$runtimeDir}/ext";
    if (is_file("{$runtimeDir}/php8ts.dll") && is_dir($packedExt)) {
        // 扩展名单与 tools/ci/dev-shell-files.php 的清单同源：那边声明包里必须有
        // 哪三个 DLL，这边写 ini 去加载它们。
        $text = 'extension_dir = "' . win($packedExt) . "\"\n"
            . "extension=mbstring\nextension=openssl\nextension=curl\nopcache.enable=0\n";
        file_put_contents("{$dir}/php.ini", $text);

        return $dir;
    }

    $source = is_file("{$project}/php.ini") ? "{$project}/php.ini"
        : (is_file("{$root}/php.ini") ? "{$root}/php.ini" : (string) php_ini_loaded_file());
    $base = $source !== '' ? (string) file_get_contents($source) : '';
    if (trim($base) === '') {
        // 三层都没有（根 php.ini 不再入库，发行版也可能没放 ini）：自己拼一份最小可用的。
        // 空 ini 的症状是壳起来就 `undefined function mb_substr`，比缺扩展难看得多。
        $base = 'extension_dir = "' . win(dirname(win(PHP_BINARY))) . "/ext/\"\n"
            . "extension=mbstring\nextension=openssl\nextension=curl\n";
    }
    file_put_contents("{$dir}/php.ini", rtrim($base) . "\nopcache.enable=0\n");

    return $dir;
}

/** 反斜杠化的 Windows 路径（ini 里的路径交给 PHP 解析，不经过 shell） */
function win(string $path): string
{
    return str_replace('/', '\\', $path);
}

/**
 * 当前所有 `dev.exe` 的 PID。
 *
 * ★ 为什么不能只用 `proc_get_status()['pid']`：AOT 产物在 Windows 上那个号在
 *   tasklist 里查不到（历史实测），于是停止只能按映像名 `/IM` —— 而 `/IM` 会连带
 *   杀掉**别的终端**里那个 dev.exe，它的 `finally` 不跑、业务状态直接丢。
 *   改为「启动前记集合、启动后取差集」，只动本次真正起的那个。
 *
 * @return list<int>
 */
function devExePids(): array
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return [];
    }
    $lines = [];
    exec('tasklist /FI "IMAGENAME eq dev.exe" /NH /FO CSV 2>NUL', $lines);
    $pids = [];
    foreach ($lines as $line) {
        if (preg_match('/"dev\.exe"\s*,\s*"(\d+)"/i', $line, $m) === 1) {
            $pids[] = (int) $m[1];
        }
    }

    return $pids;
}

/**
 * 等一个新出现的 `dev.exe` PID。
 *
 * ★ 并发起两个 drift-dev 时，两边可能各把对方认成自己的（窗口只有几十毫秒）。
 *   这是 `/IM` 之外唯一可行的口径 —— 已知限制，写在这里而不是留给下一个人猜。
 *
 * @param list<int> $before
 */
function awaitNewDevPid(array $before, float $timeout = 2.0): ?int
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return null;
    }
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        $fresh = array_values(array_diff(devExePids(), $before));
        if ($fresh !== []) {
            return $fresh[0];
        }
        usleep(50000);
    }

    return null;
}

/**
 * @return array{0: resource, 1: array<int, resource>, 2: ?int} 第 3 项 = 本次起的 PID
 */
function spawn(string $exe, string $root, array $env): array
{
    $before = devExePids();
    $process = proc_open(
        [$exe],
        [0 => ['file', 'nul', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        $env,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        fail('启动 dev.exe 失败');
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    return [$process, $pipes, awaitNewDevPid($before)];
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
 * @param int|null $pid 本次起的 PID；null 时退回按映像名（会警告）
 * @return bool 是否优雅退出（false = 已强杀，本次会话状态未写回）
 */
function stop($process, array $pipes, bool $graceful = true, ?int $pid = null): bool
{
    $warned = false;
    $signal = static function (bool $force) use ($process, $pid, &$warned): void {
        if (PHP_OS_FAMILY !== 'Windows') {
            proc_terminate($process, $force ? 9 : 15);

            return;
        }
        // 不带 /F 会投 WM_CLOSE ⇒ 应用走正常关窗 ⇒ WindowSet::run() 的 finally 写回状态。
        if ($pid !== null) {
            exec('taskkill /PID ' . $pid . ($force ? ' /F' : '') . ' 2>NUL');

            return;
        }
        if (!$warned) {
            $warned = true;
            out('! 没认出本次 dev.exe 的 PID ⇒ 退回按映像名停进程，可能连带关掉别的终端里那个');
        }
        exec('taskkill /IM dev.exe' . ($force ? ' /F' : '') . ' 2>&1');
    };

    $elegant = $graceful ? gracefulStop(
        static function () use ($signal): void {
            $signal(false);
        },
        static function () use ($pipes): bool {
            drain($pipes);

            return !exited($pipes);
        },
    ) : false;

    if (!$elegant) {
        $signal(true);
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

/**
 * 编壳：以子进程方式跑 `drift-build dev`，输出实时透传。
 *
 * ★ 不用 `passthru()`：它不返回句柄，父进程被 SIGTERM 截断时 `cmd.exe` 与 `tpc.php`
 *   都还活着 —— 孤儿握着 MSVC 的 PDB 锁，下一次构建在链接阶段等锁（"卡一次之后次次卡"）。
 * ★ 不设停滞阈值：内层 `drift-build` 自己带看门狗，外层再判一次会两边打架、
 *   同一个死法报两遍。这里只负责透传与「父进程被截断时能收拾」。
 */
function buildShell(string $root): void
{
    $work = "{$root}/_build";
    if (!is_dir($work) && !mkdir($work, 0777, true) && !is_dir($work)) {
        fail("建不了 {$work}");
    }
    $outLog = "{$work}/dev-build.stdout.log";
    $errLog = "{$work}/dev-build.stderr.log";
    @unlink($outLog);
    @unlink($errLog);

    // 输出走文件：Windows 管道设不了非阻塞，构建 3 分钟里会一个字都看不到。
    $process = proc_open(
        [PHP_BINARY, "{$root}/tools/drift-build.php", 'dev'],
        [0 => ['file', 'nul', 'r'], 1 => ['file', $outLog, 'w'], 2 => ['file', $errLog, 'w']],
        $pipes,
        $root,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        fail('启动构建失败');
    }

    $result = (new Supervisor($process, $outLog, $errLog, 0.0, [], static function (string $chunk): void {
        fwrite(STDOUT, $chunk);
    }))->run();

    // 双判据：exit==0 只说明 tpc 说完了，产物不在就是没编出来（判据与 drift-build 同一口径）。
    if ($result['exit'] !== 0 || !is_file("{$root}/dev.exe")) {
        fail("壳编译失败（exit={$result['exit']}）");
    }
}

/** project.yml 的 sources 段；同时认 `- xxx` 与 `- path: xxx`（后者是条件 sources）。 */
function shellSources(string $yml): array
{
    if (preg_match('/^sources:[^\n]*\n((?:[ \t]+[^\n]+\n)*)/m', $yml, $m) !== 1) {
        return [];
    }
    preg_match_all('/^[ \t]+-[ \t]+(?:path:[ \t]*)?(\S+)/m', $m[1], $mm);

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
        '../../cpp-src/core',
        '../../cpp-src/skia',
        '../../cpp-src/win32',
        '../../cpp-src/x11',
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
    foreach (['apps/dev', 'src/build', 'cpp-src', 'apps/hello', 'build', 'myapp', 'host-app/src'] as $dir) {
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
    $write("{$root}/myapp/entry.php", '<?php');
    $write("{$root}/myapp/widget.php", '<?php');
    $write("{$root}/host-app/composer.json", '{}');
    $write("{$root}/host-app/entry.php", '<?php');
    $write("{$root}/host-app/src/Widget.php", '<?php');

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

    $normalizedRoot = str_replace('\\', '/', $root);
    $customEntry = $normalizedRoot . '/myapp/entry.php';
    $checks['--entry 默认按 Drift 根解析'] = resolveEntry($root, $normalizedRoot, 'myapp/entry.php') === $customEntry;
    $checks['--entry 绝对路径原样用'] = resolveEntry($root, $normalizedRoot, $customEntry) === $customEntry;
    $business = businessSnapshot($root, 'hello', $customEntry);
    touch("{$root}/myapp/widget.php", time() + 30);
    clearstatcache();
    $checks['自定义入口目录变化 ⇒ 触发重启'] = businessSnapshot($root, 'hello', $customEntry) !== $business;
    $checks['自定义入口不重编壳'] = shellFingerprint($root) === $shell;

    // composer 包场景：宿主项目根 != Drift 包根，入口与热更都必须跟着宿主走。
    $host = $normalizedRoot . '/host-app';
    $hostEntry = $host . '/entry.php';
    $checks['--project 缺省为 Drift 根'] = resolveProject($root, '') === $normalizedRoot;
    $checks['--project 绝对路径解析'] = resolveProject($root, $host) === str_replace('\\', '/', (string) realpath($host));
    $checks['--entry 相对宿主项目根解析'] = resolveEntry($root, $host, 'entry.php') === $hostEntry;
    $business = businessSnapshot($root, 'hello', $hostEntry, $host);
    touch("{$root}/host-app/src/Widget.php", time() + 40);
    clearstatcache();
    $checks['宿主项目 src 变化 ⇒ 触发重启'] = businessSnapshot($root, 'hello', $hostEntry, $host) !== $business;
    $checks['宿主项目变化不重编壳'] = shellFingerprint($root) === $shell;

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

    // 发版打包也写同一份指纹：同源、且随壳输入失效。
    $shell = shellFingerprint($root);
    $written = writeShellFingerprint($root);
    $checks['写指纹与壳指纹同源'] = $written === $shell
        && trim((string) file_get_contents("{$root}/_build/dev-shell.sha")) === $shell;
    $write("{$root}/cpp-src/drift.cc", 'int written;');
    $checks['写指纹后改 C++ 桥 ⇒ 指纹过期'] = shellFingerprint($root) !== $shell;

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
$prebuilt = in_array('--prebuilt', $rest, true);
$entryOption = '';
$projectOption = '';
foreach ($rest as $argument) {
    if (str_starts_with($argument, '--entry=')) {
        $entryOption = substr($argument, strlen('--entry='));
    } elseif (str_starts_with($argument, '--project=')) {
        $projectOption = substr($argument, strlen('--project='));
    }
}

$app = 'hello';
foreach ($rest as $argument) {
    if ($argument !== '' && $argument[0] !== '-') {
        $app = $argument;
        break;
    }
}
if (preg_match('/^[A-Za-z0-9_-]+$/', $app) !== 1) {
    fail("应用名不合法：{$app}");
}

$root = dirname(__DIR__);
if (in_array('--write-fingerprint', $rest, true)) {
    if (!is_file("{$root}/dev.exe")) {
        fail('--write-fingerprint 需要仓库根的 dev.exe（先跑 drift-build.php dev）');
    }
    out('已写壳指纹：' . writeShellFingerprint($root));
    exit(0);
}
$appDir = strtolower($app);
// 自定义入口自带组合根；只有走 Drift 默认入口时才要求 apps/<app>/... 就位。
if ($entryOption === '' && !is_file("{$root}/apps/{$appDir}/bin/win32/Bootstrap.php")) {
    fail("找不到组合根：apps/{$appDir}/bin/win32/Bootstrap.php");
}

// 两种壳来源，本地产物优先（它刚编出来，和当前源码同版本）：
//   - 仓库根 `dev.exe`         ← `drift-build.php dev` 编的
//   - `_build/dev-shell/`      ← 发布包解压的，自带 phpx.dll / php8ts.dll / ext/
$packDir = "{$root}/_build/dev-shell";
$usingPack = !is_file("{$root}/dev.exe");
if ($usingPack) {
    $exe = "{$packDir}/dev.exe";
    $shaFile = "{$packDir}/dev-shell.sha";
} else {
    $exe = "{$root}/dev.exe";
    $shaFile = "{$root}/_build/dev-shell.sha";
}
$hash = shellFingerprint($root);
$previous = is_file($shaFile) ? trim((string) file_get_contents($shaFile)) : '';
$project = resolveProject($root, $projectOption);
$entry = resolveEntry($root, $project, $entryOption);

$iniDir = runtimeIni($root, $project, dirname($exe));
$env = getenv();
if (!is_array($env)) {
    $env = [];
}
// 壳旁边的运行时优先：发布包自带 phpx.dll / php8ts.dll，本地开发机则靠 PHP
// 发行版目录（TypePHP 发行版里才有 phpx.dll）。两者都在时包目录在前。
$env['PATH'] = dirname($exe) . ';' . dirname(PHP_BINARY) . ';' . (string) ($env['PATH'] ?? '');
$env['PHPRC'] = $iniDir;
$env['DRIFT_DEV_ENTRY'] = $entry;
$env['DRIFT_DEV_APP'] = $app;

// ★ 只报环境、不启动 —— 起不来的第一件事就是看这三样指向哪里。
//   必须在编译分支**之前**：它只要路径与 ini（`runtimeIni()` 不依赖编译结果），
//   排在编译之后的话，壳指纹一变，这条诊断就要先等三分钟编译。
if (in_array('--print-runtime', $rest, true)) {
    out("壳      : {$exe}");
    out('自带运行时: ' . (is_file(dirname($exe) . '/php8ts.dll') ? '是（包内）' : '否（用 PATH 里的 PHP 目录）'));
    out("ini     : {$iniDir}/php.ini");
    out("入口    : {$entry}");
    out('--- php.ini ---');
    fwrite(STDOUT, (string) file_get_contents("{$iniDir}/php.ini"));
    exit(0);
}

if ($prebuilt) {
    if (!is_file($exe)) {
        fail('--prebuilt 需要可用的壳：仓库根的 dev.exe，或把发布包解压到 _build/dev-shell/');
    }
    if ($rebuild) {
        // 静默忽略会让人以为"重编过了"
        out('! --prebuilt 优先，--rebuild 被忽略');
    }
    out('使用现成 dev.exe，跳过壳指纹检查');
} elseif ($usingPack) {
    // 包里的壳是流水线编出来的，这台机器多半没有工具链 ⇒ 不假装能重编，
    // 也不静默按错位版本跑（壳与源码不一致的症状是些莫名其妙的 require 失败）。
    if ($previous !== $hash) {
        fail(
            '壳指纹与当前源码不一致 —— 发布包与源码不是同一版本。'
            . 'checkout 与包同 tag 的源码，或加 --prebuilt 显式接受错位，'
            . '或在有 MSVC / tpc / PHPX 的机器上跑 tools/drift-build.php dev 重编'
        );
    }
    out('壳未变 ⇒ 跳过编译');
} elseif ($rebuild || !is_file($exe) || $previous !== $hash) {
    out('壳输入有变或无产物 ⇒ 编译 dev.exe（只此一次，之后改 PHP 不再编）');
    buildShell($root);
    writeShellFingerprint($root);
} else {
    out('壳未变 ⇒ 跳过编译');
}

$process = null;
$pipes = [];
$devPid = null;
register_shutdown_function(static function () use (&$process, &$pipes, &$devPid, $hardRestart): void {
    if ($process !== null) {
        stop($process, $pipes, !$hardRestart, $devPid);
    }
});

$start = static function () use (&$process, &$pipes, &$devPid, $exe, $root, $env): void {
    [$process, $pipes, $devPid] = spawn($exe, $root, $env);
    out('启动 dev.exe（PID ' . ($devPid ?? '未知') . '）');
};
$stopProcess = static function () use (&$process, &$pipes, &$devPid, $hardRestart): void {
    if ($process === null) {
        return;
    }
    if (!stop($process, $pipes, !$hardRestart, $devPid)) {
        out('优雅关闭未生效 ⇒ 已强杀，本次会话状态未写回');
    }
    $process = null;
    $pipes = [];
    $devPid = null;
};

$start();
out("应用 {$app} ｜ 监听 src/、apps/{$appDir}/、apps/dev/dev-entry.php（Ctrl+C 退出）");

$last = businessSnapshot($root, $appDir, $entry, $project);
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

    $current = businessSnapshot($root, $appDir, $entry, $project);
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
