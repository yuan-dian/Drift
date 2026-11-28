<?php
declare(strict_types=1);

/**
 * `Build\Supervisor` 的进程级判据：该杀的杀掉、不该杀的活下来、孙子也跑不掉。
 *
 * ★ 为什么不是纯单测：这里判的是「进程真的从机器上消失了」这种外部事实。
 *   只断言 `run()` 的返回值等于自己骗自己 —— kill 命令没生效，它照样返回
 *   `stalled=true`，而那正是"看着有看门狗、实际上什么也没清掉"的假绿。
 * ★ 每条正面判据都配一条反向：会判死的那侧要判死，不该判死的那侧必须活下来。
 *   少了反向，"看门狗"和"随机杀人"分不出来；少了正面，"从不判死"也是绿的。
 *
 * 用法：
 *   php tools/build-supervise-check.php            # 全部判据，exit 0/1
 *   php tools/build-supervise-check.php --keep     # 留下临时目录（排查用）
 *
 * ★ 这段历史（2026-11-28）：tpc 收尾死锁时脚本无限等；人手动杀进程又留下握 PDB
 *   锁的孤儿 ⇒ "卡一次之后次次卡"。所以判据的重点不是"能超时"，是
 *   **判死之后机器上真的没有残留**。
 *
 * ★ 本脚本第一次跑就抓到一个真 bug：`stream_get_contents()` 在 Windows 匿名管道上
 *   阻塞到 EOF，所以看门狗循环根本没转 —— 判定"停滞"那条在 30s 后才返回。
 *   现在实现走日志文件 + 偏移读取，判据也照那条路走（同源，但不是同一份代码）。
 */

use Drift\Dev\Build\Supervisor;

require_once __DIR__ . '/Build/Supervisor.php';

/** 起一个把输出写进日志文件的子进程（与 `Supervisor` 的接线方式一致） */
function sup_spawn(string $dir, string $code, array $args = []): array
{
    static $seq = 0;
    $seq++;
    $out = "{$dir}/out-{$seq}.log";
    $err = "{$dir}/err-{$seq}.log";
    $null = PHP_OS_FAMILY === 'Windows' ? 'nul' : '/dev/null';
    $process = proc_open(
        [PHP_BINARY, '-r', $code, ...$args],
        [0 => ['file', $null, 'r'], 1 => ['file', $out, 'w'], 2 => ['file', $err, 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('起不了子进程');
    }
    $pid = (int) (proc_get_status($process)['pid'] ?? 0);

    return [$process, $out, $err, $pid];
}

function sup_close($process): void
{
    if (is_resource($process)) {
        @proc_close($process);
    }
}

/** 等文件里出现一个正整数 PID（子进程落盘有延迟） */
function sup_wait_pid(string $file, float $timeout = 3.0): int
{
    $deadline = microtime(true) + $timeout;
    while (microtime(true) < $deadline) {
        if (is_file($file)) {
            $value = trim((string) file_get_contents($file));
            if (ctype_digit($value) && (int) $value > 0) {
                return (int) $value;
            }
        }
        usleep(50000);
    }

    return 0;
}

$keep = in_array('--keep', array_slice($argv, 1), true);
$dir = sys_get_temp_dir() . '/drift-supervise-' . bin2hex(random_bytes(4));
mkdir($dir, 0777, true);
$checks = [];

// ── ① 静默子进程：必须被判死、真的被杀 ─────────────────────────────
[$proc, $out, $err, $pid] = sup_spawn($dir, 'sleep(30);');
$start = microtime(true);
$result = (new Supervisor($proc, $out, $err, 1.0))->run();
$elapsed = microtime(true) - $start;
$checks['静默 ⇒ 判停滞'] = $result['stalled'] === true;
$checks['静默 ⇒ 退出码非 0'] = $result['exit'] !== 0;
$checks['静默 ⇒ 1s 阈值、6s 内结束（没等满 30s）'] = $elapsed < 6.0;
usleep(300000);
$checks['静默 ⇒ 进程真的从机器上消失'] = $pid > 0 && !Supervisor::pidAlive($pid);
sup_close($proc);

// ── ② 持续输出：不该被判死，输出要收全 ─────────────────────────────
$seen = '';
[$proc, $out, $err, $pid] = sup_spawn(
    $dir,
    'for ($i = 0; $i < 12; $i++) { echo "tick{$i}\n"; fflush(STDOUT); usleep(150000); }',
);
$result = (new Supervisor($proc, $out, $err, 1.0, [], static function (string $chunk) use (&$seen): void {
    $seen .= $chunk;
}))->run();
$checks['持续输出 ⇒ 不判停滞（阈值 1s，每 150ms 有字节）'] = $result['stalled'] === false;
$checks['持续输出 ⇒ 正常退出'] = $result['exit'] === 0;
$checks['持续输出 ⇒ 输出收全'] = str_contains($result['output'], 'tick11');
$checks['持续输出 ⇒ 回调真被调用（透传不是空承诺）'] = str_contains($seen, 'tick11');
sup_close($proc);

// ── ③ 只落盘不输出：目录变化也算进展 ───────────────────────────────
$dropDir = "{$dir}/drop";
mkdir($dropDir, 0777, true);
$toucher = 'for ($i = 0; $i < 8; $i++) { file_put_contents($argv[1] . "/f{$i}", "x"); usleep(200000); }';

[$proc, $out, $err, $pid] = sup_spawn($dir, $toucher, [$dropDir]);
$result = (new Supervisor($proc, $out, $err, 1.0, [$dropDir]))->run();
$checks['只落盘不输出 ⇒ 不判停滞'] = $result['stalled'] === false;
sup_close($proc);

// 反向：同一段代码、同一个阈值，只是不把目录算进展 ⇒ 必须被判死
$dropDir2 = "{$dir}/drop2";
mkdir($dropDir2, 0777, true);
[$proc, $out, $err, $pid] = sup_spawn($dir, $toucher, [$dropDir2]);
$result = (new Supervisor($proc, $out, $err, 1.0, []))->run();
$checks['反例：不把目录算进展 ⇒ 同一进程被判停滞'] = $result['stalled'] === true;
sup_close($proc);

// ── ④ 杀树：tpc 起的 cl.exe 不能留成孤儿 ───────────────────────────
$grandFile = "{$dir}/grand.pid";
[$proc, $out, $err, $pid] = sup_spawn(
    $dir,
    '$pp = []; $g = proc_open([PHP_BINARY, "-r", "sleep(30);"],'
    . ' [0 => ["file", $argv[2], "r"], 1 => ["file", $argv[1] . \'.grand.log\', "w"],'
    . ' 2 => ["file", $argv[1] . \'.grand.err\', "w"]], $pp);'
    . ' file_put_contents($argv[1], (string) proc_get_status($g)["pid"]); sleep(30);',
    [$grandFile, PHP_OS_FAMILY === 'Windows' ? 'nul' : '/dev/null'],
);
$grand = sup_wait_pid($grandFile);
$result = (new Supervisor($proc, $out, $err, 1.0))->run();
usleep(300000);
$checks['孙进程 PID 落盘（判据真的在测树）'] = $grand > 0;
$checks['停滞 ⇒ 父进程消失'] = $pid > 0 && !Supervisor::pidAlive($pid);
$checks['停滞 ⇒ 孙进程一起消失（/T 生效）'] = $grand > 0 && !Supervisor::pidAlive($grand);
sup_close($proc);

// ── ⑤ 孤儿兜底：上次被 SIGTERM 截断留下的 tpc ──────────────────────
[$proc, $out, $err, $pid] = sup_spawn($dir, 'sleep(30);');
$staleFile = "{$dir}/tpc.pid";
file_put_contents($staleFile, $pid . "\n");
$reclaimed = Supervisor::reclaimStale($staleFile);
$checks['孤儿 ⇒ 认出并杀掉'] = $reclaimed === $pid;
$checks['孤儿 ⇒ pid 文件被清'] = !is_file($staleFile);
$checks['孤儿 ⇒ 进程真的消失'] = $pid > 0 && !Supervisor::pidAlive($pid);
sup_close($proc);

// 反向：指向已经不在的 PID，不能报"清掉了"，也不能去杀别人
file_put_contents($staleFile, "999999\n");
$checks['反例：指向不存在的 PID ⇒ 返回 null'] = Supervisor::reclaimStale($staleFile) === null;
$checks['反例：指向不存在的 PID ⇒ pid 文件仍被清'] = !is_file($staleFile);

// 反向：pid 文件被写成自己的 PID（写坏的情况）⇒ 绝不自杀
$selfFile = "{$dir}/self.pid";
file_put_contents($selfFile, getmypid() . "\n");
$checks['反例：pid 文件指向自己 ⇒ 返回 null（不自杀）'] = Supervisor::reclaimStale($selfFile) === null;
$checks['反例：指向自己 ⇒ 本进程还活着'] = Supervisor::pidAlive((int) getmypid());

// 反向：killTree 对非法 PID 静默返回，不抛
Supervisor::killTree(0);
Supervisor::killTree(-1);
$checks['killTree(0/-1) 不抛异常'] = true;

// ── 收尾 ───────────────────────────────────────────────────────────
if (!$keep) {
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
} else {
    fwrite(STDOUT, "[build-supervise-check] 临时目录保留：{$dir}\n");
}

$failed = 0;
foreach ($checks as $name => $ok) {
    fwrite(STDOUT, ($ok ? '  ok    ' : '  FAIL  ') . $name . "\n");
    $failed += $ok ? 0 : 1;
}
fwrite(STDOUT, "--- {$failed} FAIL / " . count($checks) . " 条判据 ---\n");
exit($failed === 0 ? 0 : 1);
