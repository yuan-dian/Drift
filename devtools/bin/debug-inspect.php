#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-inspect` —— 真机叠加层：全体布局边界 / 选中节点的盒模型四层 / HUD。
 *
 * ## 这个出口回答什么
 *
 * 与 `debug-highlight` 同一形状，两件事缺一不可：
 *
 * 1. 层**开了**没有（回执 `layers` + `state` 的 `inspector`）；
 * 2. 层**画上屏**了没有（叠加前后像素指纹，不靠谁自报）。
 *
 * 只答第一件，等于奖励"回执照回、闭包没接上"；只答第二件，等于奖励"画了别的"。
 *
 * ## 覆盖式，不是累加式
 *
 * 不给 `--layer` 就是**全关**。累加式（`--on` / `--off` 两条路子）会让
 * "现在到底开着几层"变成一个要读文档才能答的问题，而它恰恰是 Web 要镜像的那项状态。
 *
 * ## 边界
 *
 * 这个进程跑完就退出，叠加不会留在任何窗口里。它证明的是通道是通的；要在人眼前
 * 看见，得发到常驻宿主（`debug serve` + `debug ctl`）或 Web 视图。
 *
 * 用法：
 *   php devtools/bin/debug-inspect.php --page=table --at=2 --layer=bounds
 *   php devtools/bin/debug-inspect.php --page=table --at=2 --layer=bounds,box-model,hud
 *   php devtools/bin/debug-inspect.php --page=table --at=2                 # 全关
 *   php devtools/bin/debug-inspect.php --page=table --at=2 --layer=bounds --json
 */

require __DIR__ . '/../autoload.php';

use Drift\DevTools\App\Replay;
use Drift\DevTools\Protocol\RemoteSession;
use Drift\DevTools\Cli\Commands;
use Drift\DevTools\Host\ProcessTransport;
use Drift\DevTools\Host\Runner;
use Drift\DevTools\View\Inspect;

$args = Commands::parseOrExit('inspect', $argv);
$width = $args->float('view-width');
$height = $args->float('height');
$meta = $args->meta();
$at = $args->at();
$remote = $args->bool('remote');
$json = $args->bool('json');
$layers = $args->strings('layer');
$label = $args->label();
if ($label === null) {
    \fwrite(\STDERR, "用法：debug-inspect <AppClass|路由id> [--layer=bounds,box-model,hud] [--page=<路由id>] [--json] [--remote]\n");
    exit(2);
}

$drive = $args->script();

/** @return string|null 当前屏幕的像素指纹；没表面或一帧还没画时为 `null` */
function pixelHash(?Drift\Dev\Smoke\Frame\SurfaceProbe $surface): ?string
{
    $canvas = $surface?->raster();

    return $canvas === null ? null : \hash('sha256', $canvas->toRgba());
}

$session = null;
$surface = null;
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
        $surface = $opened['surface'];
    }

    Replay::apply($session, $drive, [], $at);
    $lastFrame = $session->query('frames', ['limit' => 1])['frames'];
    $lastT = $lastFrame === [] ? 0.0 : (float) $lastFrame[0]['t'];

    $before = pixelHash($surface);

    $answer = $session->inspect($layers);
    // 叠加不标脏 ⇒ 不主动推一帧就永远停在"请求了"。与 `highlight` 同一条理由。
    $session->pump($lastT + 16.0);

    $after = pixelHash($surface);
    $selection = $session->query('highlight');
    $hasSelection = (int) ($selection['node'] ?? 0) > 0;

    // 上屏帧要在**推帧之后**读：`inspect` 的回执读在画之前，只能是"上一次"，
    // 拿它判断"到底画出来没有"会把第一次调用判成"一帧都没画"。
    $state = $session->query('state');
    $drawnFrame = $state['inspectorDrawn'] ?? null;

    if ($json) {
        \fwrite(\STDOUT, (string) \json_encode(
            [
                'label' => $label,
                'layers' => $answer['layers'] ?? [],
                'drawnFrame' => $drawnFrame,
                'selected' => $selection['node'] ?? 0,
                'state' => $state['inspector'] ?? [],
                'hud' => $answer['hud'] ?? [],
                'pixels' => ['before' => $before, 'after' => $after],
            ],
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
        ) . "\n");
    } else {
        foreach (Inspect::lines(
            $answer['layers'] ?? [],
            $drawnFrame === null ? null : (int) $drawnFrame,
            $hasSelection,
            $before,
            $after,
        ) as $line) {
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
