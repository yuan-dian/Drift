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
 *   php tests/Contracts/run-corpus.php --suite=v2 --tier=full --json
 *                                                    # S3：V2 矩阵 + 自动对照 V1 基线
 *
 * 这是量具不是判据。exit 1：D0（空对照）假红、注入没还原干净、
 * 非 aot 缺陷**一条通道都没抓到**、或 V2 相对 V1 基线出现覆盖回归。
 * 其余如实记录，包括「V1 其实抓不到」的格子 —— 那些正是重建时最该补的洞。
 * exit 2 = 「没测到」：未知/未注册套件、基线缺失、裁剪结果想当验收报告。
 *
 * ★ 套件路由：`v1` 是现有 9 条通道；`v2` 从 `tests/Verify/VerifyPlan.php` 生成。
 *   绝不回退到 v1 通道 —— 那只会给 V1 结果贴个 v2 标签，S3 对照自己骗自己。
 *   V1 基线是入库的 `tests/Contracts/baseline/v1-postdocs-full.json`（17 条，
 *   4 条旧文档缺陷已显式退役），不在 gitignored scratch。
 *
 * ★ 通道并发跑：缺陷必须串行（每个都要改工作树），通道之间互不依赖。
 *   串行 9 条约 125s/缺陷，并发后墙钟 = 最慢那条（feature）≈ 65s。
 */
require __DIR__ . '/DefectCorpus.php';
require __DIR__ . '/../Verify/VerifyPlan.php';

use Drift\Dev\Tests\Contracts\DefectCorpus;
use Drift\Dev\Tests\Verify\VerifyPlan;

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
$suite        = $opt('suite') ?? 'v1';
$tier         = $opt('tier') ?? 'core';
$baselinePath = $opt('baseline') ?? __DIR__ . '/baseline/v1-postdocs-full.json';
$onlyDefect   = $opt('defect');
$onlyProbe    = $opt('probe');

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

/**
 * 套件 = 哪一套验证体系在答这份题库。V2 通道由 VerifyPlan 生成，命令统一走
 * `bin/drift verify --step=<id>`：判据清单只有一份，量具不复制第二套执行器。
 * device 层（AOT）不进题库矩阵 —— 解释器侧本来也抓不到 aot-only 两条。
 */
$v2Probes = [];
foreach (VerifyPlan::steps($root) as $id => $s) {
    if ($s['layer'] === 'device') {
        continue;
    }
    $v2Probes[$id] = [
        'cmd'   => $php . ' ' . $arg('tests/Verify/verify.php') . ' --step=' . \escapeshellarg($id),
        'tier'  => $s['tier'] === 'full' ? 'full' : 'core',
        'class' => $s['class'],
    ];
}

$suites = [
    'v1' => $probes,
    'v2' => $v2Probes,
];
if (!\array_key_exists($suite, $suites)) {
    \fwrite(STDERR, "未知套件 {$suite}（已有：" . \implode(', ', \array_keys($suites)) . "）\n");
    exit(2);
}
if ($suites[$suite] === null) {
    \fwrite(STDERR, "套件 {$suite} 尚未注册通道。\n");
    \fwrite(STDERR, "不回退到 V1 通道：「没测到」不是通过。\n");
    exit(2);
}
$probes = $suites[$suite];

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
            'aot'     => (bool) ($defect['aot'] ?? false),
            'retired' => (bool) ($defect['retired'] ?? false),
            'caught'  => [],
            'error'   => $e->getMessage(),
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
            'aot'     => (bool) ($defect['aot'] ?? false),
            'retired' => (bool) ($defect['retired'] ?? false),
            'caught'  => $caught,
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
// 退役条目仍注入、仍留在矩阵里作历史档案，但既不进分母也不进基线对照。
$retired = \array_values(\array_filter($real, static fn(array $r): bool => (bool) ($r['retired'] ?? false)));
$live    = \array_values(\array_filter($real, static fn(array $r): bool => !($r['retired'] ?? false)));
$graded  = \array_values(\array_filter($live, static fn(array $r): bool => !$r['aot']));
$aotOnly = \array_values(\array_filter($live, static fn(array $r): bool => $r['aot']));
$covered = \array_values(\array_filter($graded, static fn(array $r): bool => $r['caught'] !== []));
$blind   = \array_values(\array_filter($graded, static fn(array $r): bool => $r['caught'] === []));
$ok      = $control === [] && $leftover === [] && $blind === [];

// 非 v1 套件必须对照入库的 V1 基线：S3 的硬验收线是「V2 覆盖率 ≥ V1」。
// 裁剪过的矩阵（--defect / --probe）不许当验收报告，否则会静默变绿。
$regression  = [];
$newlyCaught = [];
if ($suite !== 'v1') {
    if (!\is_file($baselinePath)) {
        \fwrite(STDERR, "V1 基线不存在：{$baselinePath}（没测到，不是通过）\n");
        exit(2);
    }
    $base = \json_decode((string) \file_get_contents($baselinePath), true);
    if (!\is_array($base) || !isset($base['matrix']) || !\is_array($base['matrix'])) {
        \fwrite(STDERR, "V1 基线格式不对：{$baselinePath}\n");
        exit(2);
    }
    $caughtBy = static function (array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            if ($row['id'] === 'D0' || ($row['aot'] ?? false) || ($row['retired'] ?? false)) {
                continue;
            }
            $out[$row['id']] = \array_keys($row['caught'] ?? []);
        }

        return $out;
    };
    $before = $caughtBy($base['matrix']);
    $after  = $caughtBy($real);
    if (\count($after) < \count($before)) {
        \fwrite(STDERR, '对照需要全量题库（当前 ' . \count($after) . '/' . \count($before)
            . "）；--defect/--probe 裁剪结果不能当验收报告。\n");
        exit(2);
    }
    foreach ($before as $id => $channels) {
        if ($channels !== [] && ($after[$id] ?? []) === []) {
            $regression[] = $id;
        }
    }
    foreach ($after as $id => $channels) {
        if ($channels !== [] && ($before[$id] ?? []) === []) {
            $newlyCaught[] = $id;
        }
    }
    $ok = $ok && $regression === [];
}

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
        'retired'  => \array_column($retired, 'id'),
        'compare'  => $suite === 'v1' ? null : $baselinePath,
        'regress'  => $regression,
        'gain'     => $newlyCaught,
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
    $mark = $row['aot'] ? ' (aot-only)'
        : (($row['retired'] ?? false) ? ' (retired)' : '');
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
if ($retired !== []) {
    echo '· retired（旧文档体系事故，显式退役不计分母）：' . \implode(', ', \array_column($retired, 'id')) . "\n";
}
if ($suite !== 'v1') {
    echo '· 对照 V1 基线：' . $baselinePath . '；回归 ' . \count($regression) . ' 条';
    if ($regression !== []) {
        echo ' ✗ ' . \implode(', ', $regression);
    }
    if ($newlyCaught !== []) {
        echo '；新抓到 ' . \implode(', ', $newlyCaught);
    }
    echo "\n";
}

exit($ok ? 0 : 1);
