<?php
declare(strict_types=1);

/**
 * `drift-build` 的「门不能信缓存」接线对账（V2 `stalepolicy` step）。
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
    ];
    foreach ($require as $needle => $why) {
        if (!\str_contains($src, $needle)) {
            $missing[] = $why . '：缺 ' . $needle;
        }
    }

    // `--incremental` 的两种认法：老的一次性 `in_array('--incremental', $argvRest)`，
    // 新的统一选项表 `isset($options['incremental'])`（2026-11-28 参数解析重写）。
    // 两种都不在 ⇒ 开关真没了；只认其中一种就等于把另一种合法写法判成红。
    if (!\str_contains($src, "'--incremental'") && !\str_contains($src, "isset(\$options['incremental'])")) {
        $missing[] = "缺 --incremental 开关（默认必须不放行增量）：缺 '--incremental'";
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

    // `cpp-src/` 必须按全部后缀入指纹：`hashTree()` 默认只收 `.php`，而那里只有
    // stub 是 PHP ⇒ 只改 `.cc` / `.h` 时指纹不变、构建 SKIP，产物静默过期
    // （2026-11-11 拆 UIA 时实证：重编却报"输入未变"）。
    $cppLine = '';
    foreach (\preg_split('~\R~', $src) ?: [] as $line) {
        if (\str_contains($line, "'cpp:'")) {
            $cppLine = $line;
            break;
        }
    }
    if ($cppLine === '' || !\str_contains($cppLine, 'hashTree(') || !\str_contains($cppLine, ", ''")) {
        $missing[] = "C++ 源码没进输入指纹（`cpp-src` 那行要给 hashTree 传 ''，否则改 .cc 会 SKIP）";
    }

    // tpc 编译器身份必须覆盖编译器**源码树**：`bin/tpc.php` 只有 12 行、2026-09-23
    // 至今未变 ⇒ 只 `hash_file($entry)` 等于「升级编译器不算输入变」，拉取 tpc 后
    // 决策照旧 SKIP、旧产物静默保留（2026-11-11 实证）。
    foreach ([
        "'tpc:' . \$tpcIdentity" => 'tpc 指纹退回了只哈希入口（`bin/tpc.php` 恒不变）⇒ 升级编译器会 SKIP',
        'hashTree($tpcSourceRoot' => 'tpc 指纹没覆盖编译器源码树（`typephp/src/**` 改了不算输入变）',
    ] as $needle => $why) {
        if (!\str_contains($src, $needle)) {
            $missing[] = $why;
        }
    }
    if (\str_contains($src, "'tpc:' . (string) hash_file('sha256', \$entry)")) {
        $missing[] = 'tpc 指纹只哈希了入口文件（旧口径）⇒ 升级编译器不会触发全量';
    }

    // 工程目录必须收 `project.yml`：默认后缀只认 `.php` ⇒ 改 `sources` / `ignore` /
    // `resource` 后指纹不变、构建 SKIP，产物静默过期；但又**不能**收全部——
    // `build/` 是每次构建都变的生成物，收进来就永远 FULL（比 SKIP 更难发现）。
    $appLine = stale_line_with($src, "'app:'");
    if ($appLine === '') {
        $missing[] = '工程目录没进输入指纹（`app:` 那行没了）';
    } else {
        if (!\str_contains($appLine, 'hashTree(')) {
            $missing[] = "工程目录没走 hashTree（`app:` 那行）";
        }
        if (!\str_contains($appLine, "'.yml'")) {
            $missing[] = "工程目录只收 `.php`（`app:` 那行要带 '.yml'，否则改 project.yml 会 SKIP）";
        }
        if (!\str_contains($appLine, "'build'")) {
            $missing[] = "工程目录没排除 build/（生成物每帧都变 ⇒ 指纹永远变、每次全量重编）";
        }
        // `bin` 不是产物目录：`apps/<app>/bin/<os>/Bootstrap.php` 是组合根源码。
        //   把它排进 ignore 后，改 Bootstrap 指纹不变 ⇒ 构建 SKIP、跑起来还是旧 exe。
        if (\str_contains($appLine, "'bin'")) {
            $missing[] = "工程目录把 bin/ 当产物排除了（`bin/<os>/Bootstrap.php` 是组合根源码 ⇒ 改它构建会 SKIP）";
        }
    }

    // 图标是构建期嵌入的，但它在仓库根 `assets/`，不在工程目录内 ⇒ 必须单列一项。
    $assetsLine = stale_line_with($src, "'assets:'");
    if ($assetsLine === '' || !\str_contains($assetsLine, 'hashTree(')) {
        $missing[] = '图标目录没进输入指纹（`assets:` 那行）⇒ 换图标构建会 SKIP';
    }

    return $missing;
}

/** 取出包含指定标记的那一行源码（用于逐行核对参数），没有则返回 '' */
function stale_line_with(string $src, string $needle): string
{
    foreach (\preg_split('~\R~', $src) ?: [] as $line) {
        if (\str_contains($line, $needle)) {
            return $line;
        }
    }

    return '';
}

/** 一份「接线齐全」的合成源码，供 --selftest 做正对照 */
function stale_good_source(): string
{
    return \implode("\n", [
        'use Drift\Dev\Build\StalePolicy;',
        "function wipeCache(string \$d): void {}",
        "\$incremental = in_array('--incremental', \$argvRest, true);",
        "\$decision = StalePolicy::decide(\$p, \$c, \$e, \$f, \$i);",
        "hash_update(\$inputs, 'app:' . hashTree(\$projectDir, ['.php', '.yml', '.json'], ['build', 'tests']) . \"\\n\");",
        "hash_update(\$inputs, 'assets:' . hashTree(\$root . '/assets', '') . \"\\n\");",
        "hash_update(\$inputs, 'cpp:' . hashTree(\$root . '/cpp-src', '') . \"\\n\");",
        "hash_update(\$inputs, 'tpc:' . \$tpcIdentity . \"\\n\");",
        "\$tpcIdentity = is_dir(\$tpcSourceRoot . '/src') ? hashTree(\$tpcSourceRoot . '/src', '') : '';",
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
        [
            'name' => 'C++ 指纹退回默认后缀（只收 .php）⇒ 报产物会静默过期',
            'got' => \array_values(\array_filter(
                stale_missing(\str_replace("hashTree(\$root . '/cpp-src', '')", "hashTree(\$root . '/cpp-src')", $good)),
                static fn(string $m): bool => \str_contains($m, '没进输入指纹')
            )),
            'wantCount' => 1,
        ],
        [
            'name' => 'tpc 指纹退回只哈希入口 ⇒ 报升级编译器会 SKIP',
            'got' => \array_values(\array_filter(
                stale_missing(\str_replace(
                    "hash_update(\$inputs, 'tpc:' . \$tpcIdentity . \"\\n\");",
                    "hash_update(\$inputs, 'tpc:' . (string) hash_file('sha256', \$entry) . \"\\n\");",
                    $good
                )),
                static fn(string $m): bool => \str_contains($m, 'tpc 指纹')
            )),
            'wantCount' => 2,
        ],
        [
            'name' => 'tpc 不再覆盖编译器源码树 ⇒ 报源码改了不算输入变',
            'got' => \array_values(\array_filter(
                stale_missing(\str_replace(
                    "hashTree(\$tpcSourceRoot . '/src', '')",
                    "'unused'",
                    $good
                )),
                static fn(string $m): bool => \str_contains($m, '没覆盖编译器源码树')
            )),
            'wantCount' => 1,
        ],
        [
            'name' => '工程目录退回默认后缀（只收 .php）⇒ 报改 project.yml 会 SKIP',
            'got' => \array_values(\array_filter(
                stale_missing(\str_replace(
                    "hashTree(\$projectDir, ['.php', '.yml', '.json'], ['build', 'tests'])",
                    "hashTree(\$projectDir)",
                    $good
                )),
                static fn(string $m): bool => \str_contains($m, '只收 `.php`')
            )),
            'wantCount' => 1,
        ],
        [
            'name' => '工程目录把 bin/ 当产物排除 ⇒ 报改组合根会 SKIP',
            'got' => \array_values(\array_filter(
                stale_missing(\str_replace(
                    "['build', 'tests']",
                    "['build', 'bin', 'tests']",
                    $good
                )),
                static fn(string $m): bool => \str_contains($m, '把 bin/ 当产物排除')
            )),
            'wantCount' => 1,
        ],
        [
            'name' => '工程目录不排除 build/ ⇒ 报指纹永远变、每次全量',
            'got' => \array_values(\array_filter(
                stale_missing(\str_replace(
                    "['build', 'tests']",
                    "['tests']",
                    $good
                )),
                static fn(string $m): bool => \str_contains($m, '没排除 build/')
            )),
            'wantCount' => 1,
        ],
        [
            'name' => '删掉 assets 指纹行 ⇒ 报换图标会 SKIP',
            'got' => \array_values(\array_filter(
                stale_missing(\str_replace(
                    "hash_update(\$inputs, 'assets:' . hashTree(\$root . '/assets', '') . \"\\n\");\n",
                    '',
                    $good
                )),
                static fn(string $m): bool => \str_contains($m, '图标目录没进输入指纹')
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
