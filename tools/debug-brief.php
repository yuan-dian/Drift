#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-brief` —— 证据层的第一个可用出口：跑一段页面，把它压成一份预算内的简报。
 *
 * 它存在的目的不是"多一个命令"，是验证证据模型里那条假设：
 * **现有查询面的事实足以自动指出"哪里不对"，不需要任何新采集。**
 * 如果这份简报读起来像"有人已经看过一遍"，假设成立；如果它只能复述数字，
 * 说明缺的不是投影层，是采集层 —— 那要在写 S1 之前就知道。
 *
 * 输入全部来自查询面（没有一行直接摸 `Recorder`），所以本地会话与 `--remote`
 * 走的是同一条路：这正是"消费者平权"的第一个可执行证据。
 *
 * 用法：
 *   php tools/debug-brief.php --page=table --frames=1 --click=400,300
 *   php tools/debug-brief.php --page=table --frames=1 --click=400,300 --json
 *   php tools/debug-brief.php --page=async-table --rows=6 --frames=2 --resolve --hold=3
 *   php tools/debug-brief.php --page=table --frames=1 --remote
 *
 * 参数：
 *   --page=<路由id>   页面（与面板同义）
 *   --frames=N        驱动几帧（默认 1）
 *   --rows=N          取数替身参数（异步页需要）
 *   --click=x,y       点一下（可重复）
 *   --key= / --text= / --wheel= / --resolve / --hold=N  与面板同一套驱动词表
 *   --at=N            只重放到第 N 帧
 *   --remote          用宿主进程跑（验证跨进程读到同一份证据）
 *   --out=<文件>      把这次会话导出成证据包（NDJSON，首行 manifest）
 *   --from=<文件>     不跑会话，直接读证据包复算（与 --page / 驱动参数互斥）
 *   --attach          接一个已经在跑的会话（`drift debug serve` 起的），只读不驱动
 *   --session=<id>    同一目录有多个会话时点名；不给且不止一个就报错列出候选
 *   --budget=N        行预算（默认 40）
 *   --width=N / --view-width=N  视口宽度（默认 800；十个出口只有一个意思）
 *   --text-width=N    排版宽度（默认 100；与视口宽度是两件事）
 *   --height=N        视口高度（默认 600）
 *   --slow=<ms>       慢帧阈值（默认 16.7；表头显示的三项都可配）
 *   --fanout=<节点数> 扇出阈值（默认 100）
 *   --repeat=<百分比> 重复重建阈值（默认 50）
 *   --json            输出结构化结果（发现 + 裁剪账 + 指纹；不含排版行）
 *
 * @internal 未进 VerifyPlan：规则阈值还在定，进 CI 会变成"改阈值就红一片"
 */

require __DIR__ . '/autoload.php';

use Drift\Debug\Session\Thresholds;
use Drift\Dev\Debug\Cli\Commands;
use Drift\Dev\Debug\Source;
use Drift\Dev\Debug\View\Brief;
use Drift\Dev\Debug\View\BriefText;
use Drift\Dev\Debug\View\Facts;
use Drift\Dev\Debug\View\Web;

$args = Commands::parseOrExit('brief', $argv);
$from = $args->str('from');
$out = $args->str('out');
$attach = $args->bool('attach');
$sessionId = $args->str('session');
$at = $args->at();
$remote = $args->bool('remote');
$json = $args->bool('json');
$budget = $args->int('budget');
$textWidth = $args->int('text-width');
// 只开放表头显示的那三项：表头写什么，就该能配什么。
$thresholds = Thresholds::default()
    ->with(['slowMs' => $args->float('slow')])
    ->with(['fanoutNodes' => $args->int('fanout')])
    ->with(['repeatPercent' => $args->int('repeat')]);
$label = $args->label();

$source = null;
try {
    $drive = null;
    if ($from === null && !$attach) {
        if ($label === null) {
            \fwrite(\STDERR, "用法：debug-brief <AppClass|路由id> [--page=<路由id>] [--frames=N] [--json]\n");
            \fwrite(\STDERR, "      debug-brief --from=<证据包> [--json]\n");
            \fwrite(\STDERR, "      debug-brief --attach [--session=<id>] [--json]\n");
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
        out: $out,
        session: $sessionId,
    );
    $label = $source->label();

    $facts = Facts::gather($source->queries(), $label);

    // 导出放在取事实之后：几何是帧末采的，早于最后一帧导出会少一段。
    $source->export();

    $projection = Brief::project($facts, $thresholds);
    $brief = BriefText::render($facts, $projection, $budget, $textWidth);

    if ($json) {
        // 与 `debug web` 的 `/api/brief` 是**同一份**：投影 + 裁剪，不含排版行。
        // 把它做成同一个函数（见 Web::brief()）而不是两处拼装，是 B4 §9 判据 1 的前提。
        \fwrite(\STDOUT, (string) \json_encode(
            Web::brief($label, $facts, $projection) + ['omitted' => $brief['omitted']],
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
        ) . "\n");
    } else {
        foreach ($brief['lines'] as $line) {
            \fwrite(\STDOUT, $line . "\n");
        }
    }
} catch (\Throwable $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
} finally {
    $source?->close();
}
