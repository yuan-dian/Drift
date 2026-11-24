#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-props` —— 属性域出口：按节点看 Widget / State 配置。
 *
 * 为什么不给 --frame：属性读的是**当前活树**，给一个历史帧号却返回现在的值
 * 就是伪称对齐。查询面回显 `liveFrame` 明说这是哪一帧读到的。
 *
 * 只在 dev 形态可用：反射拿不到私有字段时查询会报 `reflection-unavailable`，
 * 不会给一份空字段让人以为"这个控件没有配置"。
 *
 * 用法：
 *   php tools/debug-props.php --page=table --frames=1 --click=400,300 --node=12
 *   php tools/debug-props.php ... --node=12 --maxDepth=2 --maxFields=40 --json
 *   php tools/debug-props.php ... --node=12 --remote
 *
 * 参数：
 *   --node=N          必填：节点 id（可由 debug-panel / debug-brief 取到）
 *   --maxDepth=N      对象/数组展开深度（默认 3）
 *   --maxFields=N     每个对象/数组列几项（默认 120）
 *   其余驱动参数同 debug-node / debug-network
 *   --remote          用宿主进程跑（验证跨进程读到同一份证据）
 *   --json            结构化输出
 *
 * @internal 视图规则刚落地，未进 VerifyPlan
 */

require __DIR__ . '/autoload.php';

use Drift\App\Replay;
use Drift\Debug\Protocol\RemoteSession;
use Drift\Dev\Debug\Cli\Commands;
use Drift\Dev\Debug\ProcessTransport;
use Drift\Dev\Debug\Runner;
use Drift\Dev\Debug\View\Props;

$args = Commands::parseOrExit('props', $argv);
$width = $args->float('view-width');
$height = $args->float('height');
$meta = $args->meta();
$node = $args->int('node');
$maxDepth = $args->int('maxDepth');
$maxFields = $args->int('maxFields');
$at = $args->at();
$remote = $args->bool('remote');
$json = $args->bool('json');
$label = $args->label();
if ($label === null || $node <= 0) {
    \fwrite(\STDERR, "用法：debug-props <AppClass|路由id> --node=N [--page=<路由id>] [--json] [--remote]\n");
    exit(2);
}

$drive = $args->script();

$session = null;
try {
    if ($remote) {
        $session = new RemoteSession(ProcessTransport::spawn([
            \PHP_BINARY,
            \dirname(__DIR__) . '/tools/debug-host.php',
        ]));
        $session->open($drive->fork());
    } else {
        $opened = Runner::open((string) $label, (float) $width, (float) $height, $meta, $drive->fork());
        $session = $opened['session'];
    }
    Replay::apply($session, $drive, [], $at, static function (int $id, float $ms): void {
    });

    $answer = $session->query('props', [
        'node' => $node,
        'maxDepth' => $maxDepth,
        'maxFields' => $maxFields,
    ]);
    if ($json) {
        \fwrite(\STDOUT, (string) \json_encode(
            ['label' => $label] + $answer,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
        ) . "\n");
    } else {
        foreach (Props::lines($answer) as $line) {
            \fwrite(\STDOUT, $line . "\n");
        }
    }
} catch (\Throwable $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
} finally {
    if ($session !== null) {
        $session->close();
    }
}
