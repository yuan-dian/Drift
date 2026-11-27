#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-probe` —— dev 壳的轻量内省出口：进程内存 / 源码位置（A55 L2，不是证据）。
 *
 * ## 为什么是一个出口两个视图，不是两个出口
 *
 * 内存与源码位置都是 A55 说的"交给 PHP 原语"：前者 `gc_status()` + `memory_get_usage()`
 * + WeakMap 台账，后者 `ReflectionMethod::getFileName()`。它们共用一套"当场内省、
 * 不进包、不进指纹"的纪律，合成一个出口比多一个出口少一份注册表 / 手册 / 帮助面。
 * 真正的万能内省仍是 `debug eval`；这里是两个高频问题的结构化快捷方式。
 *
 * ## 报文为什么不在这里
 *
 * 设计稿原写"用 stream_wrapper 旁路抓报文、AsyncHttp 不改"。实测 `AsyncHttp` 走
 * curl（`CurlMultiTransport` / `Http.php`），curl 不经过 PHP stream 层，wrapper 抓不到
 * 任何一条真实请求 —— 那是假实现。报文原文已由 `debug network --request=N`（`bodies`
 * 开关 + `NetworkBodyRecord`）覆盖，这里不重复造一条抓不到东西的通路。
 *
 * 用法：
 *   php tools/debug-probe.php --page=table --frames=5 --what=memory
 *   php tools/debug-probe.php --page=table --frames=5 --what=source --source='apps/showcase/.../TablePage.php:142'
 *   php tools/debug-probe.php --page=table --frames=5 --what=memory --json
 *
 * 参数：
 *   --what=memory|source  看哪个视图（默认 memory）
 *   --source=SITE         source 视图的调用点字符串（形如 file:line，可由 debug report 的 site 取得）
 *   --limit=N             memory 视图最多列几个类型（默认 40）
 *   其余驱动参数同 debug-props；--json 结构化输出
 *
 * @internal 手工工具；不进 V2 判据清单
 */

require __DIR__ . '/autoload.php';

use Drift\DevTools\App\Replay;
use Drift\Dev\Debug\Cli\Commands;
use Drift\Dev\Debug\Runner;
use Drift\Dev\Debug\View\Probe;

$args = Commands::parseOrExit('probe', $argv);
$width = $args->float('view-width');
$height = $args->float('height');
$meta = $args->meta();
$what = $args->str('what') ?? 'memory';
$source = $args->str('source');
$limit = $args->int('limit');
$at = $args->at();
$json = $args->bool('json');
$label = $args->label();
if (!\in_array($what, ['memory', 'source'], true) || $label === null) {
    \fwrite(\STDERR, "用法：debug-probe <AppClass|路由id> [--what=memory|source] [--source=file:line] [--json]\n");
    exit(2);
}
if ($what === 'source' && ($source === null || \trim($source) === '')) {
    \fwrite(\STDERR, "source 视图需要 --source=SITE（形如 file:line）\n");
    exit(2);
}

$drive = $args->script();
$session = null;
try {
    $opened = Runner::open((string) $label, (float) $width, (float) $height, $meta, $drive->fork());
    $session = $opened['session'];
    Replay::apply($session, $drive, [], $at, static function (int $id, float $ms): void {
    });

    $queryArgs = $what === 'memory' ? ['limit' => $limit] : ['site' => (string) $source];
    $answer = $session->query($what, $queryArgs);
    if ($json) {
        \fwrite(\STDOUT, (string) \json_encode(
            ['label' => $label, 'what' => $what] + $answer,
            \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT,
        ) . "\n");
    } else {
        foreach (Probe::lines($what, $answer) as $line) {
            \fwrite(\STDOUT, $line . "\n");
        }
    }
} catch (\Throwable $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
} finally {
    if ($session !== null) {
        $session->close();
    }
}
