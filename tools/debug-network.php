#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-network` —— 网络域的专用出口：先看骨架，再按 requestId 取报文。
 *
 * 为什么单独一个命令而不是塞进 `debug-brief`：简报回答"哪里不对"，要压进行预算；
 * 报文回答"字段对不对"，要完整可读。两种投影放一个视图里只会互相迁就——
 * 前者会被 body 撑爆，后者会被预算裁掉。所以骨架与报文分成两次查询：
 * `network()` 默认轻量可导出，`networkBody()` 按 id 显式取，脱敏且截断。
 *
 * 读取全部走查询面（没有一行直接摸 Recorder），所以 `--remote` 与本地同一条路。
 * 报文由查询层脱敏后才到这里，CLI 不碰原文；`redacted` / `truncated` 如实显示，
 * 不许把脱敏后的内容让用户误以为是原文。
 *
 * 用法：
 *   php tools/debug-network.php --page=async-table --rows=3 --resolve --frames=2
 *   php tools/debug-network.php --page=async-table --rows=3 --resolve --frames=2 --request=1
 *   php tools/debug-network.php ... --request=1 --part=response --maxBytes=0 --json
 *   php tools/debug-network.php ... --remote
 *   php tools/debug-network.php --page=async-table --rows=3 --resolve --frames=2 --out=case.evidence
 *   php tools/debug-network.php --from=case.evidence --request=1
 *
 * 参数：
 *   --request=N       按 requestId 取报文；不给则列请求骨架
 *   --part=both|request|response  只要一侧（默认 both）
 *   --limit=N         骨架列表条数（默认 50，0=不截断）
 *   --maxBytes=N      查询侧每条 body 的上限（默认 8192，0=不截断）
 *   --page=<路由id>   页面（与面板同义）
 *   --frames=N        驱动几帧（默认 1）
 *   --rows=N          取数替身参数（异步页需要）
 *   --click=x,y / --key= / --text= / --wheel= / --resolve / --hold=N  驱动词表
 *   --at=N            只重放到第 N 帧
 *   --view-width=N / --height=N  视口尺寸（默认 800×600）
 *   --remote          用宿主进程跑（验证跨进程读到同一份证据）
 *   --out=<文件>      把这次会话导出成证据包（NDJSON，首行 manifest）
 *   --from=<文件>     不跑会话，直接读证据包复算（与驱动参数互斥）
 *   --json            结构化输出
 *
 * @internal 未进 VerifyPlan：报文隐私口径还在定，进 CI 会把脱敏规则钉死
 */

require __DIR__ . '/autoload.php';

use Drift\App\Replay;
use Drift\Debug\Protocol\RemoteSession;
use Drift\Debug\Session\Evidence;
use Drift\Debug\Session\QueryRouter;
use Drift\Debug\Session\Recorder;
use Drift\Debug\Session\SessionQueries;
use Drift\Dev\Debug\Plan;
use Drift\Dev\Debug\ProcessTransport;
use Drift\Dev\Debug\Runner;
use Drift\Dev\Debug\View\Network;

$target = null;
$page = null;
$frames = 1;
$framesGiven = false;
$width = 800;
$height = 600;
$rows = 0;
$hold = 1;
$at = null;
$remote = false;
$json = false;
$request = null;
$from = null;
$out = null;
$part = 'both';
$limit = 50;
$maxBytes = 8192;
/** @var list<array{0: 'key'|'text'|'click'|'wheel'|'resolve', 1: string}> */
$steps = [];

foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $json = true;
    } elseif ($arg === '--remote') {
        $remote = true;
    } elseif ($arg === '--resolve') {
        $steps[] = ['resolve', ''];
    } elseif (\str_starts_with($arg, '--request=')) {
        $request = \max(1, (int) \substr($arg, 10));
    } elseif (\str_starts_with($arg, '--part=')) {
        $part = \substr($arg, 7);
    } elseif (\str_starts_with($arg, '--limit=')) {
        $limit = \max(0, (int) \substr($arg, 8));
    } elseif (\str_starts_with($arg, '--maxBytes=')) {
        $maxBytes = \max(0, (int) \substr($arg, 11));
    } elseif (\str_starts_with($arg, '--frames=')) {
        $frames = \max(1, (int) \substr($arg, 9));
        $framesGiven = true;
    } elseif (\str_starts_with($arg, '--view-width=')) {
        $width = \max(1, (int) \substr($arg, 13));
    } elseif (\str_starts_with($arg, '--height=')) {
        $height = \max(1, (int) \substr($arg, 9));
    } elseif (\str_starts_with($arg, '--rows=')) {
        $rows = \max(0, (int) \substr($arg, 7));
    } elseif (\str_starts_with($arg, '--hold=')) {
        $hold = \max(1, (int) \substr($arg, 7));
    } elseif (\str_starts_with($arg, '--at=')) {
        $at = \max(1, (int) \substr($arg, 5));
    } elseif (\str_starts_with($arg, '--page=')) {
        $page = \substr($arg, 7);
    } elseif (\str_starts_with($arg, '--from=')) {
        $from = \substr($arg, 7);
    } elseif (\str_starts_with($arg, '--out=')) {
        $out = \substr($arg, 6);
    } elseif (\str_starts_with($arg, '--key=')) {
        $steps[] = ['key', \substr($arg, 6)];
    } elseif (\str_starts_with($arg, '--text=')) {
        $steps[] = ['text', \substr($arg, 7)];
    } elseif (\str_starts_with($arg, '--click=')) {
        $steps[] = ['click', \substr($arg, 8)];
    } elseif (\str_starts_with($arg, '--wheel=')) {
        $steps[] = ['wheel', \substr($arg, 8)];
    } elseif (\str_starts_with($arg, '--')) {
        \fwrite(\STDERR, "未知参数：{$arg}\n");
        exit(2);
    } else {
        $target = $arg;
    }
}

$label = $from !== null ? \basename($from) : ($page ?? $target);
if ($label === null) {
    \fwrite(\STDERR, "用法：debug-network <AppClass|路由id> [--page=<路由id>] [--frames=N] [--request=N] [--json]\n");
    \fwrite(\STDERR, "      debug-network --from=<证据包> [--request=N] [--json]\n");
    exit(2);
}

$part = \in_array($part, ['both', 'request', 'response'], true) ? $part : 'both';

/** @var SessionQueries|null $session */
$session = null;
$closable = null;
try {
    if ($from !== null) {
        // 包里已有完整的驱动与请求记录，再给驱动参数就是两套说法。
        if ($page !== null || $target !== null || $steps !== [] || $framesGiven || $at !== null) {
            throw new \InvalidArgumentException(
                '--from 与 --page / 目标 / --frames / --at / 驱动参数互斥：包里已经有完整的驱动记录',
            );
        }
        if ($out !== null) {
            // 同 debug-brief：离线读出的录制器是游离实例，写出去的是另一份东西。
            throw new \InvalidArgumentException(
                '--from 与 --out 不能同用：离线复算不产生新记录，要另存请重跑一次会话',
            );
        }

        $offline = Recorder::hydrate(Evidence::read($from));
        $session = QueryRouter::offline($offline);
        $label = $offline->script()?->target ?? \basename($from);
    } else {
        if ($out !== null && $remote) {
            throw new \InvalidArgumentException('--out 不能与 --remote 同用：导出要本地那份记录');
        }

        $meta = $rows > 0 ? ['rows' => $rows] : [];
        if ($page !== null) {
            $meta['page'] = 1;
        }

        $drive = Plan::build((string) $label, (float) $width, (float) $height, $meta, $frames, $hold, $steps);

        if ($remote) {
            $session = new RemoteSession(ProcessTransport::spawn([
                \PHP_BINARY,
                \dirname(__DIR__) . '/tools/debug-host.php',
            ]));
            $session->open($drive->fork());
        } else {
            $opened = Runner::open((string) $label, (float) $width, (float) $height, $meta, $drive->fork());
            $session = $opened['session'];
        }
        $closable = $session;

        Replay::apply($session, $drive, [], $at, static function (int $id, float $ms): void {
        });
    }

    // 导出放在查询之前、Replay 之后：帧已跑完，几何与请求记录都齐了。
    if ($out !== null) {
        $recorder = Recorder::current();
        if ($recorder === null) {
            throw new \RuntimeException('--out 要本地那份记录，当前没有录制器');
        }
        Evidence::write($recorder, $out);
    }

    if ($request !== null) {
        $answer = $session->query('networkBody', [
            'request' => $request,
            'part' => $part,
            'maxBytes' => $maxBytes,
        ]);
        if ($json) {
            \fwrite(\STDOUT, (string) \json_encode(
                ['label' => $label] + $answer,
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
            ) . "\n");
        } else {
            foreach (Network::body($answer) as $line) {
                \fwrite(\STDOUT, $line . "\n");
            }
        }
    } else {
        $answer = $session->query('network', ['limit' => $limit]);
        if ($json) {
            \fwrite(\STDOUT, (string) \json_encode(
                ['label' => $label] + $answer,
                \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
            ) . "\n");
        } else {
            foreach (Network::skeleton($answer) as $line) {
                \fwrite(\STDOUT, $line . "\n");
            }
        }
    }
} catch (\Throwable $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
} finally {
    $closable?->close();
}
