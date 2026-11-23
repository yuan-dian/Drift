<?php
declare(strict_types=1);

/**
 * 示例 / 判据集合封闭性对账（V2 `sceneset` step，`verify --list` 可见）。
 *
 * ★ 守的是 `DESIGN-EXAMPLES-TESTING-TARGET-2026-11-13.md` 的 G2：
 *   冻结清单 ↔ 生成清单 ↔ 磁盘页面 ↔ golden 整页基线 ↔ AOT 页清单，双向封闭。
 * ★ 为什么单独成项：原来集合由 `glob('apps/...')` 隐式决定，`golden --check`
 *   只判「场景有没有基线」⇒ 删一页、旧基线成孤儿，gate 静默全绿。
 * ★ 与 PHPUnit 不同源、零框架依赖：只读五份集合，不渲染、不 require autoload。
 * ★ `Bench/ShowcasePages.php` 被直接 require：只调 `names()`，其 `use` 与延迟闭包都不触发
 *   装载。⇒ 那份清单必须保持「不装 autoload 也能被加载」，见它自己的文件头。
 *
 * 用法：
 *   php tools/scene-set-check.php             # 对账，exit 0/1
 *   php tools/scene-set-check.php --selftest  # 合成变异自证（不碰磁盘）
 *   php tools/scene-set-check.php --json      # 机器可读
 */

require __DIR__ . '/Support/SceneSet.php';
require __DIR__ . '/Golden/PageScenes.php';
require __DIR__ . '/Bench/ShowcasePages.php';

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

/** @return list<string> */
function scene_manifest_names(string $root): array
{
    $path = $root . '/apps/showcase/src/Routes/RouteManifest.php';
    if (!\is_file($path)) {
        return [];
    }
    $src = (string) \file_get_contents($path);
    \preg_match_all('/Pages\\\\([A-Z]\w*)::class/', $src, $m);
    $out = $m[1];
    \sort($out);

    return $out;
}

/**
 * 五集合对账。返回问题码 + 两组 diff，自证按问题码精确比对。
 *
 * @param list<string> $declared
 * @param list<string> $disk
 * @param list<string> $baseline
 * @param list<string>|null $bench AOT 页清单；null = 不检查（合成自证用）
 * @param list<string>|null $manifest 生成清单；null = 不检查（合成自证用）
 * @return array{problems: list<array{code:string,detail:string}>, disk: array{missing:list<string>,orphan:list<string>}, baseline: array{missing:list<string>,orphan:list<string>}}
 */
function scene_reconcile(array $declared, array $disk, array $baseline, ?array $bench = null, ?array $manifest = null): array
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

    // 两份手写清单的对价：AOT 的页清单必须被整页 golden 的全集覆盖，
    // 否则两份清单可以同时漂而这里全绿。
    if ($bench !== null) {
        if ($bench === []) {
            $add('empty:bench', 'AOT 页清单为空（判据资产可能被整体删掉）');
        } else {
            $outside = \array_values(\array_diff($bench, $declared));
            \sort($outside);
            if ($outside !== []) {
                $add('bench:not-in-golden', 'AOT 页清单里有整页清单没有的页：' . \implode(', ', $outside));
            }
        }
    }

    if ($manifest !== null) {
        if ($manifest === []) {
            $add('empty:manifest', '生成清单为空（生成器没跑或被整体删掉）');
        } else {
            $diff = SceneSet::compare($disk, $manifest);
            if ($diff['missing'] !== []) {
                $add('manifest:missing', '磁盘有页但生成清单没有（漏跑生成器）：' . \implode(', ', $diff['missing']));
            }
            if ($diff['orphan'] !== []) {
                $add('manifest:orphan', '生成清单有页但磁盘没有（删页未重生成）：' . \implode(', ', $diff['orphan']));
            }
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
        ['AOT 清单是整页清单子集 ⇒ 绿', [], scene_reconcile($base, $base, $base, ['APage', 'CPage'])],
        ['AOT 清单多一项 ⇒ 红', ['bench:not-in-golden'], scene_reconcile($base, $base, $base, ['APage', 'GhostPage'])],
        ['AOT 清单为空 ⇒ 红', ['empty:bench'], scene_reconcile($base, $base, $base, [])],
        ['生成清单与磁盘一致 ⇒ 绿', [], scene_reconcile($base, $base, $base, null, $base)],
        ['磁盘新页未进生成清单 ⇒ 红', ['manifest:missing'],
            scene_reconcile($base, $base, $base, null, ['APage', 'BPage'])],
        ['生成清单有删页残留 ⇒ 红', ['manifest:orphan'],
            scene_reconcile($base, $base, $base, null, [...$base, 'GhostPage'])],
        ['生成清单为空 ⇒ 红', ['empty:manifest'], scene_reconcile($base, $base, $base, null, [])],
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
$bench = \Drift\Dev\Bench\ShowcasePages::names();
$manifest = scene_manifest_names($root);
$result = scene_reconcile($declared, $disk, $baseline, $bench, $manifest);

if ($json) {
    echo \json_encode([
        'ok' => $result['problems'] === [],
        'declared' => \count($declared),
        'disk' => \count($disk),
        'baseline' => \count($baseline),
        'bench' => \count($bench),
        'manifest' => \count($manifest),
        'problems' => $result['problems'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    exit($result['problems'] === [] ? 0 : 1);
}

\printf(
    "[scene-set-check] 冻结 %d / 生成 %d / 磁盘 %d / 基线 %d / AOT %d\n",
    \count($declared),
    \count($manifest),
    \count($disk),
    \count($baseline),
    \count($bench),
);
foreach ($result['problems'] as $p) {
    \fwrite(STDERR, '  - ' . $p['code'] . '：' . $p['detail'] . "\n");
}
if ($result['problems'] !== []) {
    \fwrite(STDERR, "[scene-set-check] " . \count($result['problems']) . " 处集合不封闭\n");
    exit(1);
}
echo "[scene-set-check] ✓ 五集合封闭\n";
exit(0);
