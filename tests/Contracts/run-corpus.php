<?php
declare(strict_types=1);

/**
 * 缺陷覆盖率量具。
 *
 *   php tests/Contracts/run-corpus.php              # 跑 v1，打印矩阵
 *   php tests/Contracts/run-corpus.php --json      # 机器可读，留给 V2 对比
 *   php tests/Contracts/run-corpus.php --suite=v2  # V2 建好后填进 $suites
 *
 * 这是量具不是判据：只有 D0（空对照）假红才 exit 1；其余如实记录，
 * 包括「V1 其实抓不到」的格子 —— 那些正是重建时最该补的洞。
 */
require __DIR__ . '/DefectCorpus.php';

use Drift\Dev\Tests\Contracts\DefectCorpus;

$root  = \dirname(__DIR__, 2);
$php   = \escapeshellarg(PHP_BINARY);
$arg   = static fn(string $p): string => \escapeshellarg($root . '/' . $p);
$suite = 'v1';
$json  = \in_array('--json', \array_slice($argv, 1), true);
foreach (\array_slice($argv, 1) as $a) {
    if (\str_starts_with($a, '--suite=')) {
        $suite = \substr($a, 8);
    }
}

$suites = [
    'v1' => [
        'sceneset'  => $php . ' ' . $arg('tools/scene-set-check.php') . ' --json',
        'pages'     => $php . ' ' . $arg('tools/golden-raster.php') . ' --check --pages --json',
        'theme'     => $php . ' ' . $arg('vendor/bin/phpunit') . ' -c ' . $arg('phpunit.xml')
            . ' --no-coverage ' . $arg('tests/Feature/Table/ThemeMetricTest.php'),
        'sceneself' => $php . ' ' . $arg('tools/scene-set-check.php') . ' --selftest',
    ],
];

if (!isset($suites[$suite])) {
    \fwrite(STDERR, "未知套件 {$suite}（已有：" . \implode(', ', \array_keys($suites)) . "）\n");
    exit(2);
}
$probes = $suites[$suite];

function probe(string $cmd): array
{
    $out = [];
    $code = -1;
    \exec($cmd . ' 2>&1', $out, $code);

    return [$code, \implode("\n", $out)];
}

// 基线：已有红灯时不能把「抓到」算到题库头上。
$baseline = [];
foreach ($probes as $name => $cmd) {
    [$code] = probe($cmd);
    $baseline[$name] = $code;
    if ($code !== 0) {
        \fwrite(STDERR, "基线判据 {$name} 已经红了（exit={$code}），先修再跑题库。\n");
        exit(2);
    }
}

$matrix = [];
foreach (DefectCorpus::all() as $defect) {
    $revert = DefectCorpus::apply($defect['op'], $root);
    try {
        $caught = [];
        foreach ($probes as $name => $cmd) {
            [$code, $out] = probe($cmd);
            if ($code !== 0) {
                $caught[$name] = $code;
            }
        }
        $matrix[] = ['id' => $defect['id'], 'from' => $defect['from'], 'caught' => $caught];
    } finally {
        $revert();
    }
}

$d0 = $matrix[0]['caught'];
$leftover = [];
foreach (DefectCorpus::all() as $d) {
    $bak = $root . '/' . ($d['op']['path'] ?? '') . '.corpus-bak';
    if (\is_file($bak) || \is_dir($bak)) {
        $leftover[] = $bak;
    }
}

if ($json) {
    echo \json_encode([
        'suite'    => $suite,
        'baseline' => $baseline,
        'matrix'   => $matrix,
        'leftover' => $leftover,
        'ok'       => $d0 === [] && $leftover === [],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} else {
    echo "[题库] 套件 {$suite} · 基线 " . \count($probes) . " 条全绿\n\n";
    echo '缺陷   ' . \implode('  ', \array_map(static fn($n) => \str_pad($n, 11), \array_keys($probes))) . "抓到\n";
    foreach ($matrix as $row) {
        $cells = [];
        foreach (\array_keys($probes) as $name) {
            $cells[] = \str_pad(isset($row['caught'][$name]) ? '红' : '·', 11);
        }
        echo \str_pad($row['id'], 6) . \implode('  ', $cells) . \count($row['caught']) . "\n";
    }
    $real = \array_slice($matrix, 1);
    $covered = \count(\array_filter($real, static fn($r) => $r['caught'] !== []));
    echo "\n覆盖 {$covered}/" . \count($real) . ' 个缺陷；D0 空对照 '
        . ($d0 === [] ? '全绿 ✓' : '假红 ✗ ' . \implode(',', \array_keys($d0)))
        . '；残留备份 ' . \count($leftover) . " 个\n";
}

exit($d0 === [] && $leftover === [] ? 0 : 1);
