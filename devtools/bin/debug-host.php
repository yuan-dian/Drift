#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * 调试**宿主进程**：从 stdin 读命令、往 stdout 写结果，中间跑一个真实页面。
 *
 * 它不自己拿主意：每条消息都来自客户端。命令行参数一概不看 —— "跑哪一页、多大视口、
 * 要不要取数替身"全在客户端交上来的脚本里（`target` / `viewport` / `meta`）。
 * 这样"怎么造这一页"只有一份规则（{@see \Drift\DevTools\Host\Target}），不会因为
 * 多了一个进程就多一处解析。
 *
 * 一般不由人手工调用，而是被 `composer debug -- --remote` 起；手工调试协议也可以：
 *
 *   echo '{"id":1,"method":"query","params":{"name":"stats"}}' | php devtools/bin/debug-host.php
 *
 * @internal 手工工具；不进 V2 判据清单
 */

require __DIR__ . '/../autoload.php';

use Drift\Debug\Session\RecordOptions;
use Drift\Debug\Session\Script;
use Drift\DevTools\Host\Host;
use Drift\DevTools\Host\OutputGuard;
use Drift\DevTools\Host\Runner;
use Drift\DevTools\Host\SingleServer;
use Drift\DevTools\Host\StdioTransport;

// ★ 先接管输出再干任何事：stdout 从此只属于协议。页面里的 echo、PHP 警告都会被
//   改道 stderr —— 否则客户端看到的是"消息不是合法 JSON"，真凶却隔了一条管道。
OutputGuard::start();

try {
    $host = new Host(new SingleServer(StdioTransport::standard()));
    $host->serve(static function (Script $script, ?RecordOptions $options = null): array {
        return Runner::open($script->target, $script->width, $script->height, $script->meta(), $script, $options);
    });
} catch (\Throwable $e) {
    OutputGuard::stop();
    \fwrite(\STDERR, '宿主异常退出：' . $e->getMessage() . "\n");
    exit(1);
}

OutputGuard::stop();
exit(0);
