#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-async` —— 异步任务时间线的专用出口。
 *
 * 记录的是三个 TaskHost 后端真实知道的状态跃迁：提交、派发、结算、取消。
 * `AsyncScope::sleep()` 与裸 Future 的等待点不是 job，不在这里列队 ——
 * 把"等"与"被后端调度"混成一张表，队列深度就解释不通。
 *
 * 默认不采集：异步 tap 在热路径上，必须 `--async` 显式开。导出时它是独立域，
 * 不进默认证据包；读取全部走查询面，`--remote` 与本地同一条路。
 *
 * 用法：
 *   php devtools/bin/debug-async.php --page=async-table --rows=3 --resolve --frames=2 --async
 *   php devtools/bin/debug-async.php --page=async-table --rows=3 --resolve --frames=2 --async --job=1
 *   php devtools/bin/debug-async.php --page=async-table --rows=3 --resolve --frames=2 --async --limit=0 --json
 *   php devtools/bin/debug-async.php ... --remote
 *   php devtools/bin/debug-async.php --page=async-table --rows=3 --resolve --frames=2 --async --out=case.evidence
 *   php devtools/bin/debug-async.php --from=case.evidence --job=1
 *
 * 参数：
 *   --job=N          按 jobId 看一条任务（不给则列时间线）
 *   --limit=N        时间线条数（默认 50，0=不截断）
 *   --async          采集 TaskHost 任务状态跃迁（默认关）
 *   --page=<路由id>  页面（与位置参数同义）
 *   --frames=N       驱动几帧（默认 1）
 *   --rows=N         取数替身参数（异步页需要）
 *   --click=x,y / --key= / --text= / --wheel= / --resolve / --hold=N  驱动词表
 *   --at=N           只重放到第 N 帧
 *   --remote         用宿主进程跑（验证跨进程读到同一份证据）
 *   --out=<文件>     把这次会话导出成证据包（NDJSON，首行 manifest）
 *   --from=<文件>    不跑会话，直接读证据包复算（与驱动参数互斥）
 *   --json           结构化输出
 */

require __DIR__ . '/../autoload.php';

use Drift\DevTools\Session\SessionQueries;
use Drift\DevTools\Cli\Commands;
use Drift\DevTools\Host\Source;
use Drift\DevTools\View\Async;

$args = Commands::parseOrExit('async', $argv);
$from = $args->str('from');
$out = $args->str('out');
$at = $args->at();
$remote = $args->bool('remote');
$json = $args->bool('json');
$job = $args->has('job') ? $args->int('job') : null;
$limit = $args->int('limit');
$label = $args->label();
$attach = $args->bool('attach');
$sessionId = $args->str('session');
if ($label === null && $from === null && !$attach) {
    \fwrite(\STDERR, "用法：debug-async <AppClass|路由id> [--page=<路由id>] [--frames=N] --async [--json]\n");
    \fwrite(\STDERR, "      debug-async --from=<证据包> [--job=N] [--json]\n");
    \fwrite(\STDERR, "      debug-async --job=N   # 当前目录只有一个活会话时自动接上\n");
    exit(2);
}
try {
    $attach = Source::autoAttach($attach, $from, $label, $sessionId);
} catch (\RuntimeException $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
}

/** @var SessionQueries|null $session */
$session = null;
$source = null;
try {
    $drive = null;
    if ($from === null && !$attach) {
        $drive = $args->script();
    }

    $source = Source::resolve(
        from: $from,
        attach: $attach,
        session: $sessionId,
        remote: $remote,
        drive: $drive,
        at: $at,
        out: $out,
        recordOptions: $args->recordOptions(),
    );
    $label = $source->label();
    $session = $source->queries();

    // 导出放在查询之前、Replay 之后：帧已跑完，异步状态都齐了。
    $source->export();

    $answer = $session->query('async', $job === null
        ? ['limit' => $limit]
        : ['job' => $job]);
    if ($json) {
        \fwrite(\STDOUT, (string) \json_encode(
            ['label' => $label] + $answer,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
        ) . "\n");
    } else {
        $lines = $job === null ? Async::timeline($answer) : Async::task($answer);
        foreach ($lines as $line) {
            \fwrite(\STDOUT, $line . "\n");
        }
    }
} catch (\Throwable $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
} finally {
    $source?->close();
}
