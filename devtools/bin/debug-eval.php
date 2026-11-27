#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-eval` —— dev 壳内省出口：对一个**正在跑的宿主**求任意 PHP 表达式。
 *
 * ## 为什么只对活宿主，不做离线包
 *
 * 表达式的整个价值在于摸活应用：`$app` / `$session` / `$node` 只有宿主进程有。
 * 离线证据包里没有应用，给它求值只会得到一份"看起来像"的结果 —— 那是伪证。
 * 所以这里走 `Source::resolve(attach: true)`，没有活会话就直接报错，不退化。
 *
 * ## 安全由宿主守，不由这里守
 *
 * 任意代码执行是宿主的能力，`debug serve` 要显式 `--allow-eval` 才开；这里
 * 只是发一条协议消息。宿主没开时回 `not_applicable`，提示用户去起宿主时加开关。
 * loopback 绑定也在宿主侧（`SocketServer`）。客户端这一层不重复判，避免两处
 * 开关说法不一。
 *
 * ## 结果不是证据
 *
 * 求值回值不进包、不进指纹、不参与重放（A55）。若表达式改了应用状态，
 * 回执的 `evidenceChanged` 会报 `true` —— 此时求值前的那份证据不能用来解释
 * 求值后的行为。文本模式会多打一行提示，JSON 模式带字段给机器。
 *
 * 用法：
 *   php devtools/bin/debug-eval.php '$app->root()->id()'
 *   php devtools/bin/debug-eval.php '$session->query("stats")["frameCount"]' --json
 *   php devtools/bin/debug-eval.php '$node->widget->runtimeType()' --node=12
 *   php devtools/bin/debug-eval.php 'count($app->treeRoots())' --session=ab12cd34
 *
 * 参数：
 *   --node=N         把节点 N 解析成 $node 注入作用域（0 = 不注入）
 *   --session=<id>   多个会话时点名（id 见 .drift/session.json）
 *   --json           结构化输出
 *
 * @internal 手工工具；不进 V2 判据清单
 */

require __DIR__ . '/../autoload.php';

use Drift\DevTools\Protocol\ProtocolError;
use Drift\DevTools\Cli\Commands;
use Drift\DevTools\Host\Source;
use Drift\DevTools\View\EvalText;

// 直跑与 `drift debug eval --help` 走同一份帮助（都从 Registry 的文档块来），
// 别让"未知参数：--help"成为协议型出口的独有体验。
if (\in_array('--help', \array_slice($argv, 1), true)) {
    \fwrite(\STDOUT, Commands::helpFor('eval'));

    exit(0);
}

$expression = null;
$node = 0;
$session = null;
$json = false;

foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $json = true;
    } elseif (\str_starts_with($arg, '--node=')) {
        $node = \max(0, (int) \substr($arg, 7));
    } elseif (\str_starts_with($arg, '--session=')) {
        $session = \substr($arg, 10);
    } elseif (\str_starts_with($arg, '--')) {
        \fwrite(\STDERR, "未知参数：{$arg}\n");
        exit(2);
    } elseif ($expression === null) {
        // 只取第一个位置参数作表达式，其余拼回去 —— 表达式里常有空格与引号，
        // 被 shell 拆开后要还原，不能丢。
        $expression = $arg;
    } else {
        $expression .= ' ' . $arg;
    }
}

if ($expression === null || \trim($expression) === '') {
    \fwrite(\STDERR, "用法：debug-eval '<PHP表达式>' [--node=N] [--session=<id>] [--json]\n");
    \fwrite(\STDERR, "      先起一个开了 eval 的宿主：drift debug serve --page=table --frames=1 --allow-eval\n");
    exit(2);
}

$source = null;
try {
    $source = Source::resolve(attach: true, session: $session);
    $remote = $source->remote();
    if ($remote === null) {
        // attach: true 只会造出远端会话；留着是为了让"以后加了别的来源却忘了分支"撞在这里。
        throw new \LogicException('这个来源没有求值面');
    }

    $answer = $remote->evaluate($expression, $node);
    if ($json) {
        \fwrite(\STDOUT, (string) \json_encode(
            ['target' => $source->label()] + $answer,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
        ) . "\n");
    } else {
        foreach (EvalText::lines($answer) as $line) {
            \fwrite(\STDOUT, $line . "\n");
        }
    }
} catch (ProtocolError $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    if ($e->errorCode === ProtocolError::NOT_APPLICABLE) {
        \fwrite(\STDERR, "（宿主默认不开 eval：restart 时加 --allow-eval）\n");
    }
    // 2 = 这次不能这么做（宿主不支持 / 会话形态不对），不是进程异常。
    exit(2);
} catch (\Throwable $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
} finally {
    $source?->close();
}
