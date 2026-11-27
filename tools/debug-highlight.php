#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-highlight` —— 高亮的反向流出口：让一个节点在屏上被描边，并回答"它真的上屏了吗"。
 *
 * ## 这个出口回答什么
 *
 * 两件事，缺一不可：
 *
 * 1. 高亮**请求**记下了没有（`step` 进脚本、`query('highlight')` 报得出号）；
 * 2. 高亮**画上屏**了没有 —— 由像素指纹前后比对，而不是靠谁自报。
 *
 * 只答第一件，等于奖励"设了值但根本没画"；只答第二件，等于奖励"画了别人的"。
 *
 * ## 边界（写在前面，免得误读）
 *
 * 这个进程跑完就退出，描边**不会留在任何窗口里**。它证明的是"叠加通道是通的"：
 * 同一条通道上，`wantFrame` 的失效信号、`afterPaint` 的绘制点、`devFacts` 的记账
 * 三处都接对了。真要在人眼前看见它，得把同一句 `highlight` 发到常驻宿主
 * （`debug serve` + `debug ctl`）或面板 / Web 视图上。
 *
 * ## 为什么高亮之后还要再推一帧
 *
 * 高亮不改结构 ⇒ 不标脏 ⇒ 它只在**下一次绘制**里出现。脚本已经跑完之后，
 * 那"下一次"不会自己来，所以这里显式推一帧（推进去的那一帧同样进脚本，
 * 因此 `--save` 存下来的脚本仍然可重放）。
 *
 * 用法：
 *   php tools/debug-highlight.php --page=table --at=2 --node=37
 *   php tools/debug-highlight.php --page=table --at=2 --node=0      # 清除
 *   php tools/debug-highlight.php --page=table --at=2 --node=37 --json
 *   php tools/debug-highlight.php --page=table --at=2 --node=37 --remote
 *
 * 参数：
 *   --node=N   必填：要描边的节点 id（`0` = 清除）；可由 debug-pick / debug-panel 取到
 *   --remote   用宿主进程跑（验证跨进程同形；远端没有表面，故不取像素）
 *   --json     结构化输出
 *   其余驱动参数同 debug-node / debug-props
 */

require __DIR__ . '/autoload.php';

use Drift\DevTools\App\Replay;
use Drift\DevTools\Protocol\RemoteSession;
use Drift\Dev\Debug\Cli\Commands;
use Drift\Dev\Debug\ProcessTransport;
use Drift\Dev\Debug\Runner;
use Drift\Dev\Debug\View\Highlight;

$args = Commands::parseOrExit('highlight', $argv);
$width = $args->float('view-width');
$height = $args->float('height');
$meta = $args->meta();
$node = $args->int('node');
$at = $args->at();
$remote = $args->bool('remote');
$json = $args->bool('json');
$label = $args->label();
if ($label === null || $node < 0 || !$args->has('node')) {
    \fwrite(\STDERR, "用法：debug-highlight <AppClass|路由id> --node=N [--page=<路由id>] [--json] [--remote]\n");
    exit(2);
}

$drive = $args->script();

/**
 * 当前屏幕的像素指纹；没表面（`--remote`）或一帧都没画过时给 `null`。
 *
 * `raster()` 回放的是**最近一次画完**的显示列表，所以"高亮前"取到的是上一帧的
 * 样子，不是空的 —— 这正是要比的两端。
 */
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
            \dirname(__DIR__) . '/tools/debug-host.php',
        ]));
        $session->open($drive->fork());
    } else {
        $opened = Runner::open((string) $label, (float) $width, (float) $height, $meta, $drive->fork());
        $session = $opened['session'];
        $surface = $opened['surface'];
    }

    // 末帧时刻要自己接住：高亮之后那一帧必须落在脚本的时钟上（编一个时刻会让
    // 同一份脚本重放得到另一条时间轴）。`onFrame` 给的是整帧墙钟，不是 `t` ——
    // 时刻只能从记录里读。
    Replay::apply($session, $drive, [], $at);
    $lastFrame = $session->query('frames', ['limit' => 1])['frames'];
    $lastT = $lastFrame === [] ? 0.0 : (float) $lastFrame[0]['t'];

    $before = pixelHash($surface);

    $session->highlight($node);
    $session->pump($lastT + 16.0);

    $after = pixelHash($surface);

    $answer = $session->query('highlight');
    if ($json) {
        \fwrite(\STDOUT, (string) \json_encode(
            ['label' => $label, 'pixels' => ['before' => $before, 'after' => $after]] + $answer,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
        ) . "\n");
    } else {
        foreach (Highlight::lines($answer, $before, $after) as $line) {
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
