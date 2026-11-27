#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-pick-live` —— 活窗口拾取：布防之后到真窗口里点一下，回节点的身份与路径。
 *
 * ## 为什么是"布防 → 点一下"，而不是"报个坐标"
 *
 * 坐标只有写脚本的人才知道。真窗口里看见一个按钮想问"它是谁"，正确动作是点它。
 * 这与 Chrome DevTools 的拾取按钮、Flutter Inspector 的 Select Widget Mode 同一条
 * 交互：先声明"下一次点击归调试器"，再由人去点。被拾取的输入不进应用 —— 否则
 * 选中一个按钮的同时会把它按下去。
 *
 * ## 结果从 `query('highlight')` 读，不另开出口
 *
 * 拾取落在绘制上就是高亮，而"高亮了谁、上屏了没"已经由 `highlight` 查询回答
 * （A51）。再开一个"拾取结果"查询等于同一件事两个名字，两边迟早会给出不同答案。
 *
 * 用法：
 *   php devtools/bin/debug-pick-live.php --session=ab12cd34
 *   php devtools/bin/debug-pick-live.php --timeout=10000 --json
 *   php devtools/bin/debug-pick-live.php --clear
 *
 * 参数：
 *   --session=<id>  多会话时点名（id 见 .drift/session.json）
 *   --timeout=N     等点击的毫秒数（默认 30000）
 *   --clear         只撤防，不等点击
 *   --json          结构化输出
 *
 * @internal 手工工具；不进 V2 判据清单
 */

require __DIR__ . '/../autoload.php';

use Drift\DevTools\Protocol\ProtocolError;
use Drift\DevTools\Cli\Commands;
use Drift\DevTools\Host\Source;

if (\in_array('--help', \array_slice($argv, 1), true)) {
    \fwrite(\STDOUT, Commands::helpFor('pick-live'));

    exit(0);
}

$session = null;
$timeoutMs = 30000;
$clear = false;
$json = false;

foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $json = true;
    } elseif ($arg === '--clear') {
        $clear = true;
    } elseif (\str_starts_with($arg, '--timeout=')) {
        $timeoutMs = \max(1, (int) \substr($arg, 10));
    } elseif (\str_starts_with($arg, '--session=')) {
        $session = \substr($arg, 10);
    } elseif (\str_starts_with($arg, '--')) {
        \fwrite(\STDERR, "未知参数：{$arg}\n");
        exit(2);
    } else {
        \fwrite(\STDERR, "拾取不需要位置参数：{$arg}\n");
        exit(2);
    }
}

$source = null;
try {
    $source = Source::resolve(attach: true, session: $session);
    $remote = $source->remote();
    if ($remote === null) {
        // attach: true 只会造出远端会话；留着是为了让"以后加了别的来源却忘了分支"撞在这里。
        throw new \LogicException('这个来源没有拾取面');
    }

    if ($clear) {
        $armed = $remote->pick(false);
        if (!$json) {
            \fwrite(\STDOUT, '拾取已撤防（armed=' . ($armed['armed'] ? 'true' : 'false') . "）\n");
        }

        exit(0);
    }

    // 先记下当前高亮：布防前就亮着同一个节点时，轮询不能把"旧画"当成这次点击的结果。
    $before = $remote->query('highlight');
    $armed = $remote->pick(true);
    if (($armed['armed'] ?? false) !== true) {
        $reason = $armed['reason'] ?? 'unknown';
        \fwrite(\STDERR, "拾取没有布防（{$reason}）："
            . ($reason === 'paused' ? '先 resume 再试' : '这个宿主没有可拾取的活窗口') . "\n");
        // 2 = 这次不能这么做（会话形态 / 宿主状态不满足），不是进程异常。
        exit(2);
    }
    if (!$json) {
        \fwrite(\STDERR, "请在窗口里点一下要检查的控件…\n");
    }

    $picked = null;
    $deadline = \microtime(true) + $timeoutMs / 1000.0;
    while (\microtime(true) < $deadline) {
        \usleep(100000);
        $now = $remote->query('highlight');
        $node = $now['node'] ?? 0;
        if (!\is_int($node) || $node <= 0 || ($now['onScreen'] ?? false) !== true) {
            continue;
        }
        if ($node === ($before['node'] ?? 0)
            && ($now['drawnFrame'] ?? 0) <= ($before['drawnFrame'] ?? 0)) {
            continue;
        }
        $picked = $now;
        break;
    }

    // 成功时桥已自动撤防；超时/失败时这里兜底。两边都调一次是幂等的，
    // 但不能省 —— 省了就把"布防还开着、后续点击继续被吞"留给下一个用这扇窗的人。
    $remote->pick(false);

    $answer = [
        'target' => $source->label(),
        'timedOut' => $picked === null,
        'node' => $picked['node'] ?? 0,
        'type' => $picked['type'] ?? null,
        'path' => $picked['path'] ?? null,
        'drawnFrame' => $picked['drawnFrame'] ?? null,
    ];
    if ($json) {
        \fwrite(\STDOUT, (string) \json_encode(
            $answer,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
        ) . "\n");
    } elseif ($picked === null) {
        \fwrite(\STDOUT, "等待 {$timeoutMs}ms，没有看到点击。\n");
    } else {
        // 双引号插值里放不了 `??`：先取成局部变量，别把显示逻辑写成解析错误。
        $type = (string) ($answer['type'] ?? '');
        $path = (string) ($answer['path'] ?? '(未知)');
        \fwrite(\STDOUT, "节点 {$answer['node']}  {$type}\n");
        \fwrite(\STDOUT, "路径  {$path}\n");
        \fwrite(\STDOUT, "追问：debug-props --node={$answer['node']}\n");
    }
} catch (ProtocolError $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    if ($e->errorCode === ProtocolError::NOT_APPLICABLE) {
        \fwrite(\STDERR, "（活窗口拾取只对 live attach 有意义：先起 DRIFT_DEBUG_LIVE=1 的开发壳）\n");
    }
    exit(2);
} catch (\Throwable $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
} finally {
    $source?->close();
}
