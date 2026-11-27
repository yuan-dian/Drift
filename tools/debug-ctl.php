#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-ctl` —— 控制面出口：让一个**正在自走**的宿主停一下、走一步、接着跑。
 *
 * ## 为什么是一个动作一条命令
 *
 * 干预的人通常不在看输出的那个终端里（一个窗口跑 `serve`，另一个窗口按暂停）。
 * `redis-cli` / `systemctl` 那种"连上、发一条、退下"的形状正好对上：可脚本化，
 * 也不会因为忘关一个交互式会话而占着一个连接。
 *
 * ## 不用给时间戳
 *
 * 单步是 `resume --frames=1`，推进的是**脚本自己的下一帧**。早先设想过把 `frame`
 * 降级成单步，那要求客户端编一个 `t` —— 而 `t` 属于脚本，插进去就是两条时间轴，
 * 且摘要比不出这件事（`Digest` 刻意不含时间）。
 *
 * ## 单步要等到真的停下来
 *
 * `resume` 的回执是"恢复的那一刻"，那时还是 `running`。单步的目的是"走一步再停"，
 * 所以带 `--frames` 时这里会等到状态不再是 `running` 才打印 —— 否则用户看到
 * `running`，会以为它一路跑下去了。
 *
 * 用法：
 *   drift debug ctl                          # 不带动作 = 看状态
 *   drift debug ctl pause
 *   drift debug ctl resume
 *   drift debug ctl resume --frames=1        # 单步
 *   drift debug ctl state --session=ab12cd34
 *
 * 参数：
 *   --session=<id>  同目录有多个会话时点名（id 见 .drift/session.json）
 *   --frames=N      resume 专用：只走 N 帧再停
 *   --json          结构化输出（形状与 `state` 查询一致）
 *
 * @internal 手工工具；不进 V2 判据清单
 */

require __DIR__ . '/autoload.php';

use Drift\DevTools\Protocol\ProtocolError;
use Drift\Dev\Debug\Source;
use Drift\Dev\Debug\View\Ctl;

const ACTIONS = ['state', 'pause', 'resume'];

$action = 'state';
$session = null;
$frames = null;
$json = false;

foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $json = true;
    } elseif (\str_starts_with($arg, '--frames=')) {
        $frames = \max(1, (int) \substr($arg, 9));
    } elseif (\str_starts_with($arg, '--session=')) {
        $session = \substr($arg, 10);
    } elseif (\str_starts_with($arg, '--')) {
        \fwrite(\STDERR, "未知参数：{$arg}\n");
        exit(2);
    } else {
        $action = $arg;
    }
}

if (!\in_array($action, ACTIONS, true)) {
    \fwrite(\STDERR, "不认识的动作：{$action}（可用：" . \implode(' / ', ACTIONS) . "）\n");
    exit(2);
}
if ($frames !== null && $action !== 'resume') {
    // 说了"只走 1 帧"却给了别的动作，等于两句话冲突；照着做哪一句都是错的。
    \fwrite(\STDERR, "--frames 只对 resume 有意义（单步：ctl resume --frames=1）\n");
    exit(2);
}

$source = null;
try {
    $source = Source::resolve(attach: true, session: $session);
    $remote = $source->remote();
    if ($remote === null) {
        // 走不到：`attach: true` 只会造出远端会话。留着是为了让"以后加了别的来源
        // 却忘了在这里分支"撞在这里，而不是撞在一个 null 上。
        throw new \LogicException('这个来源没有干预面');
    }

    $state = match ($action) {
        'pause' => $remote->pause(),
        'resume' => $remote->resume($frames),
        default => $remote->query('state'),
    };

    if ($action === 'resume' && $frames !== null) {
        $state = awaitStop($remote, $state);
    }

    if ($json) {
        \fwrite(\STDOUT, (string) \json_encode(
            ['target' => $source->label()] + $state,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
        ) . "\n");
    } else {
        foreach (Ctl::lines($state) as $line) {
            \fwrite(\STDOUT, $line . "\n");
        }
    }
} catch (ProtocolError $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    if ($e->errorCode === ProtocolError::NOT_APPLICABLE) {
        \fwrite(\STDERR, "（拿 state 的 driven 判断：true = 它自己推帧，能用 pause / resume）\n");
    }
    exit(1);
} catch (\Throwable $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
} finally {
    $source?->close();
}

/**
 * 等到它真的停下来。超时就照实报"还在跑"，不假装成功。
 *
 * @param array<array-key, mixed> $state `resume` 当时的回执
 *
 * @return array<array-key, mixed>
 */
function awaitStop(\Drift\DevTools\Protocol\RemoteSession $remote, array $state, float $seconds = 10.0): array
{
    $deadline = \microtime(true) + $seconds;
    while (($state['state'] ?? null) === 'running' && \microtime(true) < $deadline) {
        \usleep(10_000);
        $state = $remote->query('state');
    }

    if (($state['state'] ?? null) === 'running') {
        \fwrite(\STDERR, \sprintf("等了 %.0f 秒还没停（可能 --frames 太大或者限速在等）；下面是当前状态\n", $seconds));
    }

    return $state;
}
