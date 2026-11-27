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

use Drift\DevTools\Cli\SessionScope;
use Drift\DevTools\View\Async;

SessionScope::open('async', $argv)->run(static function (SessionScope $scope): int {
    $args = $scope->args;
    $job = $args->has('job') ? $args->int('job') : null;
    $answer = $scope->query('async', $job === null
        ? ['limit' => $args->int('limit')]
        : ['job' => $job]);
    $scope->emit($answer, static fn(array $a): array => $job === null ? Async::timeline($a) : Async::task($a));

    return 0;
});
