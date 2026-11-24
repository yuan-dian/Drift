#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-node` —— 跨域对齐的出口：给一个节点和一个帧，并排看因果 / 几何 / 网络。
 *
 * 为什么是显式 node + frame 而不是"帮我找"：三个域的缺省帧语义不同，让命令
 * 替你选帧就会重演"看起来对齐、实际各取各的"。`--frame` 必填正是这条纪律的
 * 可执行形式——`align()` 查询也不接受缺省帧。
 *
 * 只读查询面（`align`），本地与 `--remote` 同一条路；投影层给一条可复算的
 * 发现，地址指回 `why(node, frame)`，不冒充 bug。
 *
 * 用法：
 *   php tools/debug-node.php --page=table --frames=1 --click=400,300 --node=1 --frame=2
 *   php tools/debug-node.php ... --node=1 --frame=2 --json
 *   php tools/debug-node.php ... --node=1 --frame=2 --remote
 *
 * 参数：
 *   --node=N / --frame=N  必填：节点 id 与显式帧号
 *   其余驱动参数同 debug-brief / debug-network
 *
 * @internal 未进 VerifyPlan：S4 视图规则还在定
 */

require __DIR__ . '/autoload.php';

use Drift\App\Replay;
use Drift\Debug\Protocol\RemoteSession;
use Drift\Dev\Debug\Plan;
use Drift\Dev\Debug\ProcessTransport;
use Drift\Dev\Debug\Runner;
use Drift\Dev\Debug\View\Align;

$target = null;
$page = null;
$frames = 1;
$width = 800;
$height = 600;
$rows = 0;
$hold = 1;
$at = null;
$remote = false;
$json = false;
$node = 0;
$frame = 0;
/** @var list<array{0: 'key'|'text'|'click'|'wheel'|'resolve', 1: string}> */
$steps = [];

foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $json = true;
    } elseif ($arg === '--remote') {
        $remote = true;
    } elseif ($arg === '--resolve') {
        $steps[] = ['resolve', ''];
    } elseif (\str_starts_with($arg, '--node=')) {
        $node = \max(0, (int) \substr($arg, 7));
    } elseif (\str_starts_with($arg, '--frame=')) {
        $frame = \max(0, (int) \substr($arg, 8));
    } elseif (\str_starts_with($arg, '--frames=')) {
        $frames = \max(1, (int) \substr($arg, 9));
    } elseif (\str_starts_with($arg, '--view-width=')) {
        $width = \max(1, (int) \substr($arg, 13));
    } elseif (\str_starts_with($arg, '--height=')) {
        $height = \max(1, (int) \substr($arg, 9));
    } elseif (\str_starts_with($arg, '--rows=')) {
        $rows = \max(0, (int) \substr($arg, 7));
    } elseif (\str_starts_with($arg, '--hold=')) {
        $hold = \max(1, (int) \substr($arg, 7));
    } elseif (\str_starts_with($arg, '--at=')) {
        $at = \max(1, (int) \substr($arg, 5));
    } elseif (\str_starts_with($arg, '--page=')) {
        $page = \substr($arg, 7);
    } elseif (\str_starts_with($arg, '--key=')) {
        $steps[] = ['key', \substr($arg, 6)];
    } elseif (\str_starts_with($arg, '--text=')) {
        $steps[] = ['text', \substr($arg, 7)];
    } elseif (\str_starts_with($arg, '--click=')) {
        $steps[] = ['click', \substr($arg, 8)];
    } elseif (\str_starts_with($arg, '--wheel=')) {
        $steps[] = ['wheel', \substr($arg, 8)];
    } elseif (\str_starts_with($arg, '--')) {
        \fwrite(\STDERR, "未知参数：{$arg}\n");
        exit(2);
    } else {
        $target = $arg;
    }
}

$label = $page ?? $target;
if ($label === null || $node <= 0 || $frame <= 0) {
    \fwrite(\STDERR, "用法：debug-node <AppClass|路由id> --page=<路由id> --node=N --frame=N [--json] [--remote]\n");
    exit(2);
}

$meta = $rows > 0 ? ['rows' => $rows] : [];
if ($page !== null) {
    $meta['page'] = 1;
}
$drive = Plan::build((string) $label, (float) $width, (float) $height, $meta, $frames, $hold, $steps);

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

    $answer = $session->query('align', ['node' => $node, 'frame' => $frame]);
    if ($json) {
        \fwrite(\STDOUT, (string) \json_encode(
            ['label' => $label] + $answer,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
        ) . "\n");
    } else {
        foreach (Align::lines($answer) as $line) {
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
