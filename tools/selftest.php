<?php
declare(strict_types=1);

/**
 * drift-lint 的量具双向自检（§20 纪律 1）。
 *
 * 每条规则都配一组夹具：既验"该响会响"（已知错误），也验"不该响不误响"
 * （已知正确）。没有这层的 lint 只能证明"跑了"，不能证明"测得准"。
 *
 * @return bool 全部通过为 true
 */

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Linter;
use Drift\Dev\Lint\Project;

function drift_lint_selftest(string $root, Linter $linter): bool
{
    $fixtures = $root . '/tools/Lint/fixtures';
    $project = Project::scan($fixtures, [], ['src', 'apps']);
    $diagnostics = $linter->run($project);

    $actual = [];
    foreach ($diagnostics as $diagnostic) {
        $actual[$diagnostic->rule][] = $diagnostic->file . ':' . $diagnostic->severity;
    }

    // 每条规则的"已知错误"用例 —— 文件:严重度
    $expected = [
        'dependency-direction' => [
            'src/Geometry/Bad.php:' . Diagnostic::ERROR,
            'src/Widget/UsesTheme.php:' . Diagnostic::ERROR,
            'src/Widget/UsesCommands.php:' . Diagnostic::ERROR,
        ],
        'internal-leak' => [
            'apps/Bad.php:' . Diagnostic::ERROR,
            'apps/Exempt.php:' . Diagnostic::WARN,
        ],
        'namespace-mismatch' => [],
        'nano-hygiene' => [],
        'orphan-trait' => [],
    ];

    $ok = true;
    foreach ($linter->rules() as $rule) {
        $name = $rule->name();
        $got = $actual[$name] ?? [];
        $want = $expected[$name] ?? [];
        sort($got);
        sort($want);

        if ($got === $want) {
            printf("  ok    %-22s 该响 %d / 不该响 0\n", $name, count($want));
            continue;
        }

        $ok = false;
        printf("  FAIL  %-22s\n", $name);
        printf("        期望: %s\n", $want === [] ? '(无)' : implode(', ', $want));
        printf("        实际: %s\n", $got === [] ? '(无)' : implode(', ', $got));
    }

    foreach (array_keys($actual) as $name) {
        if (!isset($expected[$name])) {
            $ok = false;
            printf("  FAIL  规则 %s 产出了发现，但没有配对的夹具断言\n", $name);
        }
    }

    return $ok;
}
