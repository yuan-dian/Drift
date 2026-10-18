#!/usr/bin/env php
<?php
/**
 * probe-async —— 真机验证「**没有输入，界面也会自己变**」（§17.4.9 帧时钟）。
 *
 * ```bash
 * # 先起 mock（另开终端 / 后台任务）：
 * php -S 127.0.0.1:8099 tools/app-workers/users-mock.php
 * # 再跑：
 * php tools/build-grab.php      # 首次或改了 probe-grab.cc
 * php tools/probe-async.php users
 * ```
 *
 * 被验证的缺陷：`drift_win_pump()` 阻塞在 `GetMessageW`，而 `TimerHost` 一个调用者都没有
 * ⇒ `drain()` 只在"用户动了鼠标"时跑 ⇒ 后台取数的结果只能等下一条消息捎带，**且不报错**。
 * 装上 `FrameClock` 之后，窗口应当**在零输入下**自己从 Loading 切到 Ready。
 *
 * 判据（八条，缺一不可）：
 *   ① 窗口是 900×640（DPI V2 下客户区的物理像素）；
 *   ② 采样**开始时**列表区没墨（还在 Loading）；
 *   ③ 采样**结束时**列表区有墨（数据真的回来了）；
 *   ④ ③ > ②（是**后到**的，不是一开始就有）；
 *   ⑤ **同时有 2 个 `users.exe` 进程** —— 主 + `--drift-worker`（AOT 靠 argv 分流的直接证据）；
 *   ⑥ 关窗后残留 0（stdin 关闭 ⇒ worker 正常收工，不留孤儿）；
 *   ⑦ Loading 期**画面在动**（`Spinner` 相位随帧推进 —— 帧间变化像素数 > 0）；
 *   ⑧ Ready 后**静止**（帧间变化精确 0 ⇒ 帧时钟真的停了，不空转）。
 *
 * ★★ 只量**列表区**（y 220–640），不量整窗：整窗墨量对"取数失败"也成立
 *   （错误态 8.6% vs Ready 15.1%），阈值 8% 于是形同虚设 —— 这是 2026-10-18 踩到的假绿。
 */

declare(strict_types=1);

const LIST_Y0 = 220;          // 列表区起点（逻辑像素）：工具条/搜索框在上，15 行列表在下
const LIST_Y1 = 640;
const LIST_MIN_PCT = 4.0;     // 列表区"有墨"的下限（Ready 实测 9.3%，错误态 ≈0%）
const SAMPLES = 30;
const INTERVAL_MS = 100;
/** ★ 首帧落地前 `BitBlt` 抓到的是**屏幕残留**（实测 7.2%，下一帧 0.0%）⇒ 先等一下。
 *  见 `probe-grab.cc` 的 bug ②。200ms 远小于 mock 的 1.2s，不会盖住 Loading 期。 */
const SETTLE_MS = 200;

function step(string $message): void
{
    fwrite(STDOUT, "\n── {$message}\n");
}

function ok(string $message): void
{
    fwrite(STDOUT, "  ✓ {$message}\n");
}

function bad(string $message): void
{
    fwrite(STDOUT, "  ✗ {$message}\n");
}

/**
 * 跑一条命令，收齐 stdout/stderr。
 *
 * @param list<string> $command
 * @return array{0:int|null,1:string,2:string}
 */
function runCommand(array $command, ?string $cwd = null): array
{
    $process = proc_open(
        $command,
        [0 => ['file', 'nul', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd
    );
    if (!is_resource($process)) {
        return [null, '', 'proc_open failed'];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    while (true) {
        $status = proc_get_status($process);
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        if (!$status['running']) {
            break;
        }
        usleep(20000);
    }
    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [$exitCode, $stdout, $stderr];
}

/** mock 后端活着吗（`fsockopen` 而不是 `file_get_contents`：不依赖 `allow_url_fopen`） */
function mockAlive(string $host, int $port, string $path, float $timeout = 2.0): bool
{
    $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
    if (!is_resource($socket)) {
        return false;
    }
    stream_set_timeout($socket, (int) ceil($timeout));
    fwrite($socket, "GET {$path} HTTP/1.0\r\nHost: {$host}:{$port}\r\nConnection: close\r\n\r\n");
    $status = (string) fgets($socket, 128);
    fclose($socket);

    return str_contains($status, ' 200');
}

/**
 * `key=value` 输出 ⇒ 关联数组；`s0..sN` 收进 `samples`，`c1..cN` 收进 `motion`。
 *
 * @return array{meta: array<string,string>, samples: list<float>, motion: list<int>}
 */
function parseGrab(string $stdout): array
{
    $meta = [];
    $samples = [];
    $motion = [];
    foreach (preg_split('/[\r\n]+/', $stdout) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        if (preg_match('/^s\d+$/', $key) === 1) {
            $samples[] = (float) $value;
        } elseif (preg_match('/^c\d+$/', $key) === 1) {
            $motion[] = (int) $value;
        } else {
            $meta[$key] = $value;
        }
    }

    return ['meta' => $meta, 'samples' => $samples, 'motion' => $motion];
}

// ──────────────────────────────────────────────────────────── 前置 ──

$root = dirname(__DIR__);
$app = 'users';
foreach (array_slice($argv, 1) as $argument) {
    if ($argument !== '' && $argument[0] !== '-') {
        $app = $argument;
        break;
    }
}

$exe = "{$root}/{$app}.exe";
$image = "{$app}.exe";
$grab = "{$root}/tools/probe-grab.exe";
$mockHost = '127.0.0.1';
$mockPort = 8099;

step('前置');

if (!is_file($grab)) {
    bad("找不到 {$grab} —— 先跑 `php tools/build-grab.php`");
    exit(2);
}
ok("探针 {$grab}");

if (!is_file($exe)) {
    bad("找不到 {$exe} —— 先跑 `php tools/drift-build.php {$app}`");
    exit(2);
}
ok("应用 {$exe}");

if (!mockAlive($mockHost, $mockPort, '/healthz')) {
    bad("mock 后端不可达（http://{$mockHost}:{$mockPort}/healthz）");
    fwrite(STDERR, "  先跑：php -S {$mockHost}:{$mockPort} tools/app-workers/users-mock.php\n");
    exit(2);
}
ok("mock 后端 http://{$mockHost}:{$mockPort}");

// ★★ 临时 ini **必须以 exe 同目录那份为底**（2026-10-18 的假绿根因）：
//   `PHPRC` 会**覆盖** `drift-build` 写在仓库根的那份 `php.ini`，而那份里才有
//   `extension=mbstring/openssl/curl`。只写 `opcache.enable=0` 的话，**worker 子进程**
//   （同一 exe + `--drift-worker`）就没有 curl ⇒ `curl_init()` 未定义 ⇒ worker Fatal
//   ⇒ 界面停在"加载失败"，而探针还在报 OK。
$runtimeIni = "{$root}/php.ini";
$scratch = sys_get_temp_dir() . '/drift-probe-' . getmypid();
if (!is_dir($scratch) && !mkdir($scratch, 0777, true) && !is_dir($scratch)) {
    bad("建不出临时目录 {$scratch}");
    exit(2);
}
$iniLines = [];
if (is_file($runtimeIni)) {
    foreach (file($runtimeIni, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $iniLines[] = $line;
    }
    ok("运行时 ini 以 {$runtimeIni} 为底（" . count($iniLines) . ' 行）');
} else {
    bad("没有 {$runtimeIni}（drift-build 写的运行时 ini）—— worker 可能缺扩展");
}
$iniLines[] = 'opcache.enable=0';
$tempIni = "{$scratch}/php.ini";
file_put_contents($tempIni, implode("\n", $iniLines) . "\n");

$environment = getenv();
if (!is_array($environment)) {
    $environment = [];
}
$environment['PHPRC'] = $tempIni;

// ──────────────────────────────────────────────────────────── 起应用 ──

step('启动应用（全程不发任何输入）');
$log = "{$scratch}/app.log";
$appProcess = proc_open(
    [$exe],
    [0 => ['file', 'nul', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
    $appPipes,
    $root,
    $environment
);
if (!is_resource($appProcess)) {
    bad('启动应用失败');
    exit(2);
}
$appStatus = proc_get_status($appProcess);
$appPid = (int) ($appStatus['pid'] ?? 0);
if ($appPid <= 0) {
    bad('拿不到应用 pid');
    proc_terminate($appProcess);
    exit(2);
}
ok("pid={$appPid}，日志 {$log}");

// ──────────────────────────────────────────────────────── 抓 + 采样 ──

step('抓客户区（等窗口 ≤8s，settle ' . SETTLE_MS . 'ms，然后 ' . SAMPLES . ' 次 × ' . INTERVAL_MS . 'ms 采样）');
[$grabCode, $grabOut, $grabErr] = runCommand([
    $grab,
    "--pid={$appPid}",
    "--image={$image}",
    '--wait=8000',
    '--settle=' . SETTLE_MS,
    '--samples=' . SAMPLES,
    '--interval=' . INTERVAL_MS,
    '--y0=' . LIST_Y0,
    '--y1=' . LIST_Y1,
    '--motion',
    '--close',
], $root);

if ($grabCode !== 0) {
    bad("probe-grab 失败（exit={$grabCode}）");
    if (trim($grabErr) !== '') {
        fwrite(STDERR, "  {$grabErr}");
    }
    if (trim($grabOut) !== '') {
        fwrite(STDERR, "  {$grabOut}");
    }
    if (is_file($log)) {
        $tail = array_slice(file($log, FILE_IGNORE_NEW_LINES) ?: [], -12);
        fwrite(STDERR, "  ── 应用日志尾部 ──\n");
        foreach ($tail as $line) {
            fwrite(STDERR, "  {$line}\n");
        }
    }
    proc_terminate($appProcess);
    exit(2);
}

$parsed = parseGrab($grabOut);
$meta = $parsed['meta'];
$series = $parsed['samples'];
$motion = $parsed['motion'];

$client = $meta['client'] ?? '?';
$inkAll = (float) ($meta['ink_all_pct'] ?? 0.0);
$procs = (int) ($meta['procs'] ?? 0);

fwrite(STDOUT, "  client = {$client}（整窗首帧墨量 {$inkAll}%）\n");
fwrite(STDOUT, '  列表区采样：' . implode(' ', array_map(
    static fn (float $v): string => number_format($v, 1),
    $series
)) . "\n");
// ★ 帧间变化像素数：Loading 期 `Spinner` 在转 ⇒ 每格都有变化；Ready 后静止 ⇒ 精确 0。
fwrite(STDOUT, '  帧间变化（像素数）：' . implode(' ', $motion) . "\n");

$early = $series === [] ? 0.0 : $series[0];
$late = $series === [] ? 0.0 : $series[count($series) - 1];
$peak = $series === [] ? 0.0 : max($series);
$crossedAt = null;
foreach ($series as $index => $value) {
    if ($value >= LIST_MIN_PCT) {
        $crossedAt = $index * INTERVAL_MS;
        break;
    }
}
fwrite(STDOUT, sprintf(
    "  首=%.1f%% 末=%.1f%% 峰=%.1f%% ｜ 越过 %.1f%% 的时刻 = %s\n",
    $early,
    $late,
    $peak,
    LIST_MIN_PCT,
    $crossedAt === null ? '从未' : "{$crossedAt}ms（采样起点后）"
));

// ──────────────────────────────────────────────────────────── 关窗 ──

step('关窗（WM_CLOSE 已由探针发出）');
usleep(1_500_000);
if (proc_get_status($appProcess)['running']) {
    proc_terminate($appProcess);
}
proc_close($appProcess);

[$countCode, $countOut] = runCommand([$grab, '--count-only', "--image={$image}"], $root);
$residual = $countCode === 0 ? (int) trim(str_replace('procs=', '', trim($countOut))) : -1;

// ──────────────────────────────────────────────────────────── 判据 ──

step('判据');
// ⑦ Loading 期前 8 格必须几乎都在动（`Spinner` 相位每帧推进）。
//   取 7/8 而不是 8/8：第一格跨着"首帧落地"，允许一次例外。
$loadingMotion = array_slice($motion, 0, 8);
$movingCount = count(array_filter($loadingMotion, static fn (int $c): bool => $c > 0));
// ⑧ 收尾必须静止：Ready 后没有任何东西该继续画 ⇒ 帧间变化精确 0（不空转）。
$restChanged = $motion === [] ? -1 : $motion[count($motion) - 1];

$results = [
    '① 客户区 900×640' => $client === '900x640',
    '② 采样开始时还在 Loading' => $early < LIST_MIN_PCT,
    '③ 采样结束时列表已画出来' => $late >= LIST_MIN_PCT,
    '④ 是后到的（末 > 首）' => $late > $early,
    '⑤ 进程数 ≥ 2（主 + worker）' => $procs >= 2,
    '⑥ 关窗后无残留' => $residual === 0,
    '⑦ Loading 期 Spinner 真的在转' => $movingCount >= 7,
    '⑧ Ready 后静止（不空转）' => $restChanged === 0,
];
foreach ($results as $label => $passed) {
    $passed ? ok($label) : bad($label);
}
fwrite(STDOUT, sprintf(
    "  （进程数 = %d，残留 = %d，Loading 期在动 %d/8 格，收尾变化 %d 像素）\n",
    $procs,
    $residual,
    $movingCount,
    $restChanged
));

// 失败时给根因提示，而不是只丢一句 FAIL
if (in_array(false, $results, true)) {
    fwrite(STDOUT, "\n── 可能的根因\n");
    if ($procs < 2) {
        fwrite(STDOUT, "  · 没有 worker 子进程：`--drift-worker` 分流没生效，或 ProcessTaskHost 没装上\n");
    }
    if ($early >= LIST_MIN_PCT) {
        fwrite(STDOUT, "  · 采样开始时列表就满了：mock 太快（应 1.2s 慢响应），" .
            "或者数据是启动时同步取的 —— 这条判据失去意义\n");
    }
    if ($late < LIST_MIN_PCT) {
        fwrite(STDOUT, "  · 列表区始终没墨：① 帧时钟没接（`FrameClock::pace/tick`）" .
            " ② worker Fatal（看 app.log）③ 取数失败（错误态在列表区几乎无墨）\n");
    }
    if ($residual > 0) {
        fwrite(STDOUT, "  · 有残留进程：worker 没收到 stdin EOF（关窗没走 WM_CLOSE？）\n");
    }
    if ($movingCount < 7) {
        fwrite(STDOUT, "  · Loading 期画面不动：① `Application::tickAnimations()` 没在 settle 之前调" .
            " ② `SpinnerState::tickFrame()` 没覆写 / 没返回 true ③ 帧时钟根本没跑\n");
    }
    if ($restChanged > 0) {
        fwrite(STDOUT, "  · Ready 后还在变：帧时钟没停（`wantsFrame()` 恒真 ⇒ 空闲 CPU 不归零），" .
            "或有别的动画没收工\n");
    }
}

$failed = in_array(false, $results, true);
fwrite(STDOUT, "\n" . ($failed ? 'FAIL' : 'OK') . "\n");
exit($failed ? 1 : 0);
