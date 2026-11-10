<?php
declare(strict_types=1);

/**
 * `drift-build` 的「门不能信缓存」接线对账（gate 的 `stalepolicy` 项）。
 *
 * ★ 为什么需要：`StalePolicy` 改对了不等于 `drift-build` 用了它 —— 把 `decide()`
 *   调用删掉、改回裸 if，单测仍全绿，构建却悄悄退回「默认信增量」。与 PHPUnit
 *   的分工：那边验「决策表行为对不对」，这边验「接线还在不在」，不同源。
 * ★ 纯 PHP 内建，不 require autoload —— 对账器不该依赖它要检查的那套代码。
 *
 * 用法：
 *   php tools/build-stale-check.php              # 对账，exit 0/1
 *   php tools/build-stale-check.php --selftest   # 对账器自证（合成源码）
 */

/** @return list<string> 缺失的接线要素（空 = 全在） */
function stale_missing(string $src): array
{
    $missing = [];
    $require = [
        'use Drift\Dev\Build\StalePolicy;' => '没接 StalePolicy',
        '$decision = StalePolicy::decide(' => '决策没走纯函数（改回裸 if / 只剩注释？）',
        'StalePolicy::FULL' => '缺 FULL 分支',
        'StalePolicy::SKIP' => '缺 SKIP 短路分支',
        'StalePolicy::INCREMENTAL' => '缺 INCREMENTAL 分支',
        'StalePolicy::label(' => '决策没输出原因（--dry 与日志要共用）',
        "'--incremental'" => '缺 --incremental 开关（默认必须不放行增量）',
    ];
    foreach ($require as $needle => $why) {
        if (!\str_contains($src, $needle)) {
            $missing[] = $why . '：缺 ' . $needle;
        }
    }

    // 函数定义 1 次 + 根缓存 1 次 + app 级缓存 1 次。少一处就是 watermark 那次 stale。
    if (\substr_count($src, 'wipeCache(') < 3) {
        $missing[] = 'FULL 分支没清全缓存（根 + app 级两处 wipeCache 都要有）';
    }

    // --dry 必须在清缓存之前退出，否则 `--dry --full` 会「只算决策」却真清了缓存。
    $dryPos = \strpos($src, 'if ($dry)');
    $fullPos = \strpos($src, 'if ($decision === StalePolicy::FULL)');
    if ($dryPos === false || $fullPos === false || $dryPos > $fullPos) {
        $missing[] = '--dry 没有在 FULL 清缓存之前退出（会误清缓存）';
    }

    // 旧口径的两处特征不许残留：留着就是两套决策并存。
    if (\str_contains($src, '$upToDate')) {
        $missing[] = '旧短路变量 $upToDate 残留（两套决策并存）';
    }
    if (\str_contains($src, '输入已变 ⇒ 增量会重编受影响文件')) {
        $missing[] = '旧「输入变了仍走增量」提示残留（默认口径又退回去了）';
    }

    return $missing;
}

/** 一份「接线齐全」的合成源码，供 --selftest 做正对照 */
function stale_good_source(): string
{
    return \implode("\n", [
        'use Drift\Dev\Build\StalePolicy;',
        "function wipeCache(string \$d): void {}",
        "\$incremental = in_array('--incremental', \$argvRest, true);",
        "\$decision = StalePolicy::decide(\$p, \$c, \$e, \$f, \$i);",
        "fwrite(STDOUT, StalePolicy::label(\$decision));",
        "if (\$dry) { exit(0); }",
        "if (\$decision === StalePolicy::FULL) { wipeCache(\$a); wipeCache(\$b); }",
        "if (\$decision === StalePolicy::INCREMENTAL) { }",
        "if (\$decision === StalePolicy::SKIP) { }",
    ]);
}

$selftest = \in_array('--selftest', \array_slice($argv, 1), true);
$failures = 0;

if ($selftest) {
    $good = stale_good_source();
    $cases = [
        [
            'name' => '接线齐全 ⇒ 绿',
            'got' => stale_missing($good),
            'want' => [],
        ],
        [
            'name' => '删掉 decide() ⇒ 精确报决策没走纯函数',
            'got' => \array_filter(
                stale_missing(\str_replace('$decision = StalePolicy::decide(', '$decision = legacy_decide(', $good)),
                static fn(string $m): bool => \str_contains($m, '决策没走纯函数')
            ),
            'wantCount' => 1,
        ],
        [
            'name' => '注释里提到 decide() 但没有真实调用 ⇒ 仍报缺决策',
            'got' => \array_values(\array_filter(
                stale_missing(\str_replace(
                    '$decision = StalePolicy::decide(',
                    '// `StalePolicy::decide()` 只是注释',
                    $good
                )),
                static fn(string $m): bool => \str_contains($m, '决策没走纯函数')
            )),
            'wantCount' => 1,
        ],
        [
            'name' => '删掉 --incremental ⇒ 报缺开关',
            'got' => \array_values(\array_filter(
                stale_missing(\str_replace("'--incremental'", "'--nope'", $good)),
                static fn(string $m): bool => \str_contains($m, '--incremental')
            )),
            'wantCount' => 1,
        ],
        [
            'name' => '只清根缓存 ⇒ 报没清全',
            'got' => \array_values(\array_filter(
                stale_missing(\str_replace('wipeCache($b);', '', $good)),
                static fn(string $m): bool => \str_contains($m, '没清全缓存')
            )),
            'wantCount' => 1,
        ],
        [
            'name' => '--dry 挪到 FULL 之后 ⇒ 报会误清缓存',
            'got' => \array_values(\array_filter(
                stale_missing(\str_replace(
                    "if (\$dry) { exit(0); }\nif (\$decision === StalePolicy::FULL)",
                    "if (\$decision === StalePolicy::FULL)\n{ /* moved */ }\nif (\$dry) { exit(0); }\nif (\$decision === StalePolicy::FULL)",
                    $good
                )),
                static fn(string $m): bool => \str_contains($m, '误清缓存')
            )),
            'wantCount' => 1,
        ],
        [
            'name' => '旧 $upToDate 残留 ⇒ 报两套决策',
            'got' => \array_values(\array_filter(
                stale_missing($good . "\n\$upToDate = true;"),
                static fn(string $m): bool => \str_contains($m, '两套决策')
            )),
            'wantCount' => 1,
        ],
        [
            'name' => '旧「输入变了仍走增量」提示残留 ⇒ 报口径退回',
            'got' => \array_values(\array_filter(
                stale_missing($good . "\n// 输入已变 ⇒ 增量会重编受影响文件"),
                static fn(string $m): bool => \str_contains($m, '口径又退回去了')
            )),
            'wantCount' => 1,
        ],
    ];

    echo "[build-stale-check --selftest] " . \count($cases) . " 条自洽判据\n";
    foreach ($cases as $case) {
        $got = \array_values((array) $case['got']);
        $ok = isset($case['want'])
            ? $got === $case['want']
            : \count($got) === $case['wantCount'];
        echo ($ok ? '  ✓ ' : '  ✗ ') . $case['name'] . "\n";
        if (!$ok) {
            $failures++;
            \fwrite(STDERR, '    实得 ' . \json_encode($got) . "\n");
        }
    }
    echo $failures === 0
        ? "[build-stale-check --selftest] ✓ 全过\n"
        : "[build-stale-check --selftest] ✗ {$failures} 条红\n";
    exit($failures === 0 ? 0 : 1);
}

$root = \dirname(__DIR__);
$src = (string) \file_get_contents($root . '/tools/drift-build.php');
$missing = stale_missing($src);

echo "[build-stale-check] 对账 `tools/drift-build.php` 的「门不能信缓存」接线\n";
if ($missing !== []) {
    foreach ($missing as $m) {
        \fwrite(STDERR, "  - {$m}\n");
    }
    exit(1);
}

echo "[build-stale-check] ✓ 决策走 StalePolicy、FULL 清两处缓存、--dry 不碰缓存、旧口径无残留\n";
exit(0);
