#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-tree-diff` —— 两帧之间的结构事件。
 *
 * 它回答"从 A 帧之后到 B 帧（含）发生了什么"：谁出生、谁离开、谁在中间重建。
 * 区间是左开右闭，所以 `diff(2,4)` 与 `diff(4,6)` 拼起来正好是 `diff(2,6)`。
 *
 * 事实取自生命周期两端（`f` / `g`）与帧记录的重建集合，不是"两次 tree() 相减"：
 * 后者拿到的只是两份被 depth / limit 截断的投影，相减会把截断当成"没变化"。
 *
 * 没有逐帧的父 / 类型快照，所以移位与改型不报——报了就是拿现在冒充当时。
 * 对比的完整性与缺口写在第二行；空结果配上"不完整"不等于"没发生"。
 *
 * 用法：
 *   php tools/debug-tree-diff.php --page=async-table --base=2 --frame=6
 *   php tools/debug-tree-diff.php --page=async-table --base=2 --limit=0
 *   php tools/debug-tree-diff.php --from=case.evidence --base=10 --frame=20 --json
 *
 * 参数：
 *   --base=N       起点帧（不含；默认 0 = 从录制开始）
 *   --frame=N      终点帧（含；默认最新帧）
 *   --limit=N      最多列多少条事件（默认 200，0=不截断）
 */

require __DIR__ . '/autoload.php';

use Drift\DevTools\Session\SessionQueries;
use Drift\Dev\Debug\Cli\Commands;
use Drift\Dev\Debug\Source;
use Drift\Dev\Debug\View\TreeDiff;

$args = Commands::parseOrExit('tree-diff', $argv);
$from = $args->str('from');
$out = $args->str('out');
$at = $args->at();
$remote = $args->bool('remote');
$json = $args->bool('json');
$base = $args->int('base');
$frame = $args->has('frame') ? $args->int('frame') : null;
$limit = $args->int('limit');
$label = $args->label();
$attach = $args->bool('attach');
$sessionId = $args->str('session');
if ($label === null && $from === null && !$attach) {
    \fwrite(\STDERR, "用法：debug-tree-diff <AppClass|路由id> [--page=<路由id>] [--base=N] [--frame=N]\n");
    \fwrite(\STDERR, "      debug-tree-diff --from=<证据包> --base=N [--json]\n");
    exit(2);
}
try {
    $attach = Source::autoAttach($attach, $from, $label, $sessionId);
} catch (\RuntimeException $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
}

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

    $source->export();

    $answer = $session->query('treeDiff', [
        'base' => $base,
        'frame' => $frame,
        'limit' => $limit,
    ]);
    if ($json) {
        \fwrite(\STDOUT, (string) \json_encode(
            ['label' => $label] + $answer,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
        ) . "\n");
    } else {
        foreach (TreeDiff::render($answer) as $line) {
            \fwrite(\STDOUT, $line . "\n");
        }
    }
} catch (\Throwable $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
} finally {
    $source?->close();
}
