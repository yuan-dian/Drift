#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-web` —— 同一份证据的第三个消费者：浏览器。
 *
 * 它**不采集、不计算、不排版**。全部数据来自查询面，页面只负责画。
 * 这条纪律的可判形式是 `/api/brief` 与 `debug-brief --json` 的 `findings` 逐值相同
 * （见 `DESIGN-EVIDENCE-B4` 判据 1）—— 两份要是各算一遍，那些数字迟早对不上，
 * 而对不上时读者不知道信谁。
 *
 * 用法：
 *   php tools/debug-web.php --page=table --frames=1
 *   php tools/debug-web.php --attach                 # 接 `drift debug serve` 起的宿主
 *   php tools/debug-web.php --from=<证据包>           # 离线读包
 *   php tools/debug-web.php --page=table --slow=5 --port=7000
 *
 * 参数：
 *   --page / --frames / --rows / --click / --key / --text / --wheel / --resolve / --hold
 *                     与 brief / panel 同一套驱动词表
 *   --at=N            只重放到第 N 帧
 *   --from=<文件>     不跑会话，直接读证据包（与 --page / 驱动参数互斥）
 *   --attach          接一个已经在跑的会话（只读；`--ctl` 需要宿主自走）
 *   --session=<id>    同一目录有多个会话时点名
 *   --slow= / --fanout= / --repeat=  阈值（与 brief 同义，表头显示哪三项就能配哪三项）
 *   --port=N          监听端口（默认 0 = 系统挑一个）
 *   --host=<ip>       默认 127.0.0.1。绑 0.0.0.0 之前先想清楚：这个服务没有密码，
 *                     而它能读到进程内的全部结构。
 *
 * @internal 未进 VerifyPlan（起服务不结束，不适合放进批处理链）
 */

require __DIR__ . '/autoload.php';

use Drift\Debug\Session\Thresholds;
use Drift\Dev\Debug\HttpServer;
use Drift\Dev\Debug\Plan;
use Drift\Dev\Debug\Source;
use Drift\Dev\Debug\View\Web;

$target = null;
$page = null;
$frames = 1;
$framesGiven = false;
$from = null;
$width = 800;
$height = 600;
$rows = 0;
$hold = 1;
$at = null;
$remote = false;
$attach = false;
$sessionId = null;
$port = 0;
$host = '127.0.0.1';
$thresholds = Thresholds::default();
/** @var list<array{0: 'key'|'text'|'click'|'wheel'|'resolve', 1: string}> */
$steps = [];

foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--remote') {
        $remote = true;
    } elseif ($arg === '--attach') {
        $attach = true;
    } elseif (\str_starts_with($arg, '--session=')) {
        $sessionId = \substr($arg, 10);
    } elseif ($arg === '--resolve') {
        $steps[] = ['resolve', ''];
    } elseif (\str_starts_with($arg, '--frames=')) {
        $frames = \max(1, (int) \substr($arg, 9));
        $framesGiven = true;
    } elseif (\str_starts_with($arg, '--from=')) {
        $from = \substr($arg, 7);
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
    } elseif (\str_starts_with($arg, '--port=')) {
        $port = \max(0, (int) \substr($arg, 7));
    } elseif (\str_starts_with($arg, '--host=')) {
        $host = \substr($arg, 7);
    } elseif (\str_starts_with($arg, '--slow=')) {
        $thresholds = $thresholds->with(['slowMs' => (float) \substr($arg, 7)]);
    } elseif (\str_starts_with($arg, '--fanout=')) {
        $thresholds = $thresholds->with(['fanoutNodes' => (int) \substr($arg, 9)]);
    } elseif (\str_starts_with($arg, '--repeat=')) {
        $thresholds = $thresholds->with(['repeatPercent' => (int) \substr($arg, 9)]);
    } elseif (\str_starts_with($arg, '--page=')) {
        $page = \substr($arg, 7);
    } elseif (\str_starts_with($arg, '--key=')) {
        $steps[] = ['key', \substr($arg, 6)];
    } elseif (\str_starts_with($arg, '--text=')) {
        $steps[] = ['text', \substr($arg, 7)];
    } elseif (\str_starts_with($arg, '--click=')) {
        $steps[] = ['click', \substr($arg, 8)];
    } elseif (\str_starts_with($arg, '--wheel=')) {
        $steps[] = ['wheel', \substr($arg, 8)];
    } elseif ($arg === '--help' || $arg === '-h') {
        \fwrite(\STDOUT, "用法：debug-web [<AppClass|路由id>] [--page=<路由id>] [--frames=N] [--port=N]\n"
            . "      debug-web --attach [--session=<id>]\n"
            . "      debug-web --from=<证据包>\n");
        exit(0);
    } elseif (\str_starts_with($arg, '--')) {
        \fwrite(\STDERR, "未知参数：{$arg}\n");
        exit(2);
    } else {
        $target = $arg;
    }
}

$label = $from !== null ? \basename($from) : ($page ?? $target);

$source = null;
$server = null;
try {
    if ($from !== null && ($page !== null || $target !== null || $steps !== [] || $framesGiven)) {
        throw new \InvalidArgumentException(
            '--from 与 --page / 目标 / --frames / 驱动参数互斥：包里已经有完整的驱动记录',
        );
    }

    $drive = null;
    if ($from === null && !$attach) {
        if ($label === null) {
            \fwrite(\STDERR, "用法：debug-web [<AppClass|路由id>] [--page=<路由id>] [--frames=N] [--port=N]\n"
                . "      debug-web --attach [--session=<id>]\n"
                . "      debug-web --from=<证据包>\n");
            exit(2);
        }

        $meta = $rows > 0 ? ['rows' => $rows] : [];
        if ($page !== null) {
            $meta['page'] = 1;
        }

        $drive = Plan::build((string) $label, (float) $width, (float) $height, $meta, $frames, $hold, $steps);
    }

    $source = Source::resolve(
        from: $from,
        attach: $attach,
        remote: $remote,
        drive: $drive,
        at: $at,
        session: $sessionId,
    );
    $label = $source->label();

    // 只有接上自走宿主时才有驱动面：`pause` / `resume` 是那个进程的动作，
    // 离线读包与从属宿主都没有。没有就不给按钮 —— 给了按钮再回 501，比没有按钮更糟。
    $live = $source->remote();
    $control = $live === null ? null : static function (string $action, array $body) use ($live): array {
        if ($action === 'pause') {
            return $live->pause();
        }

        $frames = $body['frames'] ?? null;

        return $live->resume(\is_int($frames) ? $frames : null);
    };

    $web = new Web($source->queries(), $label, $thresholds, __DIR__ . '/Debug/View/web', $control);
    $server = new HttpServer($host, $port);

    \fwrite(\STDERR, \sprintf(
        "Web 视图：http://%s:%d/\n读的是 %s%s\nCtrl-C 结束\n",
        $host === '0.0.0.0' ? '127.0.0.1' : $host,
        $server->port(),
        $label,
        $control === null ? '（只读：没有驱动面）' : '（可暂停 / 单步）',
    ));

    if ($host === '0.0.0.0') {
        \fwrite(\STDERR, "⚠ 绑在 0.0.0.0：同网段任何人都能读到这个进程的结构，且没有密码\n");
    }

    $server->serve(static fn(string $m, string $p, array $q, array $b): array => $web->route($m, $p, $q, $b));
} catch (\Throwable $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
} finally {
    $server?->close();
    $source?->close();
}
