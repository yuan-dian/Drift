<?php
declare(strict_types=1);

/**
 * CI 侧读 `bin/drift verify --json` 报告，独立复核 runner 自己给出的结论。
 *
 * ① 报告必须是一份合法、完整的 JSON。2026-11-12 CI 上 V1 gate 的 `--json` 因
 *    失败项 stdout 含 GBK 字节在 `CheckReport::json()` 抛 JsonException，
 *    stdout 一片空白 —— 只剩一个 255，连「哪条判据红」都看不见。V2 runner
 *    已改用 `JSON_INVALID_UTF8_SUBSTITUTE`，断言这里仍独立验一次序列化没崩。
 * ② 指定的 step 必须 `status === ok`。V2 把「进程起不来 / 依赖缺失」记成
 *    `exitCode 127`、顶层 `ok=false`，不像 V1 gate 那样把 skip 算通过；
 *    这份断言再走**独立路径**读 `items[].status`，不复用 runner 的 `$ok`。
 * ③ 分片 / 裁剪结果不能当验收报告（`partial=true` 一律红）。
 *
 * 用法：php .github/scripts/assert-verify-report.php <报告.json> [step id ...]
 *   不给 id ⇒ 只验 ①②③ 与报告 ok（deps-build 只跑 static 层，产物是依赖包）；
 *   给了 id ⇒ 逐项要求 `status === ok`，缺项 / fail 一律红。
 *
 * 参考上游 swoole/typephp：CI 里 PHPUnit 是独立硬判据（`vendor/bin/phpunit`，
 * 缺了命令本身就红），不藏在允许 skip 的聚合报告里。本仓用这份断言补上
 * 「没测到不许算通过」，而不是把单测再跑一遍。
 */

function fail(string $msg): never
{
    fwrite(STDERR, '  FAIL  ' . $msg . "\n");
    exit(1);
}

$file = $argv[1] ?? '';
$required = array_slice($argv, 2);

if ($file === '' || !is_file($file)) {
    fail('报告文件不存在：' . ($file === '' ? '(未给参数)' : $file));
}

$raw = file_get_contents($file);
if ($raw === false || trim($raw) === '') {
    fail('报告为空 —— runner 很可能在序列化之前就崩了');
}

try {
    $report = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fail('报告不是合法 JSON：' . $e->getMessage());
}

if (!is_array($report) || !array_key_exists('ok', $report) || !isset($report['items']) || !is_array($report['items'])) {
    fail('报告结构不完整（缺 ok / items）');
}

if (($report['partial'] ?? false) === true) {
    fail('这是分片 / 裁剪结果，不能当验收报告');
}

if ($report['ok'] !== true) {
    fail('verify 报告 ok=false（跑 verify 的步骤本应已红，这里是第二道）');
}

if (($report['exitCode'] ?? 0) !== 0) {
    fail('verify 报告 exitCode=' . $report['exitCode'] . '（不是 0，不许算通过）');
}

$byId = [];
foreach ($report['items'] as $item) {
    if (is_array($item) && isset($item['id'])) {
        $byId[$item['id']] = $item['status'] ?? '(无 status)';
    }
}

foreach ($required as $id) {
    if (!array_key_exists($id, $byId)) {
        fail('step ' . $id . ' 不在报告里（runner 提前退出 / 判据集漂移）');
    }
    if ($byId[$id] !== 'ok') {
        fail('step ' . $id . ' 的 status 是 ' . $byId[$id] . '，不是 ok —— 没测到不许算通过');
    }
}

$suffix = $required === [] ? '(无，只验报告本身)' : implode(', ', $required);
fwrite(STDOUT, '  ok    报告合法；关键 step 确实跑到了：' . $suffix . "\n");
