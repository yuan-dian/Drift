<?php
declare(strict_types=1);

/**
 * 示例 / 判据集合封闭性对账（gate 的 `tooling` 组 `sceneset` 项）。
 *
 * ★ 守的是 `DESIGN-EXAMPLES-TESTING-TARGET-2026-11-13.md` 的 G2：
 *   冻结清单 ↔ 磁盘页面 ↔ golden 整页基线，三向双向校验，少一个多一个都红。
 * ★ 为什么单独成项：原来集合由 `glob('apps/...')` 隐式决定，`golden --check`
 *   只判「场景有没有基线」⇒ 删一页、旧基线成孤儿，gate 静默全绿。
 * ★ 与 PHPUnit 不同源、零框架依赖：只读两个集合 + 一份冻结清单，不渲染、不 require autoload。
 *
 * 用法：
 *   php tools/scene-set-check.php             # 对账，exit 0/1
 *   php tools/scene-set-check.php --selftest  # 合成变异自证（不碰磁盘）
 *   php tools/scene-set-check.php --json      # 机器可读
 */

require __DIR__ . '/Support/SceneSet.php';
require __DIR__ . '/Golden/PageScenes.php';

use Drift\Dev\Golden\PageScenes;
use Drift\Dev\Support\SceneSet;

/**
 * 从路径列表取名字。纯函数，给 --selftest 用。
 *
 * @param list<string> $paths
 * @return list<string>
 */
function scene_stems(array $paths, string $suffix): array
{
    $out = [];
    foreach ($paths as $p) {
        $p = \str_replace('\\', '/', \rtrim($p, '/\\'));
        $name = \basename($p);
        if (\str_starts_with($name, '.') || ($suffix !== '' && !\str_ends_with($name, $suffix))) {
            continue;
        }
        $out[] = $suffix === '' ? $name : \substr($name, 0, -\strlen($suffix));
    }
    $out = \array_values(\array_unique($out));
    \sort($out);

    return $out;
}

/** @return list<string> */
function scene_disk_names(string $dir): array
{
    return scene_stems(\glob($dir . '/*.php') ?: [], '.php');
}

/** @return list<string> */
function scene_baseline_names(string $dir): array
{
    return scene_stems(\glob($dir . '/*', GLOB_ONLYDIR) ?: [], '');
}

/**
 * 三向对账。返回问题码 + 两组 diff，自证按问题码精确比对。
 *
 * @param list<string> $declared
 * @param list<string> $disk
 * @param list<string> $baseline
 * @return array{problems: list<array{code:string,detail:string}>, disk: array{missing:list<string>,orphan:list<string>}, baseline: array{missing:list<string>,orphan:list<string>}}
 */
function scene_reconcile(array $declared, array $disk, array $baseline): array
{
    $problems = [];
    $add = static function (string $code, string $detail) use (&$problems): void {
        $problems[] = ['code' => $code, 'detail' => $detail];
    };

    // 空集合哨兵：删整个 app 时三个集合一起归零，双向 diff 会全绿。
    foreach (['declared' => $declared, 'disk' => $disk, 'baseline' => $baseline] as $kind => $set) {
        if ($set === []) {
            $add('empty:' . $kind, $kind . ' 集合为空（判据资产可能被整体删掉）');
        }
    }

    if (\count($declared) !== \count(\array_unique($declared))) {
        $add('duplicate:declared', '冻结清单有重复项');
    }
    $sorted = $declared;
    \sort($sorted);
    if ($declared !== $sorted) {
        $add('unsorted:declared', '冻结清单不是字典序（手写清单最容易漂的形状）');
    }

    $a = SceneSet::compare($declared, $disk);
    $b = SceneSet::compare($declared, $baseline);
    if ($a['missing'] !== []) {
        $add('disk:missing', '清单有、磁盘无：' . \implode(', ', $a['missing']));
    }
    if ($a['orphan'] !== []) {
        $add('disk:orphan', '磁盘有、清单无（新页未登记）：' . \implode(', ', $a['orphan']));
    }
    if ($b['missing'] !== []) {
        $add('baseline:missing', '清单有、基线无：' . \implode(', ', $b['missing']));
    }
    if ($b['orphan'] !== []) {
        $add('baseline:orphan', '基线有、清单无（孤儿基线 / 删页遗留）：' . \implode(', ', $b['orphan']));
    }

    return ['problems' => $problems, 'disk' => $a, 'baseline' => $b];
}

/** @return list<string> */
function scene_codes(array $result): array
{
    return \array_column($result['problems'], 'code');
}

function scene_usage(): never
{
    \fwrite(STDERR, "用法：php tools/scene-set-check.php [--selftest] [--json]\n");
    exit(2);
}

$args = \array_slice($argv, 1);
foreach ($args as $a) {
    if (!\in_array($a, ['--selftest', '--json'], true)) {
        scene_usage();
    }
}
$selftest = \in_array('--selftest', $args, true);
$json = \in_array('--json', $args, true);

if ($selftest) {
    $base = ['APage', 'BPage', 'CPage'];
    $cases = [
        ['三向一致 ⇒ 绿', [], scene_reconcile($base, $base, $base)],
        ['删一个磁盘页 ⇒ 红', ['disk:missing'], scene_reconcile($base, ['APage', 'BPage'], $base)],
        ['加一个未登记页 ⇒ 红', ['disk:orphan'], scene_reconcile($base, [...$base, 'DPage'], $base)],
        ['删一张基线 ⇒ 红', ['baseline:missing'], scene_reconcile($base, $base, ['APage', 'BPage'])],
        ['加一张孤儿基线 ⇒ 红', ['baseline:orphan'], scene_reconcile($base, $base, [...$base, 'GhostPage'])],
        ['清单漏登记一项 ⇒ 两边都多', ['disk:orphan', 'baseline:orphan'],
            scene_reconcile(['APage', 'BPage'], $base, $base)],
        ['三个集合全空 ⇒ 不许空过', ['empty:declared', 'empty:disk', 'empty:baseline'],
            scene_reconcile([], [], [])],
        ['清单重复 ⇒ 红', ['duplicate:declared'], scene_reconcile(['APage', 'APage', 'BPage'], ['APage', 'APage', 'BPage'], ['APage', 'APage', 'BPage'])],
        ['清单乱序 ⇒ 红', ['unsorted:declared'], scene_reconcile(['BPage', 'APage'], ['BPage', 'APage'], ['BPage', 'APage'])],
        ['磁盘/基线顺序不同 ⇒ 不误报', [], scene_reconcile($base, ['CPage', 'APage', 'BPage'], ['BPage', 'CPage', 'APage'])],
    ];

    // 纯函数与扫描器各补一条独立路径，避免「聚合层自己判自己」。
    $pure = [
        ['compare 双向独立', ['missing' => ['APage'], 'orphan' => ['DPage']],
            SceneSet::compare(['APage', 'BPage'], ['BPage', 'DPage'])],
        ['compare 不受重复值影响', ['missing' => [], 'orphan' => []],
            SceneSet::compare(['APage', 'APage'], ['APage'])],
        ['stems 只收 .php', ['APage', 'BPage'],
            scene_stems(['x/APage.php', 'x/BPage.php', 'x/note.txt', 'x/.hidden.php'], '.php')],
        ['stems 目录名去重排序', ['APage', 'BPage'],
            scene_stems(['g/BPage/', 'g/APage', 'g/APage/'], '')],
    ];

    $failures = 0;
    echo "[scene-set-check --selftest] " . (\count($cases) + \count($pure)) . " 条合成变异\n";
    foreach ($cases as [$name, $want, $got]) {
        $ok = scene_codes($got) === $want;
        echo ($ok ? '  ok   ' : '  FAIL ') . $name . "\n";
        if (!$ok) {
            $failures++;
            \fwrite(STDERR, '    实得 ' . \json_encode(scene_codes($got)) . ' / 期望 ' . \json_encode($want) . "\n");
        }
    }
    foreach ($pure as [$name, $want, $got]) {
        $ok = $got === $want;
        echo ($ok ? '  ok   ' : '  FAIL ') . $name . "\n";
        if (!$ok) {
            $failures++;
            \fwrite(STDERR, '    实得 ' . \json_encode($got) . ' / 期望 ' . \json_encode($want) . "\n");
        }
    }
    echo $failures === 0
        ? "[scene-set-check --selftest] 全部咬合\n"
        : "[scene-set-check --selftest] {$failures} 条红\n";
    exit($failures === 0 ? 0 : 1);
}

$root = \dirname(__DIR__);
$declared = PageScenes::names();
$disk = scene_disk_names($root . '/apps/showcase/src/Pages');
$baseline = scene_baseline_names($root . '/golden/page');
$result = scene_reconcile($declared, $disk, $baseline);

if ($json) {
    echo \json_encode([
        'ok' => $result['problems'] === [],
        'declared' => \count($declared),
        'disk' => \count($disk),
        'baseline' => \count($baseline),
        'problems' => $result['problems'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($result['problems'] === [] ? 0 : 1);
}

\printf("[scene-set-check] 清单 %d / 磁盘 %d / 基线 %d\n", \count($declared), \count($disk), \count($baseline));
foreach ($result['problems'] as $p) {
    \fwrite(STDERR, '  - ' . $p['code'] . '：' . $p['detail'] . "\n");
}
if ($result['problems'] !== []) {
    \fwrite(STDERR, "[scene-set-check] " . \count($result['problems']) . " 处集合不封闭\n");
    exit(1);
}
echo "[scene-set-check] ✓ 三向集合封闭\n";
exit(0);
