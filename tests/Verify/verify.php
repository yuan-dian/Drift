#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `bin/drift verify` 的执行器 —— V2 唯一 runner。
 *
 *   php bin/drift verify                    日常（static + behavior:fast）
 *   php bin/drift verify --full             CI 口径（补 feature / pages / smoke）
 *   php bin/drift verify --layer=static     只跑一层
 *   php bin/drift verify --shard=unit:Layout  增量选跑一个目录（不是验收报告）
 *   php bin/drift verify --device --app=hello AOT 真机构建（人工触发）
 *   php bin/drift verify --layer=corpus    缺陷题库 V2 对照（一次性，~30min）
 *   php bin/drift verify --list            看计划（唯一事实源是 VerifyPlan）
 *   php bin/drift verify --step=lint       单通道，给 run-corpus.php 用
 *
 * ★ 退出码口径：0 全过 / 1 有 step 变红 / **2 没测到**（参数错、依赖缺失、
 *   进程起不来）。`--step` 模式下底层判据的退出码**原样透传** —— 题库量具
 *   把任何非 0 都算「抓到」，而历史基线里真实存在 2 / 3 / 255 三种「抓到」码，
 *   归一化会把它们洗成同一个值，甚至把「没测到」当成覆盖。
 *
 * ★ 依赖缺失由前置校验挡在跑之前，量具的基线阶段会再拦一次（`exit 2`）。
 * ★ 不读 V1 判据清单、不调 V1 编排层、不给判据配自证：V1 已随 S4 退役，
 *   判据可信度只由缺陷题库回答。
 * ★ 数组式 `proc_open` + `bypass_shell`：Windows 下带空格的路径不会被
 *   `cmd.exe` 二次解释（与 `bin/drift` 同法）。
 */

use Drift\Dev\Tests\Verify\VerifyPlan;

require __DIR__ . '/VerifyPlan.php';

$root = \dirname(__DIR__, 2);
$args = \array_slice($argv, 1);

/** @param list<string> $args */
function verify_opt(array $args, string $name): ?string
{
    foreach ($args as $a) {
        if (\str_starts_with($a, '--' . $name . '=')) {
            return \substr($a, \strlen($name) + 3);
        }
    }

    return null;
}

function verify_usage(): never
{
    \fwrite(STDERR, <<<'TXT'
用法：php bin/drift verify [--full] [--layer=static|behavior|device|corpus|all]
                          [--shard=<unit|feature>:<目录>] [--step=<id>]
                          [--device] [--app=<name>] [--json] [--list]

  --full      把 tier=full 的 step 纳入（CI 只认这个；默认只跑快项）
  --device    把 device 层纳入（需 MSVC + SDK，人工触发）
  --shard     增量选跑一个 PHPUnit 目录；裁剪结果不能当验收报告
  --step      单通道透传（供 tests/Contracts/run-corpus.php --suite=v2 使用）
  --list      打印分层计划与分片清单（唯一事实源 VerifyPlan）

  0 = 全过，1 = 有项红，2 = 没测到。

TXT);
    exit(2);
}

/**
 * 前置依赖校验：命令里任何指向磁盘却不存在的路径 = 没测到。
 * 从 command 自动推导，不另抄一份依赖清单（抄一份必然漂）。
 *
 * @param list<string> $command
 * @return list<string> 缺失路径
 */
function verify_missing(string $root, array $command): array
{
    $bad = [];
    foreach ($command as $part) {
        $isPath = \str_starts_with($part, $root . '/')
            || (bool) \preg_match('#^[A-Za-z]:[\\\\/]#', $part);
        if (!$isPath) {
            continue;
        }
        if (!\is_file($part) && !\is_dir($part)) {
            $bad[] = $part;
        }
    }

    return $bad;
}

/**
 * 并发跑一组 step：全部起飞后再统一收割，墙钟 = 最慢一条。
 * 输出走非阻塞管道，避免 PHPUnit / smoke 的大输出把管道堵死。
 *
 * @param list<array{id: string, label: string, command: list<string>}> $steps
 * @return list<array{id: string, label: string, exitCode: int, stdout: string, stderr: string, durationMs: int}>
 */
function verify_run_steps(string $root, array $steps): array
{
    // ★ 输出重定向到**临时文件**而不是管道：Windows 上 `stream_set_blocking(false)`
    //   对 proc_open 管道不生效，`stream_get_contents()` 会阻塞到该进程 EOF ⇒
    //   并发退化成「按顺序等」，durationMs 也失真（sceneset 0.9s 记成 9.7s）。
    //   `run-corpus.php` 的 runConcurrent() 早就改用临时文件，是同一个坑。
    $dir = \sys_get_temp_dir() . '/drift-verify-' . \getmypid();
    if (!\is_dir($dir)) {
        \mkdir($dir, 0777, true);
    }

    $live = [];
    foreach ($steps as $s) {
        $t0  = \hrtime(true);
        $log = $dir . '/' . \preg_replace('/[^A-Za-z0-9_.-]/', '_', $s['id']) . '.log';
        $proc = \proc_open(
            $s['command'],
            [0 => ['file', 'nul', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true],
        );
        if (!\is_resource($proc)) {
            $live[] = ['id' => $s['id'], 'label' => $s['label'], 't0' => $t0, 'log' => $log,
                'handle' => null, 'code' => 127, 'endT' => \hrtime(true)];
            continue;
        }
        $live[] = ['id' => $s['id'], 'label' => $s['label'], 't0' => $t0, 'log' => $log,
            'handle' => $proc, 'code' => -1, 'endT' => 0];
    }

    // 只轮询进程状态、不碰管道 ⇒ 不会阻塞；durationMs 记「起飞 → 该进程自己结束」，
    // 不是「起飞 → 轮到收割它」，否则快项会被记成慢项的耗时。
    while (true) {
        $running = 0;
        foreach ($live as &$r) {
            if ($r['endT'] !== 0) {
                continue;
            }
            $status = \proc_get_status($r['handle']);
            if (!$status['running']) {
                $r['code'] = (int) $status['exitcode'];
                $r['endT'] = \hrtime(true);
            } else {
                $running++;
            }
        }
        unset($r);
        if ($running === 0) {
            break;
        }
        \usleep(10000);
    }

    $out = [];
    foreach ($live as $r) {
        if ($r['handle'] !== null) {
            \proc_close($r['handle']);
        }
        // stdout 与 stderr 写进同一个文件：失败详情（PHPUnit / lint 的 stderr）
        // 必须在报告里可见，不值得为了分流再开第二个临时文件。
        $out[] = [
            'id' => $r['id'], 'label' => $r['label'], 'exitCode' => $r['code'],
            'stdout' => \is_file($r['log']) ? (string) \file_get_contents($r['log']) : '',
            'stderr' => '',
            'durationMs' => (int) \round(($r['endT'] - $r['t0']) / 1e6),
        ];
        @\unlink($r['log']);
    }
    @\rmdir($dir);

    return $out;
}

$listOnly = \in_array('--list', $args, true);
$json     = \in_array('--json', $args, true);
$full     = \in_array('--full', $args, true);
$device   = \in_array('--device', $args, true);
$layer    = verify_opt($args, 'layer') ?? 'all';
$stepId   = verify_opt($args, 'step');
$shardId  = verify_opt($args, 'shard');
$app      = verify_opt($args, 'app') ?? 'hello';

if (!\in_array($layer, ['all', 'static', 'behavior', 'device', 'corpus'], true)) {
    verify_usage();
}
if ($stepId !== null && ($shardId !== null || $layer !== 'all')) {
    verify_usage();
}
if ($shardId !== null && $layer !== 'all') {
    verify_usage();
}

$steps  = VerifyPlan::steps($root, $app);
$shards = VerifyPlan::shards($root);

if ($listOnly) {
    echo "V2 验证计划（唯一事实源：tests/Verify/VerifyPlan.php）\n\n";
    foreach ([...VerifyPlan::LAYERS, 'corpus'] as $l) {
        echo '[' . $l . "]\n";
        if ($l === 'corpus') {
            // ★ 这一行是**展示**不是 steps()：corpus 不在 VerifyPlan::LAYERS 里，
            //   `--full --json` 的 items 也没有它。把它当「报告里会有 corpus」会写错断言。
            echo "  corpus      full     缺陷题库 V2 对照（独立量具，不进 --json 的 items）→ run-corpus.php --suite=v2 --tier=full\n";
            continue;
        }
        foreach ($steps as $s) {
            if ($s['layer'] !== $l) {
                continue;
            }
            echo '  ' . \str_pad($s['id'], 13) . \str_pad($s['tier'], 9) . $s['label'] . "\n";
        }
    }
    echo "\n分片 " . \count($shards) . " 个（tests/Unit/* + tests/Feature/* 一级目录）；";
    echo "--shard=<id> 增量选跑，分片结果不能当验收报告。\n";
    exit(0);
}

if ($stepId !== null) {
    if (!isset($steps[$stepId])) {
        \fwrite(STDERR, "未知 step：{$stepId}（已知：" . \implode(', ', \array_keys($steps)) . "）\n");
        exit(2);
    }
    $selected = [$stepId => $steps[$stepId]];
    $mode     = 'step';
} elseif ($shardId !== null) {
    if (!isset($shards[$shardId])) {
        \fwrite(STDERR, "未知分片：{$shardId}（用 --list 看清单）\n");
        exit(2);
    }
    $selected = [$shardId => $shards[$shardId]];
    $mode     = 'shard';
} elseif ($layer === 'corpus') {
    $selected = ['corpus' => [
        'id' => 'corpus', 'layer' => 'corpus', 'tier' => 'full', 'class' => 'corpus',
        'label'   => '缺陷题库 V2 对照（一次性）',
        'command' => [PHP_BINARY, $root . '/tests/Contracts/run-corpus.php',
            '--suite=v2', '--tier=full', '--json'],
    ]];
    $mode = 'corpus';
} else {
    $selected = \array_filter($steps, static function (array $s) use ($layer, $full, $device): bool {
        if ($s['layer'] === 'device') {
            return $device;
        }
        if ($layer !== 'all' && $s['layer'] !== $layer) {
            return false;
        }
        if ($s['tier'] === 'full' && !$full) {
            return false;
        }

        return true;
    });
    $mode = 'run';
}

if ($selected === []) {
    \fwrite(STDERR, "没选到任何 step（没测到，不是通过）；用 --list 看计划\n");
    exit(2);
}

foreach ($selected as $id => $s) {
    $bad = verify_missing($root, $s['command']);
    if ($bad !== []) {
        \fwrite(STDERR, "verify: step {$id} 前置依赖缺失（没测到，不是通过）："
            . \implode(', ', $bad) . "\n");
        exit(2);
    }
}

$runSteps = [];
foreach ($selected as $s) {
    $runSteps[] = ['id' => $s['id'], 'label' => $s['label'], 'command' => $s['command']];
}
$results = verify_run_steps($root, $runSteps);

// 单通道 / corpus：输出与退出码原样透传，不做汇总、不归一化。
if ($mode === 'step' || $mode === 'corpus') {
    $r = $results[0];
    \fwrite(STDOUT, $r['stdout']);
    \fwrite(STDERR, $r['stderr']);
    exit($r['exitCode'] === 127 ? 2 : $r['exitCode']);
}

$failed      = \array_values(\array_filter($results, static fn(array $r): bool => $r['exitCode'] !== 0));
$notMeasured = \array_values(\array_filter($results, static fn(array $r): bool => $r['exitCode'] === 127));
$code = $notMeasured !== [] ? 2 : ($failed !== [] ? 1 : 0);

if ($json) {
    echo \json_encode([
        'ok'       => $code === 0,
        'layer'    => $layer,
        'full'     => $full,
        'partial'  => $mode === 'shard',
        'items'    => \array_map(static fn(array $r): array => [
            'id' => $r['id'], 'status' => $r['exitCode'] === 0 ? 'ok' : 'fail',
            'exitCode' => $r['exitCode'], 'durationMs' => $r['durationMs'],
            'stdout' => $r['stdout'], 'stderr' => $r['stderr'],
        ], $results),
        'exitCode' => $code,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
    exit($code);
}

foreach ($results as $r) {
    echo '  ' . ($r['exitCode'] === 0 ? 'ok  ' : 'FAIL') . '  '
        . \str_pad($r['id'], 14) . $r['label'] . '（' . $r['durationMs'] . "ms）\n";
    if ($r['exitCode'] !== 0) {
        foreach (['stdout' => $r['stdout'], 'stderr' => $r['stderr']] as $k => $v) {
            if (\trim($v) !== '') {
                echo '        [' . $k . '] ' . \trim($v) . "\n";
            }
        }
    }
}
echo $failed === []
    ? '  ---  ' . \count($results) . " 项全过 ---\n"
    : '  ---  ' . \count($failed) . ' / ' . \count($results) . " 项红 ---\n";
if ($mode === 'shard') {
    echo '  ⚠ 分片结果只是增量反馈，不能当验收报告（覆盖率看 --full + corpus）。' . "\n";
}
exit($code);
