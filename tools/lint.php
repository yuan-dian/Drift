<?php
declare(strict_types=1);

/**
 * drift-lint —— 构建期静态检查（§16.2 六件套之一）。
 *
 * 用法：
 *   php tools/lint.php            全仓检查
 *   php tools/lint.php --list     打印规则清单（与 §16.6 的表对账）
 *   php tools/lint.php --api      打印 @api 面清单（按层分组，当文档用）
 *   php tools/lint.php --selftest 量具双向自检（§20 纪律 1）
 */

use Drift\Dev\Lint\ApiSurface;
use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Linter;
use Drift\Dev\Lint\Project;

require __DIR__ . '/autoload.php';

$root = dirname(__DIR__);
$linter = Linter::defaultRules();
$args = array_slice($argv, 1);

if (in_array('--list', $args, true)) {
    foreach ($linter->rules() as $rule) {
        printf("%-24s %-6s %s\n", $rule->name(), $rule->serves(), $rule->summary());
    }
    exit(0);
}

if (in_array('--api', $args, true)) {
    echo ApiSurface::render(ApiSurface::scan($root)), "\n";
    exit(0);
}

if (in_array('--selftest', $args, true)) {
    require __DIR__ . '/selftest.php';
    $ok = drift_lint_selftest($root, $linter);
    try {
        ApiSurface::selftest();
    } catch (\RuntimeException $e) {
        fwrite(STDERR, '  FAIL  api-surface ' . $e->getMessage() . "\n");
        $ok = false;
    }
    exit($ok ? 0 : 1);
}

$project = Project::scan($root, ['tools/Lint/fixtures/']);
$diagnostics = $linter->run($project);

$errors = 0;
$warns = 0;
foreach ($diagnostics as $diagnostic) {
    echo $diagnostic->format(), "\n";
    if ($diagnostic->isError()) {
        $errors++;
    } else {
        $warns++;
    }
}

printf(
    "\n--- %d 个文件 · %d error · %d warn ---\n",
    count($project->files()),
    $errors,
    $warns
);

exit($errors > 0 ? 1 : 0);
