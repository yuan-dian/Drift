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
 *   php tools/debug-network.php --page=async-table --rows=3 --resolve --frames=2 --request=1 --bodies
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
 *   --bodies          采集请求 / 响应正文（脱敏，默认关）
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

use Drift\DevTools\Session\SessionQueries;
use Drift\Dev\Debug\Cli\Commands;
use Drift\Dev\Debug\Source;
use Drift\Dev\Debug\View\Network;

$args = Commands::parseOrExit('network', $argv);
$from = $args->str('from');
$out = $args->str('out');
$at = $args->at();
$remote = $args->bool('remote');
$json = $args->bool('json');
$request = $args->has('request') ? $args->int('request') : null;
$part = $args->str('part') ?? 'both';
$limit = $args->int('limit');
$maxBytes = $args->int('maxBytes');
$label = $args->label();
$attach = $args->bool('attach');
$sessionId = $args->str('session');
if ($label === null && $from === null && !$attach) {
    \fwrite(\STDERR, "用法：debug-network <AppClass|路由id> [--page=<路由id>] [--frames=N] [--request=N] [--json]\n");
    \fwrite(\STDERR, "      debug-network --from=<证据包> [--request=N] [--json]\n");
    \fwrite(\STDERR, "      debug-network --request=N   # 当前目录只有一个活会话时自动接上\n");
    exit(2);
}
try {
    $attach = Source::autoAttach($attach, $from, $label, $sessionId);
} catch (\RuntimeException $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
}

$part = \in_array($part, ['both', 'request', 'response'], true) ? $part : 'both';

/** @var SessionQueries|null $session */
$session = null;
$source = null;
try {
    $drive = null;
    if ($from === null && !$attach) {
        $drive = $args->script();
    }

    $source = Source::resolve(
        from: $from,
        attach: $attach,
        session: $sessionId,
        remote: $remote,
        drive: $drive,
        at: $at,
        out: $out,
        recordOptions: $args->recordOptions(),
    );
    $label = $source->label();
    $session = $source->queries();

    // 导出放在查询之前、Replay 之后：帧已跑完，几何与请求记录都齐了。
    $source->export();

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
    $source?->close();
}
