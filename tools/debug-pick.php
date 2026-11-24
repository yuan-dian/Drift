#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-pick` —— 点选反查出口：给一个视口坐标，问"点到了谁"。
 *
 * 命中的是**当前活树**，与 `--click` 的输入坐标同口径（浮层会自动减掉原点）。
 * 返回的链是根 → 最深命中盒；每层给出节点号、Widget / State 类型与树路径，
 * 可以直接接 `debug-props.php --node=N` 继续追问配置。
 *
 * 用法：
 *   php tools/debug-pick.php --page=table --frames=1 --x=400 --y=300
 *   php tools/debug-pick.php ... --x=400 --y=300 --json
 *   php tools/debug-pick.php ... --x=400 --y=300 --remote
 *
 * 参数：
 *   --x=N / --y=N  必填：视口坐标（逻辑像素）
 *   其余驱动参数同 debug-node / debug-props
 *   --remote       用宿主进程跑（验证跨进程读到同一份答案）
 *   --json         结构化输出
 *
 * @internal 视图规则刚落地，未进 VerifyPlan
 */

require __DIR__ . '/autoload.php';

use Drift\App\Replay;
use Drift\Debug\Protocol\RemoteSession;
use Drift\Dev\Debug\Plan;
use Drift\Dev\Debug\ProcessTransport;
use Drift\Dev\Debug\Runner;
use Drift\Dev\Debug\View\Pick;

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
$x = 0.0;
$y = 0.0;
$hasX = false;
$hasY = false;
/** @var list<array{0: 'key'|'text'|'click'|'wheel'|'resolve', 1: string}> */
$steps = [];

foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $json = true;
    } elseif ($arg === '--remote') {
        $remote = true;
    } elseif ($arg === '--resolve') {
        $steps[] = ['resolve', ''];
    } elseif (\str_starts_with($arg, '--x=')) {
        $x = (float) \substr($arg, 4);
        $hasX = \is_numeric(\substr($arg, 4));
    } elseif (\str_starts_with($arg, '--y=')) {
        $y = (float) \substr($arg, 4);
        $hasY = \is_numeric(\substr($arg, 4));
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
if ($label === null || !$hasX || !$hasY) {
    \fwrite(\STDERR, "用法：debug-pick <AppClass|路由id> --x=N --y=N [--page=<路由id>] [--json] [--remote]\n");
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

    $answer = $session->query('pick', ['x' => $x, 'y' => $y]);
    if ($json) {
        \fwrite(\STDOUT, (string) \json_encode(
            ['label' => $label] + $answer,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
        ) . "\n");
    } else {
        foreach (Pick::lines($answer) as $line) {
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
