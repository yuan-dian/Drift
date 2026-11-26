#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug capabilities` —— 协议版本与能力目录。
 *
 * 不带 `--session` 时只读本地声明：不需要应用、不需要证据包、也不需要跑一帧。
 * 带 `--session=<id>` 时接一个活会话，把宿主的可用性一栏也带上（`eval` 开没开、
 * 帧谁推、拾取有没有窗口）——那部分只有宿主自己知道，不能靠本地声明猜。
 *
 * 用法：
 *   php tools/debug-capabilities.php
 *   php tools/debug-capabilities.php --session=<登记id> --json
 *
 * 参数：
 *   --session=ID  接一个活会话，取它的宿主能力；不给则只报协议声明
 *   --json        结构化输出
 *   --width=N     文本行宽（默认 100）
 *
 * @internal 手工工具；不进 V2 判据清单
 */

require __DIR__ . '/autoload.php';

use Drift\Debug\Protocol\Capabilities;
use Drift\Debug\Protocol\ProtocolError;
use Drift\Dev\Debug\Cli\Commands;
use Drift\Dev\Debug\Source;
use Drift\Dev\Debug\View\CapabilitiesText;

if (\in_array('--help', \array_slice($argv, 1), true)) {
    \fwrite(\STDOUT, Commands::helpFor('capabilities'));

    exit(0);
}

$json = false;
$sessionId = null;
$width = 100;
foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $json = true;
    } elseif (\str_starts_with($arg, '--session=')) {
        $sessionId = (string) \substr($arg, 10);
        if ($sessionId === '') {
            \fwrite(\STDERR, "--session 需要登记 id\n");
            exit(2);
        }
    } elseif (\str_starts_with($arg, '--width=')) {
        $width = \max(40, (int) \substr($arg, 8));
    } else {
        \fwrite(\STDERR, "未知参数：{$arg}（--help 看用法）\n");
        exit(2);
    }
}

$reply = Capabilities::describe();
$source = null;
if ($sessionId !== null) {
    try {
        $source = Source::attachOne($sessionId);
        $remote = $source->remote();
        if ($remote === null) {
            throw new \RuntimeException('这个会话没有干预通道，取不到宿主能力');
        }
        $reply = $remote->capabilities();
    } catch (ProtocolError $e) {
        // 老宿主不认识 capabilities 动词：这是明确的不支持，报出来让人升级，
        // 而不是退回本地声明冒充"已经问过宿主了"。
        \fwrite(\STDERR, '宿主没有能力发现（' . $e->errorCode . '）：' . $e->getMessage() . "\n");
        exit(2);
    } catch (\Throwable $e) {
        \fwrite(\STDERR, $e->getMessage() . "\n");
        exit(2);
    } finally {
        $source?->close();
    }
}

if ($json) {
    \fwrite(\STDOUT, (string) \json_encode(
        $reply,
        \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
    ) . "\n");
} else {
    foreach (CapabilitiesText::render($reply, $width) as $line) {
        \fwrite(\STDOUT, $line . "\n");
    }
}

exit(0);
