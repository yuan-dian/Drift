<?php
declare(strict_types=1);

// 运行期动态入口。改本文件不重编 dev.exe，由 drift-dev.php 重启进程生效。
require getcwd() . '/tools/autoload.php';

use Drift\App\EventLoop;
use Drift\DevTools\Host\LiveTicker;
use Drift\DevTools\Host\SocketServer;

$app = getenv('DRIFT_DEV_APP');
if ($app === false || $app === '') {
    $app = 'hello';
}
if (preg_match('/^[A-Za-z0-9_]+$/', $app) !== 1) {
    fwrite(STDERR, "[dev] 非法应用名：{$app}\n");
    return;
}

// 组合根按 PSR-4 找 apps/<app>/bin/win32/Bootstrap.php（见 composer.json）。
$class = 'App\\' . ucfirst(strtolower($app)) . '\\Bootstrap';
if (!class_exists($class)) {
    fwrite(STDERR, "[dev] 找不到组合根 {$class}\n");
    return;
}

// dev-only 的 live attach（A57）：`DRIFT_DEBUG_LIVE=1` 才装桥，AOT 组合根一行不改。
// 安装点在组合根之前 —— `AppEntry::run()` 是 final，应用不该为调试各改一行。
// 关桥在 `EventLoop` 的 finally，不在这里：关窗有三条出口，漏一条就留发现文件僵尸。
// `DRIFT_DEBUG_BODIES=1` / `DRIFT_DEBUG_ASYNC=1` 在宿主开场时打开可选采集域；
// 录制器归宿主，attach 端再传 --bodies / --async 已经晚了。
if (\getenv('DRIFT_DEBUG_LIVE') === '1') {
    $target = \getenv('DRIFT_DEBUG_TARGET');
    EventLoop::setTickerFactory(static function (array $apps) use ($target): ?\Drift\App\DebugTicker {
        $server = new SocketServer(0);
        $token = \bin2hex(\random_bytes(16));

        return new LiveTicker(
            $server,
            $token,
            $target !== false && $target !== '' ? $target : 'live',
            $server->port(),
            25.0,
            static function (string $message): void {
                \fwrite(\STDERR, "[debug] {$message}\n");
            },
        );
    });
}

(new $class())->run();
