#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-loop` —— 重开 N 次：证明"关掉这一页再打开"是等价的。
 *
 * 它不产出"某一轮的证据"，它产出"多轮的对比"。这就是它为什么是一个独立出口，
 * 而不是给 `brief` 加 `--loop=N`：报告给第几轮没有答案（给第一轮则后几轮白跑，
 * 给最后一轮则前几轮成了 warmup）。
 *
 * 每轮都**重建应用**（`Runner::open`），不复用会话——状态在树里，不在脚本里，
 * 复用会让第二轮从第一轮的终点起步，而第 2、3 轮仍然彼此相同（见 B5 §2.2）。
 *
 * 用法：
 *   php tools/debug-loop.php --page=table --runs=3
 *   php tools/debug-loop.php --page=async-table --rows=6 --resolve --frames=2 --runs=3
 *   php tools/debug-loop.php --page=table --runs=3 --json
 *   php tools/debug-loop.php --page=table --runs=3 --assert
 *
 * 参数：
 *   --page=<路由id>   页面（与面板同义）；也可直接给应用类名
 *   --runs=N          重开几次（默认 3；1 只报不判）
 *   --frames=N        每轮驱动几帧（默认 1）
 *   --rows=N          取数替身参数（异步页需要）
 *   --click=x,y       点一下（可重复）
 *   --key= / --text= / --wheel= / --resolve / --hold=N  与面板同一套驱动词表
 *   --at=N            每轮只重放到第 N 帧
 *   --out=<文件>      把**第 1 轮**导出成证据包（后面几轮只用于验证）
 *   --view-width=N    视口宽度（默认 800）
 *   --height=N        视口高度（默认 600）
 *   --json            输出结构化结果
 *   --assert          不一致时以状态码 3 退出（给 CI / verify 用）
 *
 * @internal 未进 VerifyPlan 的日常闸门：跑一次 3 轮约 1 秒，进 `--full` 才划算
 */

require __DIR__ . '/autoload.php';

use Drift\App\DebugSession;
use Drift\Debug\Session\Evidence;
use Drift\Debug\Session\Session;
use Drift\Dev\Debug\Cli\Commands;
use Drift\Dev\Debug\Reopen;
use Drift\Dev\Debug\Runner;
use Drift\Dev\Debug\View\LoopReport;

$args = Commands::parseOrExit('loop', $argv);
$out = $args->str('out');
$at = $args->at();
$json = $args->bool('json');
$assert = $args->bool('assert');
$runs = $args->int('runs');
$width = $args->float('view-width');
$height = $args->float('height');
$meta = $args->meta();
$label = $args->label();

// 三条都指向"另一个会话"，而 loop 要的恰恰是"若干个本进程的新会话"。
// 不静默忽略：忽略之后报告会看起来正常，只是测的不是他想测的东西。
$rejected = [];
if ($args->bool('remote')) {
    $rejected[] = '--remote';
}
if ($args->has('from')) {
    $rejected[] = '--from';
}
if ($args->bool('attach')) {
    $rejected[] = '--attach';
}
if ($rejected !== []) {
    \fwrite(\STDERR, 'loop 不支持 ' . \implode(' / ', $rejected) . "：它每轮都要在本进程里重建应用，"
        . "接别人的会话或读离线包都做不到这件事\n");
    exit(2);
}
if ($label === null) {
    \fwrite(\STDERR, "用法：debug-loop <AppClass|路由id> [--page=<路由id>] [--runs=N] [--json]\n");
    exit(2);
}
if ($assert && $runs < 2) {
    \fwrite(\STDERR, "--assert 至少要 --runs=2：一轮没有可比对象，断言不了任何事\n");
    exit(2);
}

try {
    $drive = $args->script();

    // 每轮一个新应用：这正是 loop 与被否决的"复用应用"方案的唯一区别，
    // 而它必须是这里的默认，不是选项——给用户开关等于给他一个拿到假结论的捷径。
    $open = static function (int $round) use ($label, $width, $height, $meta, $drive): array {
        $opened = Runner::open((string) $label, (float) $width, (float) $height, $meta, $drive->fork());

        return ['session' => $opened['session'], 'mountMs' => $opened['mountMs']];
    };

    $watch = $out === null ? null : static function (int $round, Session $session) use ($out): void {
        // 导出第 1 轮：它是唯一没有被任何前置轮影响的那次，也是判据的基准。
        if ($round === 1 && $session instanceof DebugSession) {
            Evidence::write($session->recorder(), $out);
        }
    };

    $report = Reopen::run($open, $drive, $runs, $at, $watch);

    if ($json) {
        // 只补 label：`runs` 是 `$report` 里的**逐轮记录**，不要再放一个"次数"同名字段，
        // 两个 `runs` 相加时后一个会被 `+` 静默吃掉（实测过：JSON 里 runs 变成整数）。
        \fwrite(\STDOUT, (string) \json_encode(
            ['label' => $label] + $report,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
        ) . "\n");
    } else {
        foreach (LoopReport::lines((string) $label, $report) as $line) {
            \fwrite(\STDOUT, $line . "\n");
        }
    }

    // 状态码 3 而不是 1：1 留给"跑不起来"，两者在 CI 日志里要能分开看。
    if ($assert && $report['consistent'] !== true) {
        exit(3);
    }
} catch (\Throwable $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
}
