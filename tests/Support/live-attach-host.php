<?php
declare(strict_types=1);

/**
 * live attach 的**子进程宿主**：真 socket、真 `LiveTicker`、假窗口帧源。
 *
 * 目的只有一个：让 `RemoteSession` 的请求能像真窗口那样被"每轮一次"的轮询接住。
 * 在同一个进程里同步调用 `attach()` 是接不到答复的 —— 那需要有人同时在收消息，
 * 而 PHP 开发壳没有第二条线程（A57 选协作式的理由）。帧源用最简单的话代替主循环：
 * 每轮一帧、跑完通知 ticker，语义与 `EventLoop` 一致。
 *
 * 用法：`php tests/Support/live-attach-host.php <dir>`
 * 就绪写 `<dir>/ready.json`；看到 `<dir>/stop` 就正常收尾。
 */

require \dirname(__DIR__, 2) . '/tools/autoload.php';

use Drift\Dev\Debug\Discovery;
use Drift\Dev\Debug\LiveTicker;
use Drift\Dev\Debug\SocketServer;
use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;

$dir = \rtrim((string) ($argv[1] ?? ''), '/\\');
if ($dir === '' || !\is_dir($dir)) {
    \fwrite(\STDERR, "需要一个已存在的目录\n");
    exit(2);
}

$server = new SocketServer(0);
$token = \bin2hex(\random_bytes(16));
$discovery = Discovery::inDirectory($dir);
$ticker = new LiveTicker($server, $token, 'live-test', $server->port(), 5.0, null, $discovery);
$app = new EchoApplication();
$app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
$ticker->bind([1 => $app]);

\file_put_contents(
    $dir . '/ready.json',
    \json_encode([
        'port' => $server->port(),
        'token' => $token,
        'pid' => \getmypid(),
        'entries' => \array_column($discovery->sessions(), 'id'),
    ], \JSON_THROW_ON_ERROR),
);

$start = \microtime(true);
$next = 0.0;
while (!\is_file($dir . '/stop')) {
    $ticker->poll();
    if ($ticker->canAdvance()) {
        $app->frame($next, BoxConstraints::tight(new Size(320.0, 240.0)));
        $ticker->afterFrame();
        $next += 16.0;
    }
    // 不让子进程空转烧 CPU；5ms 足够让 socket 请求在毫秒级被接住。
    \usleep(5_000);
    if (\microtime(true) - $start > 60.0) {
        break;   // 兜底：测试忘了写 stop 也不能留一个孤儿进程
    }
}

$ticker->close();
exit(0);
