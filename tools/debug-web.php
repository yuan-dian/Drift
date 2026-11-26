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
 *   php tools/debug-web.php                          # 接当前目录正在跑的那个应用
 *   php tools/debug-web.php --page=table --frames=1  # 不接应用，自己跑示例台
 *   php tools/debug-web.php --from=<证据包>           # 离线读包
 *   php tools/debug-web.php --page=table --slow=5 --port=7000
 *
 * 参数：
 *   --page / --frames / --rows / --click / --key / --text / --wheel / --resolve / --hold
 *                     与 brief / panel 同一套驱动词表
 *   --at=N            只重放到第 N 帧
 *   --from=<文件>     不跑会话，直接读证据包（与 --page / 驱动参数互斥）
 *   --attach          接一个已经在跑的会话（可暂停/高亮/真机拾取，需宿主自走）
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
use Drift\Dev\Debug\View\Rules\RuleSelection;
use Drift\Dev\Debug\View\Rules\Severity;
use Drift\Dev\Debug\Cli\Commands;
use Drift\Dev\Debug\Discovery;
use Drift\Dev\Debug\HttpServer;
use Drift\Dev\Debug\Source;
use Drift\Dev\Debug\View\Web;

$args = Commands::parseOrExit('web', $argv);
$from = $args->str('from');
$at = $args->at();
$remote = $args->bool('remote');
$attach = $args->bool('attach');
$sessionId = $args->str('session');
$port = $args->int('port');
$host = $args->str('host') ?? '127.0.0.1';
$thresholds = Thresholds::default()
    ->with(['slowMs' => $args->float('slow')])
    ->with(['fanoutNodes' => $args->int('fanout')])
    ->with(['repeatPercent' => $args->int('repeat')]);
$minRaw = $args->str('min-severity');
$min = $minRaw === null ? null : Severity::parse($minRaw);
if ($minRaw !== null && $min === null) {
    \fwrite(\STDERR, "未知严重度：{$minRaw}；可用：info / warning / error\n");
    exit(2);
}
// 启动时的默认选择；页面还能用 `?rule=…&minSeverity=…` 逐次覆盖。
$selection = new RuleSelection(
    only: $args->strings('rule'),
    without: $args->strings('without'),
    min: $min,
);
$label = $args->label();

// 真实应用的常态是「应用先跑着，再开 web 看」，不是先想起 --page。发现规则与
// 其余出口共用一份（Source::autoAttach），否则每个出口的"不猜"条件都不一样。
try {
    $attach = Source::autoAttach($attach, $from, $label, $sessionId);
} catch (\RuntimeException $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
}

$source = null;
$server = null;
try {
    $drive = null;
    if ($from === null && !$attach) {
        if ($label === null) {
            \fwrite(\STDERR, "当前目录没有正在跑的应用。\n"
                . "  先起一个：drift dev --entry=<入口文件>\n"
                . "  或自己跑示例台：debug-web --page=<路由id> [--frames=N]\n"
                . "  或读一份证据包：debug-web --from=<包>\n");
            exit(2);
        }

        $drive = $args->script();
    }

    $source = Source::resolve(
        from: $from,
        attach: $attach,
        remote: $remote,
        drive: $drive,
        at: $at,
        session: $sessionId,
        recordOptions: $args->recordOptions(),
    );
    $label = $source->label();

    // 只有接上自走宿主时才有干预面：`pause` / `resume` 推节奏，`highlight` / `pick`
    // 改"下一帧画什么"。离线读包与从属宿主都没有，没有就不给按钮 —— 给了按钮再回
    // 501，比没有按钮更糟。
    $live = $source->remote();
    $control = $live === null ? null : static function (string $action, array $body) use ($live): array {
        if ($action === 'pause') {
            return $live->pause();
        }
        if ($action === 'highlight') {
            $live->highlight((int) $body['node']);
            // 回执取宿主侧的真相：请求与上屏之间隔着一帧，页面不该把回执当成"已经画了"。
            return $live->query('highlight');
        }
        if ($action === 'pick') {
            return $live->pick((bool) $body['enabled']);
        }

        $frames = $body['frames'] ?? null;

        return $live->resume(\is_int($frames) ? $frames : null);
    };

    // 画面通道：只有接活会话才可能有像素（离线包/自己跑的会话都没有宿主进程在拍）。
    // 目录约定与宿主 `Shot` 一致：`<cwd>/.drift`。
    $evidenceDir = $attach ? \dirname(Discovery::inDirectory((string) \getcwd())->path()) : null;
    // 底图与几何必须来自同一个会话：目录按会话号分开，别让另一个宿主覆盖这张图。
    $shotDirFor = $attach ? Web::shotDirFor((string) $evidenceDir, $source->sessionId()) : null;

    $web = new Web($source->queries(), $label, $thresholds, __DIR__ . '/Debug/View/web', $control, $selection, $evidenceDir, $source->sessionId());
    $server = new HttpServer($host, $port);

    \fwrite(\STDERR, \sprintf(
        "Web 视图：http://%s:%d/\n读的是 %s%s\n画面：%s\nCtrl-C 结束\n",
        $host === '0.0.0.0' ? '127.0.0.1' : $host,
        $server->port(),
        $label,
        $control === null ? '（只读：没有驱动面）' : '（可暂停 / 单步）',
        $shotDirFor ?? '无（这个会话没有画面通道）',
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
