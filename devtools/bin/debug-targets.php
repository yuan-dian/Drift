#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-targets` —— 把当前目录里所有活会话并列成一份视图。
 *
 * ## 它是发现层的消费者，不是第四个会话来源
 *
 * 每个窗口自己登记、自己断连；这里只按登记 id 逐个连上、逐个取事实。连接
 * 走 {@see Source::attachOne()} 那一个入口，与 `--attach` 共用同一套点名与诊断，
 * 出口里不碰 socket。
 *
 * ## 并列，不合并
 *
 * 两个窗口的节点号与帧号各自从 1 开始，按它们 join 会造出假因果（A59）。
 * 所以每行是一份独立的 `Brief::project()`：发现仍由规则注册表产生，这里只
 * 把结论摆在一起，深挖仍走既有出口的 `--session=<id>`。
 *
 * ## 一个连不上，不代表整组不能看
 *
 * 强杀留下的僵尸、刚退出还没注销的宿主都会出现在发现文件里。逐会话 `try/catch`，
 * 失败的那行写 `unreachable` 与诊断理由，其他会话照常呈现；连接一律在 `finally`
 * 里断开，不留"布防还开着"那种僵尸状态。
 *
 * 用法：
 *   php devtools/bin/debug-targets.php
 *   php devtools/bin/debug-targets.php --json
 *   php devtools/bin/debug-targets.php --budget=10 --width=120
 *
 * 参数：
 *   --json            结构化输出（每会话一份标量投影 + 汇总）
 *   --budget=N        最多显示几行会话（默认 40；不可达的优先保留）
 *   --width=N         文本行宽（默认 100）
 *   --slow=N          慢帧阈值（默认 16.7，与 brief 同源）
 *   --fanout=N        扇出阈值（默认 100）
 *   --repeat=N        重复重建阈值（默认 50）
 *
 * @internal 手工工具；不进 V2 判据清单
 */

require __DIR__ . '/../autoload.php';

use Drift\Debug\Session\Thresholds;
use Drift\DevTools\Cli\Commands;
use Drift\DevTools\Host\Discovery;
use Drift\DevTools\Host\Source;
use Drift\DevTools\View\Brief;
use Drift\DevTools\View\Facts;
use Drift\DevTools\View\Targets;
use Drift\DevTools\View\TargetsText;

if (\in_array('--help', \array_slice($argv, 1), true)) {
    \fwrite(\STDOUT, Commands::helpFor('targets'));

    exit(0);
}

$json = false;
$budget = 40;
$width = 100;
$slow = null;
$fanout = null;
$repeat = null;

foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $json = true;
    } elseif (\str_starts_with($arg, '--budget=')) {
        $budget = \max(1, (int) \substr($arg, 9));
    } elseif (\str_starts_with($arg, '--width=')) {
        $width = \max(20, (int) \substr($arg, 8));
    } elseif (\str_starts_with($arg, '--slow=')) {
        $slow = (float) \substr($arg, 7);
    } elseif (\str_starts_with($arg, '--fanout=')) {
        $fanout = (int) \substr($arg, 9);
    } elseif (\str_starts_with($arg, '--repeat=')) {
        $repeat = (int) \substr($arg, 9);
    } else {
        \fwrite(\STDERR, "未知参数：{$arg}（--help 看用法）\n");
        exit(2);
    }
}

// 与 brief 用同一份阈值定义：表头写什么，规则就判什么。
$thresholds = Thresholds::default()->with([
    'slowMs' => $slow,
    'fanoutNodes' => $fanout,
    'repeatPercent' => $repeat,
]);

$discovery = Discovery::inDirectory((string) \getcwd());
try {
    $entries = $discovery->live();
} catch (\Throwable $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
}

if ($entries === []) {
    \fwrite(\STDERR, "当前目录没有运行中的 drift 会话（{$discovery->path()}）。\n");
    exit(2);
}

$rows = [];
foreach ($entries as $entry) {
    $row = [
        'id' => $entry['id'],
        'target' => $entry['target'],
        'pid' => $entry['pid'],
        'port' => $entry['port'],
        'startedAt' => $entry['startedAt'],
        'state' => 'unreachable',
        'error' => null,
        'facts' => null,
        'brief' => null,
    ];

    $source = null;
    try {
        $source = Source::attachOne($entry['id'], $discovery);
        $facts = Facts::gather($source->queries(), $source->label());
        $row['state'] = 'ok';
        $row['facts'] = $facts;
        // 每会话一份完整的规则投影；这里不再判任何一条发现（A59）。
        $row['brief'] = Brief::project($facts, $thresholds);
    } catch (\Throwable $e) {
        $row['error'] = $e->getMessage();
    } finally {
        $source?->close();
    }

    $rows[] = $row;
}

$projection = Targets::project($rows);

if ($json) {
    \fwrite(\STDOUT, (string) \json_encode(
        $projection,
        \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
    ) . "\n");
} else {
    foreach (TargetsText::render($projection, $budget, $width)['lines'] as $line) {
        \fwrite(\STDOUT, $line . "\n");
    }
}

exit(0);
