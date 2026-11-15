<?php
declare(strict_types=1);

/**
 * 真机接缝：WM_CLOSE 让 WindowSet::run() 的 finally 写 users.window；/F 强杀不写。
 * php tools/probe-hotreload-p15.php —— 只读配置目录，不要前台窗口。
 * 环境不满足 ⇒ SKIP（码 2），不写成通过；不进 VerifyPlan / CI。
 */
$root = dirname(__DIR__);
$exe = "{$root}/dev.exe";
$ini = "{$root}/_build/dev-ini";
$state = getenv('APPDATA') . '/Drift/users.window';

if (PHP_OS_FAMILY !== 'Windows' || !is_file($exe) || !is_dir(dirname($state))) {
    fwrite(STDERR, "SKIP: 需要 Windows + dev.exe + 用户配置目录\n");
    exit(2);
}
if (!is_dir($ini) && !mkdir($ini, 0777, true) && !is_dir($ini)) {
    fwrite(STDERR, "SKIP: 建不了 dev-ini\n");
    exit(2);
}
$base = is_file("{$root}/php.ini") ? (string) file_get_contents("{$root}/php.ini") : '';
file_put_contents("{$ini}/php.ini", rtrim($base) . "\nopcache.enable=0\n");

/** 清掉历史 dev.exe，避免上一轮残留进程替我们写状态文件。 */
function killAll(): void
{
    exec('taskkill /IM dev.exe /F 2>&1');
    usleep(300000);
}

/** @return array{0: resource|false, 1: array<int, resource>} */
function startProc(string $exe, string $root, string $ini): array
{
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    $env['PATH'] = dirname(PHP_BINARY) . ';' . (string) ($env['PATH'] ?? '');
    $env['PHPRC'] = $ini;
    $env['DRIFT_DEV_ENTRY'] = "{$root}/apps/dev/dev-entry.php";
    $env['DRIFT_DEV_APP'] = 'users';

    $pipes = [];
    $proc = proc_open(
        [$exe],
        [0 => ['file', 'nul', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        $env,
        ['bypass_shell' => true],
    );

    return [$proc, $pipes];
}

/** @return array{0: bool, 1: string} 是否写出几何 + 诊断 */
function trial(string $exe, string $root, string $ini, string $state, bool $graceful): array
{
    killAll();
    @unlink($state);
    [$proc, $pipes] = startProc($exe, $root, $ini);
    if (!is_resource($proc)) {
        return [false, 'proc_open 失败'];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    usleep(2500000);

    $lines = [];
    exec($graceful ? 'taskkill /IM dev.exe 2>&1' : 'taskkill /IM dev.exe /F 2>&1', $lines, $killCode);

    $deadline = microtime(true) + 4.0;
    while (microtime(true) < $deadline && proc_get_status($proc)['running']) {
        usleep(100000);
    }
    $alive = proc_get_status($proc)['running'];
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) {
        if (is_resource($pipe)) {
            fclose($pipe);
        }
    }
    proc_close($proc);

    // 读内容而非只看存在：空文件或别的应用写的文件都不算数。
    $body = is_file($state) ? (string) file_get_contents($state) : '';
    $written = str_contains($body, 'main.x=');
    $diag = ($graceful ? '优雅' : '强杀')
        . " kill={$killCode} alive=" . ($alive ? 'yes' : 'no')
        . ' bytes=' . strlen($body)
        . ' | ' . trim(substr(preg_replace('/\s+/', ' ', $out . ' ' . implode(' ', $lines)), 0, 200));

    return [$written, $diag];
}

[$elegant, $diagA] = trial($exe, $root, $ini, $state, true);
[$forced, $diagB] = trial($exe, $root, $ini, $state, false);
killAll();

fwrite(STDOUT, '① 优雅关闭 ⇒ finally 写出 users.window: ' . ($elegant ? 'PASS' : 'FAIL') . " | {$diagA}\n");
fwrite(STDOUT, '② /F 强杀 ⇒ 不写盘（对照组）: ' . (!$forced ? 'PASS' : 'FAIL') . " | {$diagB}\n");
$fail = ($elegant ? 0 : 1) + ($forced ? 1 : 0);
fwrite(STDOUT, "--- probe-hotreload-p15: {$fail} FAIL / 2 条判据 ---\n");
exit($fail === 0 ? 0 : 1);
