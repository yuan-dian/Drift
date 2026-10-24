<?php
declare(strict_types=1);

/**
 * drift-docref —— 文档引用闸门（ARCH-REVIEW-2026-10-24 §7 M2）。
 *
 * 用法：
 *   php tools/docref.php            全量检查（有 error ⇒ exit 1）
 *   php tools/docref.php --list     打印检查清单
 *   php tools/docref.php --selftest 量具双向自检（§20 纪律 1：该响会响 + 不该响不误响）
 *
 * ★ 与 lint 的关系：**互补不重叠**。lint 查代码（19 条规则），docref 查文档
 *   （3 条检查）。`Diagnostic` 复用 lint 的 —— 字段形状通用（file=文档路径）。
 * ★ 与 `tools/docref.php` 建议的关系：ARCH-REVIEW 原文建议"写一个 docref.php"，
 *   这里按 lint 的同构结构落成"主脚本 + Checks 目录"，为将来自动化更多文档
 *   检查（死链已含、册间重复、行数断言漂移）留出插槽，每条自带反例自检。
 */

use Drift\Dev\DocRef\Checks\FileLineRefs;
use Drift\Dev\DocRef\Checks\MarkdownLinks;
use Drift\Dev\DocRef\Checks\SectionRefs;
use Drift\Dev\DocRef\DocSet;

require __DIR__ . '/autoload.php';

$root = dirname(__DIR__);
$args = array_slice($argv, 1);

/** @var list<\Drift\Dev\DocRef\Check> */
$checks = [
    new FileLineRefs(),
    new SectionRefs(),
    new MarkdownLinks(),
];

if (in_array('--list', $args, true)) {
    foreach ($checks as $c) {
        printf("%-18s %s\n", $c->name(), $c->summary());
    }
    exit(0);
}

if (in_array('--selftest', $args, true)) {
    $failed = 0;
    foreach ($checks as $c) {
        try {
            $c->selftest();
            fwrite(STDOUT, "  ok    {$c->name()} 量具双向自检通过\n");
        } catch (\Throwable $e) {
            ++$failed;
            fwrite(STDOUT, "  FAIL  {$c->name()} —— {$e->getMessage()}\n");
        }
    }
    fwrite(STDOUT, \sprintf("\n--- %d ok / %d FAIL ---\n", \count($checks) - $failed, $failed));
    exit($failed === 0 ? 0 : 1);
}

// ── 全量检查 ──
$docs = new DocSet($root);
fwrite(STDOUT, 'drift-docref ｜ 文档 ' . \count($docs->docFiles()) . " 个 ｜ " . \count($checks) . " 条检查\n\n");

$all = [];
foreach ($checks as $c) {
    $found = $c->run($docs);
    $n = \count($found);
    fwrite(STDOUT, \sprintf("%-18s %d 处%s\n", $c->name(), $n, $n === 0 ? '  ✓' : ''));
    foreach ($found as $d) {
        fwrite(STDOUT, '    ' . $d->format() . "\n");
    }
    $all = array_merge($all, $found);
}

$errors = array_filter($all, static fn($d): bool => $d->isError());
fwrite(STDOUT, \sprintf("\n--- %d ok / %d FAIL ---\n", \count($all) - \count($errors), \count($errors)));
exit($errors === [] ? 0 : 1);
