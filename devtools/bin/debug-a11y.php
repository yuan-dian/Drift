#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-a11y` —— 无障碍出口：问"读屏看到的是哪棵树、什么状态"（A77）。
 *
 * 语义树是 **L2 内省**：查询发生时用生产的 `SemanticsBuilder` 现建，不写记录、
 * 不进证据包、不进指纹。下标是 `toArrays()['nodes']` 的前序下标（与 UIA 快照
 * 同源），**不是**结构节点号，不能拿去 `debug node --node=N`。
 *
 * 用法：
 *   php devtools/bin/debug-a11y.php --page=table --frames=1
 *   php devtools/bin/debug-a11y.php --page=table --frames=1 --index=3
 *   php devtools/bin/debug-a11y.php --page=table --frames=1 --x=400 --y=300
 *   php devtools/bin/debug-a11y.php --page=table --frames=1 --json --remote
 *
 * 参数：
 *   （不给定位参数）   列整棵语义树
 *   --index=N          取一个语义节点（下标来自树行首的 #N）
 *   --x=N / --y=N      取包含该点的最深语义节点（逻辑像素视口坐标）
 *   --limit=N          树模式最多列几行（默认 200，0=不截断）
 *   --remote           用宿主进程跑（验证跨进程读到同一份答案）
 *   --json             结构化输出
 *   其余驱动参数同 debug-pick
 *
 * @internal 手工工具；不进 V2 判据清单
 */

require __DIR__ . '/../autoload.php';

use Drift\DevTools\App\Replay;
use Drift\DevTools\Cli\Commands;
use Drift\DevTools\Host\ProcessTransport;
use Drift\DevTools\Host\Runner;
use Drift\DevTools\Protocol\RemoteSession;
use Drift\DevTools\View\A11y;

$args = Commands::parseOrExit('a11y', $argv);
$width = $args->float('view-width');
$height = $args->float('height');
$meta = $args->meta();
$limit = $args->int('limit');
$hasIndex = $args->has('index');
$hasX = $args->has('x');
$hasY = $args->has('y');
$index = $args->int('index');
$x = $args->float('x');
$y = $args->float('y');
$at = $args->at();
$remote = $args->bool('remote');
$json = $args->bool('json');
$label = $args->label();

if ($label === null || ($hasIndex && ($hasX || $hasY)) || ($hasX !== $hasY)) {
    \fwrite(\STDERR, "用法：debug-a11y <AppClass|路由id> [--index=N | --x=N --y=N] [--limit=N] [--json] [--remote]\n");
    exit(2);
}
$what = $hasIndex ? 'node' : ($hasX ? 'hit' : 'tree');
$query = $what === 'node' ? 'semanticsNode' : ($what === 'hit' ? 'semanticsHit' : 'semantics');
$params = match ($what) {
    'node' => ['index' => $index],
    'hit' => ['x' => $x, 'y' => $y],
    default => ['limit' => $limit],
};

$drive = $args->script();
$session = null;
try {
    if ($remote) {
        $session = new RemoteSession(ProcessTransport::spawn([
            \PHP_BINARY,
            \dirname(__DIR__) . '/bin/debug-host.php',
        ]));
        $session->open($drive->fork());
    } else {
        $opened = Runner::open((string) $label, (float) $width, (float) $height, $meta, $drive->fork());
        $session = $opened['session'];
    }
    Replay::apply($session, $drive, [], $at, static function (int $id, float $ms): void {
    });

    $answer = $session->query($query, $params);
    if ($json) {
        \fwrite(\STDOUT, (string) \json_encode(
            ['label' => $label, 'what' => $what] + $answer,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
        ) . "\n");
    } else {
        foreach (A11y::lines($what, $answer) as $line) {
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
