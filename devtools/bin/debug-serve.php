#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 调试**常驻宿主**：自己按脚本推帧，同时开一个 loopback 端口等人来看。
 *
 * 与 {@see \Drift\DevTools\Host\Host} 的分工：`Host` 只管"消息进、响应出、空闲时推一步"，
 * 而"推哪一步、隔多久推"是 {@see \Drift\DevTools\Host\Drive} 的事。这一层只解析本命令
 * 自己的词（`--page` / `--frames` / `--at` / `--speed` …）。
 *
 * ## 时间从脚本里来，不从墙钟来
 *
 * `--speed` 只改**两次 `pump()` 之间等多久**，不改喂进去的 `t` —— `t` 永远是脚本里
 * 写的那个值。所以 `--speed=0`（尽快）与 `--speed=1`（实时）跑同一个脚本，记录内容
 * 逐字节相同，只有墙钟耗时不同。反过来，拿 `now()` 当 `t` 的自走，无论多快都不可判。
 *
 * `--speed=0` 表示"不按实时节奏等待"，**不表示不考虑观察者的响应性**：每推一步都会
 * 回头看一眼有没有人说话（一次 `wait(0)`，几微秒）。这是暂停 / 单步能生效的前提，
 * 也让默认配置的总时长比"一口气跑完"多三成 —— 那不是性能回归，帧耗时里不含它。
 *
 * ## 跑完之后不退进程
 *
 * 脚本跑完只把状态改成 `finished`（`--at` 停的是 `paused` + `reason=at`），然后阻塞
 * 等人连上来查。进程退出的时机是"最后一个观察者走了"——宿主先退的话，发现文件里的
 * 条目就成了死的，而观察者看到的会是"连接被拒绝"。
 *
 * ## 能被叫停，也能被放行
 *
 * 另一个终端里 `drift debug ctl pause` / `ctl resume --frames=1` 就能停它、单步它。
 * `--at` 停下的会话同样能 `resume` 继续跑（观察点只生效一次）。
 *
 * 用法：
 *   php devtools/bin/debug-serve.php --page=table --frames=1
 *   php devtools/bin/debug-serve.php --page=async-table --rows=6 --resolve --speed=1
 *   php devtools/bin/debug-serve.php --page=table --frames=30 --at=10 --click=400,300
 *   php devtools/bin/debug-serve.php --page=table --frames=2000 --speed=0
 *   php devtools/bin/debug-serve.php --page=table --frames=1 --allow-eval   # 开 eval（默认关）
 *
 * @internal 手工工具；不进 V2 判据清单
 */

require __DIR__ . '/../autoload.php';

use Drift\Debug\Session\Script;
use Drift\DevTools\Cli\Commands;
use Drift\DevTools\Host\Discovery;
use Drift\DevTools\Host\Drive;
use Drift\DevTools\Host\Host;
use Drift\DevTools\Host\OutputGuard;
use Drift\DevTools\Host\Runner;
use Drift\DevTools\Host\SocketServer;

$args = Commands::parseOrExit('serve', $argv);
$at = $args->at();
$speed = $args->float('speed');
$frames = $args->int('frames');
$label = $args->label();
if ($label === null) {
    \fwrite(\STDERR, "用法：debug-serve <AppClass|路由id> [--page=<路由id>] [--frames=N] [--at=N] [--speed=N]\n");
    \fwrite(\STDERR, "      debug-serve --page=async-table --rows=6 --resolve --speed=1\n");
    exit(2);
}
if ($at !== null && $at > $frames) {
    // 不报错：脚本本来就只跑 frames 帧，`--at` 大于它等于没给。但要说一声，
    // 否则"我明明 --at=30 怎么只到 1 帧"会被当成 bug 查半天。
    \fwrite(\STDERR, "--at={$at} 大于 --frames={$frames}，脚本跑完就停，不会再往后推\n");
}

$script = $args->script();

$drive = new Drive($script, $at, $speed, static function (string $message): void {
    \fwrite(\STDERR, '驱动失败：' . $message . "\n");
});

$server = new SocketServer(0);
$token = \bin2hex(\random_bytes(16));
$discovery = Discovery::inDirectory((string) \getcwd());

OutputGuard::start();

// `exit()` 会跳过 `finally`（实测：try 里 exit(7)，finally 不执行）。所以异常
// 路径只记退出码，统一在收口之后 exit —— 否则 `.drift/session.json` 留着一条
// 死条目，下一个出口会报"连接被拒绝"而不是"没有会话"，把人引到错误的方向。
$entryId = null;
$code = 0;
try {
    $entryId = $discovery->register($script->target, $token, $server->port());
    \fwrite(\STDERR, \sprintf(
        "会话已就绪：%s（pid %d，端口 %d）\n连上去看：drift debug brief --attach\n"
        . "叫停 / 单步：drift debug ctl pause / ctl resume --frames=1\n",
        $discovery->path(),
        (int) \getmypid(),
        $server->port(),
    ));

    (new Host($server, $token, $drive, $args->bool('allow-eval')))->serve(
        static fn(Script $skeleton): array => Runner::open(
            $skeleton->target,
            $skeleton->width,
            $skeleton->height,
            $skeleton->meta(),
            $skeleton,
        ),
    );
} catch (\Throwable $e) {
    \fwrite(\STDERR, '宿主异常退出：' . $e->getMessage() . "\n");
    $code = 1;
} finally {
    if ($entryId !== null) {
        $discovery->unregister($entryId);
    }
    $server->close();
    OutputGuard::stop();
}

exit($code);
