<?php
declare(strict_types=1);

/**
 * CI 侧读 `gate --json` 报告，钉住 gate 自己不会替你拒绝的两件事。
 *
 * ① 报告必须是一份合法、完整的 JSON。2026-11-12 CI 上 `gate --fast --json` 因
 *    失败项的 stdout 含 GBK 字节，在 `CheckReport::json()` 抛 JsonException，
 *    stdout 上一片空白 —— 只剩一个 255，连「哪条判据红」都看不见。
 * ② 指定的判据必须 `status === ok`。`gate` 的 `$may_skip` 允许 unit / feature
 *    在 PHPUnit 缺失时记 `skip`，而 skip 不算 fail ⇒ 整份报告 `ok: true` ⇒ CI 绿。
 *    「没测到」又一次被当成「通过」。判据走独立路径：这里直接读报告里的
 *    `items[].status`，不复用 gate 给出的 `$ok` 结论。
 *
 * 用法：php .github/scripts/assert-gate-report.php <报告.json> [判据 id ...]
 *   不给 id ⇒ 只验 ① 与报告 `ok`（deps-build 的自托管 runner 不保证有 composer）；
 *   给了 id ⇒ 逐项要求 `status === ok`，缺项 / skip / fail 一律红。
 *
 * 参考上游 swoole/typephp：CI 里 PHPUnit 是独立硬判据（`vendor/bin/phpunit`，
 * 缺了命令本身就红），不藏在允许 skip 的聚合报告里。本仓保留 gate 作为唯一
 * 账本，用这份断言补上「skip 不是通过」这一条，而不是把单测再跑一遍。
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
    fail('报告为空 —— gate 很可能在序列化之前就崩了');
}

try {
    $report = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    fail('报告不是合法 JSON：' . $e->getMessage());
}

if (!is_array($report) || !array_key_exists('ok', $report) || !isset($report['items']) || !is_array($report['items'])) {
    fail('报告结构不完整（缺 ok / items）');
}

if ($report['ok'] !== true) {
    fail('gate 报告 ok=false（跑 gate 的步骤本应已红，这里是第二道）');
}

if (($report['encoding']['substituted'] ?? false) === true) {
    $fields = $report['encoding']['nonUtf8Fields'] ?? [];
    fwrite(STDOUT, '  note  报告含非 UTF-8 字段，已按 U+FFFD 替换：' . implode(', ', $fields) . "\n");
}

$byId = [];
foreach ($report['items'] as $item) {
    if (is_array($item) && isset($item['id'])) {
        $byId[$item['id']] = $item['status'] ?? '(无 status)';
    }
}

foreach ($required as $id) {
    if (!array_key_exists($id, $byId)) {
        fail('判据 ' . $id . ' 不在报告里（gate 提前退出 / 判据集漂移）');
    }
    if ($byId[$id] !== 'ok') {
        fail('判据 ' . $id . ' 的 status 是 ' . $byId[$id] . '，不是 ok —— 没测到不许算通过');
    }
}

$suffix = $required === [] ? '(无，只验报告本身)' : implode(', ', $required);
fwrite(STDOUT, '  ok    报告合法；关键判据确实跑到了：' . $suffix . "\n");
