<?php
declare(strict_types=1);

/**
 * 缺陷覆盖率量具。
 *
 *   php tests/Contracts/run-corpus.php                 # core 层，打印矩阵
 *   php tests/Contracts/run-corpus.php --tier=full     # 加上 feature（S3 验收用）
 *   php tests/Contracts/run-corpus.php --defect=B2     # 只跑一条（开发题库时用）
 *   php tests/Contracts/run-corpus.php --dry-run       # 只验锚点能不能注入/还原（秒级）
 *   php tests/Contracts/run-corpus.php --probe=lint    # 只跑一条通道
 *   php tests/Contracts/run-corpus.php --json          # 机器可读，留给 V2 对比
 *
 * 这是量具不是判据。只有三种情况 exit 1：D0（空对照）假红、注入没还原干净、
 * 非 aot 缺陷**一条通道都没抓到**（那说明题库或通道在骗自己）。
 * 其余如实记录，包括「V1 其实抓不到」的格子 —— 那些正是重建时最该补的洞。
 *
 * ★ 通道并发跑：缺陷必须串行（每个都要改工作树），通道之间互不依赖。
 *   串行 9 条约 125s/缺陷，并发后墙钟 = 最慢那条（feature）≈ 65s。
 */
require __DIR__ . '/DefectCorpus.php';

use Drift\Dev\Tests\Contracts\DefectCorpus;

$root = \dirname(__DIR__, 2);
$php  = \escapeshellarg(PHP_BINARY);
$arg  = static fn(string $p): string => \escapeshellarg($root . '/' . $p);
$argv_ = \array_slice($argv, 1);

$opt = static function (string $name) use ($argv_): ?string {
    foreach ($argv_ as $a) {
        if (\str_starts_with($a, '--' . $name . '=')) {
            return \substr($a, \strlen($name) + 3);
        }
    }

    return null;
};

$json       = \in_array('--json', $argv_, true);
$dryRun     = \in_array('--dry-run', $argv_, true);
$suite      = $opt('suite') ?? 'v1';
$tier       = $opt('tier') ?? 'core';
$onlyDefect = $opt('defect');
$onlyProbe  = $opt('probe');

$phpunit = $php . ' ' . $arg('vendor/bin/phpunit') . ' -c ' . $arg('phpunit.xml')
    . ' --no-coverage --do-not-cache-result';

/**
 * 通道 = V1 的一条 gate 项（或一条代表性切片）。
 * `tier=core` 覆盖每一类缺陷；`full` 额外补上最贵的 feature 全量。
 *
 * @var array<string, array{cmd: string, tier: string, class: string}> $probes
 */
$probes = [
    'sceneset' => [
        'cmd'   => $php . ' ' . $arg('tools/scene-set-check.php') . ' --json',
        'tier'  => 'core',
        'class' => 'set',
    ],
    'docref' => [
        'cmd'   => $php . ' ' . $arg('tools/docref.php'),
        'tier'  => 'core',
        'class' => 'doc',
    ],
    'apidoc' => [
        'cmd'   => $php . ' ' . $arg('tools/api-doc-check.php'),
        'tier'  => 'core',
        'class' => 'static',
    ],
    'theme' => [
        'cmd'   => $phpunit . ' ' . $arg('tests/Feature/Table/ThemeMetricTest.php'),
        'tier'  => 'core',
        'class' => 'behavior',
    ],
    'lint' => [
        'cmd'   => $php . ' ' . $arg('tools/lint.php'),
        'tier'  => 'core',
        'class' => 'static',
    ],
    'pages' => [
        'cmd'   => $php . ' ' . $arg('tools/golden-raster.php') . ' --check --pages --json',
        'tier'  => 'core',
        'class' => 'pixel',
    ],
    'unit' => [
        'cmd'   => $phpunit . ' --testsuite unit',
        'tier'  => 'core',
        'class' => 'unit',
    ],
    'smoke' => [
        'cmd'   => $php . ' ' . $arg('tools/smoke.php'),
        'tier'  => 'core',
        'class' => 'behavior',
    ],
    'feature' => [
        'cmd'   => $phpunit . ' --testsuite feature',
        'tier'  => 'full',
        'class' => 'behavior',
    ],
];

if (!\in_array($tier, ['core', 'full'], true)) {
    \fwrite(STDERR, "未知层级 {$tier}（core | full）\n");
    exit(2);
}

if ($tier === 'core') {
    $probes = \array_filter($probes, static fn(array $p): bool => $p['tier'] === 'core');
}

if ($onlyProbe !== null) {
    if (!isset($probes[$onlyProbe])) {
        \fwrite(STDERR, "未知通道 {$onlyProbe}（已有：" . \implode(', ', \array_keys($probes)) . "）\n");
        exit(2);
    }
    $probes = [$onlyProbe => $probes[$onlyProbe]];
}

$defects = DefectCorpus::all();
if ($onlyDefect !== null) {
    $wanted  = \array_map('trim', \explode(',', $onlyDefect));
    $defects = \array_values(\array_filter(
        $defects,
        static fn(array $d): bool => \in_array($d['id'], $wanted, true) || $d['id'] === 'D0'
    ));
    if ($defects === []) {
        \fwrite(STDERR, "未知缺陷 {$onlyDefect}\n");
        exit(2);
    }
}

if ($dryRun) {
    $bad = 0;
    foreach ($defects as $d) {
        try {
            $revert = DefectCorpus::apply($d['op'], $root);
            $target = $root . '/' . ($d['op']['path'] ?? '');
            $lint = '';
            if (\str_ends_with($target, '.php') && \is_file($target)) {
                $out = [];
                \exec(\escapeshellarg(PHP_BINARY) . ' -l ' . \escapeshellarg($target) . ' 2>&1', $out, $code);
                if ($code !== 0) {
                    $lint = '（注入后 PHP 语法就坏了：' . \trim(\implode(' ', $out)) . '）';
                }
            }
            $revert();
            if ($lint !== '') {
                $bad++;
                echo "  FAIL {$d['id']}  {$lint}\n";

                continue;
            }
            echo "  ok   {$d['id']}\n";
        } catch (\Throwable $e) {
            $bad++;
            echo "  FAIL {$d['id']}  " . $e->getMessage() . "\n";
        }
    }
    echo "\n" . \count($defects) . " 条；锚点失败 {$bad} 条\n";
    exit($bad === 0 ? 0 : 1);
}

/**
 * 并发跑一组命令：全部起飞后逐个 `proc_close`（它会阻塞到该进程结束）。
 * 墙钟 ≈ 最慢一条，而不是求和。
 *
 * 输出重定向到临时文件而不是管道：PHPUnit / smoke 的输出能超过管道缓冲区，
 * 只写不读会死锁。
 *
 * @param array<string, string> $cmds
 * @return array<string, array{0: int, 1: string}>
 */
function runConcurrent(array $cmds): array
{
    $dir = \sys_get_temp_dir() . '/drift-corpus-' . \getmypid();
    if (!\is_dir($dir)) {
        \mkdir($dir, 0777, true);
    }

    $procs = [];
    foreach ($cmds as $name => $cmd) {
        $log = $dir . '/' . $name . '.log';
        $descriptor = [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']];
        $handle = \proc_open($cmd, $descriptor, $pipes);
        if (!\is_resource($handle)) {
            throw new \RuntimeException('起不来：' . $cmd);
        }
        $procs[$name] = [$handle, $log];
    }

    $results = [];
    foreach ($procs as $name => [$handle, $log]) {
        $code = \proc_close($handle);
        $results[$name] = [$code, (string) \file_get_contents($log)];
        @\unlink($log);
    }
    @\rmdir($dir);

    return $results;
}

// 基线：已有红灯时不能把「抓到」算到题库头上。
$baseline = [];
foreach (runConcurrent(\array_map(static fn(array $p): string => $p['cmd'], $probes)) as $name => [$code, $out]) {
    $baseline[$name] = $code;
    if ($code !== 0) {
        \fwrite(STDERR, "基线通道 {$name} 已经红了（exit={$code}），先修再跑题库。\n");
        \fwrite(STDERR, \substr($out, 0, 2000) . "\n");
        exit(2);
    }
}

$matrix = [];
foreach ($defects as $defect) {
    // 注入失败（锚点漂了）只算这条无效，不能让整轮 9 分钟白跑。
    try {
        $revert = DefectCorpus::apply($defect['op'], $root);
    } catch (\Throwable $e) {
        $matrix[] = [
            'id'     => $defect['id'],
            'class'  => $defect['class'],
            'from'   => $defect['from'],
            'aot'    => (bool) ($defect['aot'] ?? false),
            'caught' => [],
            'error'  => $e->getMessage(),
        ];
        \fwrite(STDERR, "[{$defect['id']}] 注入失败：" . $e->getMessage() . "\n");

        continue;
    }

    try {
        $caught = [];
        foreach (runConcurrent(\array_map(static fn(array $p): string => $p['cmd'], $probes)) as $name => [$code]) {
            if ($code !== 0) {
                $caught[$name] = $code;
            }
        }
        $matrix[] = [
            'id'     => $defect['id'],
            'class'  => $defect['class'],
            'from'   => $defect['from'],
            'aot'    => (bool) ($defect['aot'] ?? false),
            'caught' => $caught,
        ];
    } finally {
        $revert();
    }

    // 增量落盘：中途炸掉也能拿到前面的结果。
    if ($json) {
        \file_put_contents(
            $root . '/_build/corpus-partial.json',
            \json_encode($matrix, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n"
        );
    }
}

$leftover = [];
foreach ($defects as $d) {
    $bak = $root . '/' . ($d['op']['path'] ?? '') . '.corpus-bak';
    if (\is_file($bak) || \is_dir($bak)) {
        $leftover[] = $bak;
    }
}

$control = $matrix[0]['caught'] ?? [];
$real    = \array_slice($matrix, 1);
$graded  = \array_values(\array_filter($real, static fn(array $r): bool => !$r['aot']));
$aotOnly = \array_values(\array_filter($real, static fn(array $r): bool => $r['aot']));
$covered = \array_values(\array_filter($graded, static fn(array $r): bool => $r['caught'] !== []));
$blind   = \array_values(\array_filter($graded, static fn(array $r): bool => $r['caught'] === []));
$ok      = $control === [] && $leftover === [] && $blind === [];

if ($json) {
    echo \json_encode([
        'suite'    => $suite,
        'tier'     => $tier,
        'baseline' => $baseline,
        'matrix'   => $matrix,
        'leftover' => $leftover,
        'graded'   => \count($graded),
        'covered'  => \count($covered),
        'blind'    => \array_column($blind, 'id'),
        'aotOnly'  => \array_column($aotOnly, 'id'),
        'ok'       => $ok,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit($ok ? 0 : 1);
}

$names = \array_keys($probes);
echo "[题库] 套件 {$suite} · 层级 {$tier} · 基线 " . \count($probes) . " 条全绿\n\n";
echo \str_pad('缺陷', 6) . \implode('  ', \array_map(static fn($n) => \str_pad($n, 8), $names)) . "抓到\n";
foreach ($matrix as $row) {
    $cells = [];
    foreach ($names as $name) {
        $cells[] = \str_pad(isset($row['caught'][$name]) ? '红' : '·', 8);
    }
    $mark = $row['aot'] ? ' (aot-only)' : '';
    echo \str_pad($row['id'], 6) . \implode('  ', $cells) . \count($row['caught']) . $mark . "\n";
}

echo "\n覆盖 " . \count($covered) . '/' . \count($graded) . ' 个可解释器侧发现的缺陷';
echo '；D0 空对照 ' . ($control === [] ? '全绿 ✓' : '假红 ✗ ' . \implode(',', \array_keys($control)));
echo '；残留备份 ' . \count($leftover) . " 个\n";
if ($blind !== []) {
    echo '✗ 一条通道都没抓到（题库或通道在骗自己）：' . \implode(', ', \array_column($blind, 'id')) . "\n";
}
if ($aotOnly !== []) {
    echo '· aot-only（解释器侧全绿是已知缺口，不计分母）：' . \implode(', ', \array_column($aotOnly, 'id')) . "\n";
}

exit($ok ? 0 : 1);
