#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-find` —— 在证据里找：给一段文本，回一串**地址**。
 *
 * 搜索与追问是一对：`find` 只回答"哪些地址提到了它"，不回答"为什么会这样"。
 * 拿到 `node:12` 之后去 `debug node --node=12 --frame=N` 看三域对齐，拿到
 * `request:3` 之后去 `debug network --request=3 --bodies` 看报文 —— 地址是
 * 两端之间唯一不会漂的东西（行号、屏幕位置、数组下标都会漂）。
 *
 * 只读查询面（`find`），本地 / `--remote` / `--from=<证据包>` 三条路同形：
 * 搜的是记录，不是活树，所以离线包搜出来的结果与现场跑逐值相同。
 *
 * 不做正则、不切词、不打相关度：三条都会让"同一次搜索"在不同实现下给出不同
 * 结果，而搜索的结论要能被复算。`Table|Column` 按字面找 —— 找不到就是找不到。
 *
 * 用法：
 *   php devtools/bin/debug-find.php --page=table --frames=1 --pattern=Table
 *   php devtools/bin/debug-find.php --page=async-table --rows=3 --resolve --frames=2 --pattern=rows --domain=request
 *   php devtools/bin/debug-find.php --page=table --frames=1 --pattern=table --case-sensitive --limit=5
 *   php devtools/bin/debug-find.php --from=case.evidence --pattern=Table --json
 *   php devtools/bin/debug-find.php --page=table --frames=1 --pattern=Table --remote
 *
 * 参数：
 *   --pattern=<文本>   必填：字面子串
 *   --domain=all|node|request  搜哪个域（默认 all）
 *   --limit=N          最多给多少条命中（默认 50，命中总数照实报）
 *   --case-sensitive   区分大小写（默认不区分）
 *   --from=<文件>      不跑会话，直接读证据包
 *   --remote           用宿主进程跑
 *   --json             结构化输出
 *
 * @internal 未进 VerifyPlan：搜索的域集合还会扩（属性 / 绘制），先让形状站住
 */

require __DIR__ . '/../autoload.php';

use Drift\DevTools\Session\SessionQueries;
use Drift\DevTools\Cli\Commands;
use Drift\DevTools\Host\Source;
use Drift\DevTools\View\Find;

$args = Commands::parseOrExit('find', $argv);
$from = $args->str('from');
$remote = $args->bool('remote');
$json = $args->bool('json');
$pattern = $args->str('pattern');
$domain = $args->str('domain') ?? 'all';
$limit = $args->int('limit');
$caseSensitive = $args->bool('case-sensitive');
$label = $args->label();
$attach = $args->bool('attach');
$sessionId = $args->str('session');
try {
    $attach = Source::autoAttach($attach, $from, $label, $sessionId);
} catch (\RuntimeException $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
}

if ($pattern === null || \trim($pattern) === '') {
    \fwrite(\STDERR, "用法：debug-find <AppClass|路由id> --pattern=<文本> [--domain=all|node|request] [--limit=N] [--json]\n");
    \fwrite(\STDERR, "      debug-find --from=<证据包> --pattern=<文本> [--json]\n");
    exit(2);
}

/** @var SessionQueries|null $session */
$session = null;
$source = null;
try {
    $source = Source::resolve(
        from: $from,
        attach: $attach,
        session: $sessionId,
        remote: $remote,
        drive: $from === null && !$attach ? $args->script() : null,
        at: $args->at(),
        out: null,
        recordOptions: $args->recordOptions(),
    );
    $label = $source->label();
    $session = $source->queries();

    $answer = $session->query('find', [
        'pattern' => $pattern,
        'domain' => $domain,
        'limit' => $limit,
        'caseSensitive' => $caseSensitive,
    ]);

    if ($json) {
        \fwrite(\STDOUT, (string) \json_encode(
            ['label' => $label] + $answer,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
        ) . "\n");
    } else {
        foreach (Find::lines($answer) as $line) {
            \fwrite(\STDOUT, $line . "\n");
        }
    }
} catch (\Throwable $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
} finally {
    $source?->close();
}
