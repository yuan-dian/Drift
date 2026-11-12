<?php
declare(strict_types=1);

/**
 * gate 的组执行器：把一个组里的判据跑完再汇总。
 *
 * ★ 只做编排，判据实现住各自的 `*-check.php`，这里一行不改。
 * ★ 组内不 fail-fast：一次看全失败。退出码 0 全过 / 1 有项红 / 2 用法错。
 * ★ 每次正常跑先自证，且期望不读 `CheckRegistry::groups()`：合成 step 反证失败冒泡，
 *   磁盘上带 `--selftest` 的脚本反证清单完整。
 *
 * 用法：
 *   php tools/group-run.php --group=tooling
 *   php tools/group-run.php --group=tooling --only=abi,abiself
 *   php tools/group-run.php --group=tooling --selftest
 *   php tools/group-run.php --group=tooling --json
 */

require __DIR__ . '/autoload.php';

use Drift\Dev\Support\CheckRegistry;

$root = dirname(__DIR__);
$args = array_slice($argv, 1);

/** @param list<string> $args */
function group_arg(array $args, string $name): ?string
{
    foreach ($args as $a) {
        if (str_starts_with($a, '--' . $name . '=')) {
            return substr($a, strlen($name) + 3);
        }
    }

    return null;
}

function group_usage(): never
{
    fwrite(STDERR, "用法：php tools/group-run.php --group=<name> [--only=<id,...>] [--json] [--selftest]\n");
    exit(2);
}

/**
 * @param list<array{id:string,label:string,command:list<string>}> $steps
 * @return list<array{id:string,label:string,exitCode:int,stdout:string,stderr:string,durationMs:int}>
 */
function group_run_steps(string $root, array $steps): array
{
    $out = [];
    foreach ($steps as $s) {
        $t0 = hrtime(true);
        $proc = proc_open(
            $s['command'],
            [0 => ['file', 'nul', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($proc)) {
            $out[] = ['id' => $s['id'], 'label' => $s['label'], 'exitCode' => 127,
                'stdout' => '', 'stderr' => '启动失败', 'durationMs' => 0];
            continue;
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $so = '';
        $se = '';
        $code = -1;
        while (true) {
            $st = proc_get_status($proc);
            $so .= (string) stream_get_contents($pipes[1]);
            $se .= (string) stream_get_contents($pipes[2]);
            if (!$st['running']) {
                $code = (int) $st['exitcode'];
                break;
            }
            usleep(10000);
        }
        $so .= (string) stream_get_contents($pipes[1]);
        $se .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        $out[] = [
            'id' => $s['id'], 'label' => $s['label'], 'exitCode' => $code,
            'stdout' => $so, 'stderr' => $se,
            'durationMs' => (int) round((hrtime(true) - $t0) / 1e6),
        ];
    }

    return $out;
}

/**
 * @param list<array{id:string,label:string,command:list<string>}> $steps
 * @return list<string> 问题描述（空 = 自证过）
 */
function group_self_check(string $root, array $steps): array
{
    $bad = [];

    // ① 合成 step：过 / 红 / 红。红必须冒泡，且两条都要真跑到（不许 fail-fast）。
    $synth = [
        ['id' => 'ok', 'label' => '合成·过', 'command' => [PHP_BINARY, '-r', 'exit(0);']],
        ['id' => 'red1', 'label' => '合成·红1', 'command' => [PHP_BINARY, '-r', 'fwrite(STDERR,"red1");exit(1);']],
        ['id' => 'red2', 'label' => '合成·红2', 'command' => [PHP_BINARY, '-r', 'fwrite(STDERR,"red2");exit(1);']],
    ];
    $r = group_run_steps($root, $synth);
    if (count($r) !== 3) {
        $bad[] = '合成自证没跑完 3 条（执行器可能 fail-fast）';
    } else {
        if ($r[0]['exitCode'] !== 0) {
            $bad[] = '合成·过 被判红';
        }
        if ($r[1]['exitCode'] === 0 || $r[2]['exitCode'] === 0) {
            $bad[] = '合成红 step 的失败被吞了';
        }
        if (!str_contains($r[2]['stderr'], 'red2')) {
            $bad[] = '第二条红没被跑到（执行器可能提前退出）';
        }
    }

    // ② 期望来自磁盘：带 --selftest 的 *-check.php 必须各有一对 step。
    $expected = [];
    foreach (glob($root . '/tools/*-check.php') ?: [] as $f) {
        if (str_contains((string) file_get_contents($f), '--selftest')) {
            $expected[] = basename($f);
        }
    }
    if ($expected === []) {
        $bad[] = '磁盘上找不到带 --selftest 的对账器（期望来源失效）';
    }
    foreach ($expected as $name) {
        $mine = array_values(array_filter($steps, static function (array $s) use ($name): bool {
            foreach ($s['command'] as $part) {
                if (basename((string) $part) === $name) {
                    return true;
                }
            }

            return false;
        }));
        if (count($mine) !== 2) {
            $bad[] = $name . ' 应有判据 + 自证 2 条，实得 ' . count($mine) . ' 条';
            continue;
        }
        $withSelf = count(array_filter($mine,
            static fn(array $s): bool => in_array('--selftest', $s['command'], true)));
        if ($withSelf !== 1) {
            $bad[] = $name . ' 的 2 条里必须恰好 1 条带 --selftest';
        }
    }

    return $bad;
}

$group = group_arg($args, 'group');
if ($group === null || $group === '') {
    group_usage();
}
$groups = CheckRegistry::groups($root);
if (!isset($groups[$group])) {
    fwrite(STDERR, '未知组：' . $group . '（已知：' . implode(', ', array_keys($groups)) . "）\n");
    exit(2);
}
$steps = $groups[$group];
$json = in_array('--json', $args, true);
$selfOnly = in_array('--selftest', $args, true);

$problems = group_self_check($root, $steps);
if ($problems !== []) {
    foreach ($problems as $p) {
        fwrite(STDERR, '  FAIL  组自证：' . $p . "\n");
    }
    exit(1);
}
if ($selfOnly) {
    echo '  ok    组自证通过（' . $group . "）\n";
    exit(0);
}

$onlyArg = group_arg($args, 'only');
$only = $onlyArg === null
    ? null
    : array_values(array_filter(array_map('trim', explode(',', $onlyArg))));
if ($only !== null) {
    $known = array_column($steps, 'id');
    foreach ($only as $id) {
        if (!in_array($id, $known, true)) {
            fwrite(STDERR, '未知组内判据：' . $id . '（已知：' . implode(', ', $known) . "）\n");
            exit(2);
        }
    }
    $steps = array_values(array_filter($steps,
        static fn(array $s): bool => in_array($s['id'], $only, true)));
    if ($steps === []) {
        group_usage();
    }
}

$results = group_run_steps($root, $steps);
$failed = array_values(array_filter($results, static fn(array $r): bool => $r['exitCode'] !== 0));

if ($json) {
    $items = array_map(static fn(array $r): array => [
        'id' => $r['id'], 'status' => $r['exitCode'] === 0 ? 'ok' : 'fail',
        'exitCode' => $r['exitCode'], 'durationMs' => $r['durationMs'],
        'stdout' => $r['stdout'], 'stderr' => $r['stderr'],
    ], $results);
    echo json_encode(['group' => $group, 'items' => $items, 'ok' => $failed === []],
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
    exit($failed === [] ? 0 : 1);
}

foreach ($results as $r) {
    echo '  ' . ($r['exitCode'] === 0 ? 'ok  ' : 'FAIL') . '  '
        . str_pad($r['id'], 14) . ' ' . $r['label'] . '（' . $r['durationMs'] . "ms）\n";
    if ($r['exitCode'] !== 0) {
        foreach (['stdout' => $r['stdout'], 'stderr' => $r['stderr']] as $k => $v) {
            if (trim($v) !== '') {
                echo '        [' . $k . '] ' . trim($v) . "\n";
            }
        }
    }
}
echo $failed === []
    ? '  ---  ' . count($results) . " 项全过 ---\n"
    : '  ---  ' . count($failed) . ' / ' . count($results) . " 项红 ---\n";
exit($failed === [] ? 0 : 1);
