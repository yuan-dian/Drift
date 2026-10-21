<?php
declare(strict_types=1);

/**
 * 跑 bench.exe 并等它自己退出，然后把 `build/bench-out.txt` 打出来。
 *
 * ★★ 为什么不能直接 `./bench.exe`（三个坑，都表现为"看不出根因"）：
 *   1. bench 是 **GUI 子系统**（`no-console: true`）⇒ bash 起的这种进程会被
 *      沙箱回收，现象是"1.5 秒返回、rc=0、没有任何产出"。必须 PHP `proc_open`
 *      持管道（MEMORY 的既有结论，与 `_build/probe-shot.php` 同形）。
 *   2. **`build/m4.on` 在不在决定跑哪一支**：`Runner::run()` 开头就分流 ——
 *      有这个文件只跑 M4（要窗口），写 `build/bench-m4.txt`；没有才跑 M0–M3。
 *      M4 真机压测留下的 `build/m4.on` 忘删 ⇒ 无头基准"跑完"但什么都不产出。
 *   3. 输出是 `build/bench-out.txt`，**相对 cwd** ⇒ 必须从仓库根跑。
 *
 * 用法（从仓库根）：`php tools/bench-run.php`
 */

$root = __DIR__ . '/..';
$exe = $root . '/bench.exe';
$out = $root . '/build/bench-out.txt';

$say = static function (string $s): void {
    fwrite(STDOUT, $s . "\n");
    flush();
};

if (!is_file($exe)) {
    $say('bench.exe 不存在，先 php tools/drift-build.php bench');
    exit(1);
}

// ★ 坑 2：`build/m4.on` 在 ⇒ Runner 只跑 M4（要窗口），无头基准什么都不产出。
//   不自动删：破坏性动作要人确认，哪怕它只是个 0 字节的开关文件。
if (is_file($root . '/build/m4.on')) {
    $say('✗ build/m4.on 存在 ⇒ Runner 只会跑 M4 分支并写 build/bench-m4.txt。');
    $say('  这是 M4 真机压测留下的开关。要跑无头基准请先：');
    $say('      rm build/m4.on        # 之后可用 touch build/m4.on 恢复');
    exit(3);
}

if (is_file($out)) {
    \unlink($out);
}

$t0 = \microtime(true);
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open($exe, $descriptors, $pipes);
if (!\is_resource($proc)) {
    $say('launch FAILED');
    exit(1);
}

// GUI 子系统没有 stdout 可读；只把管道读干，避免它塞满缓冲区
$err = '';
foreach ($pipes as $p) {
    stream_set_blocking($p, false);
}

$code = null;
$deadline = \microtime(true) + 600.0;
while (true) {
    $status = proc_get_status($proc);
    if (!$status['running']) {
        $code = $status['exitcode'];
        break;
    }
    if (\microtime(true) > $deadline) {
        $say('TIMEOUT 600s，杀进程');
        proc_terminate($proc, 9);
        $code = -1;
        break;
    }
    foreach ($pipes as $p) {
        $chunk = fread($p, 8192);
        if ($chunk !== false && $chunk !== '') {
            $err .= $chunk;
        }
    }
    usleep(200000);
}

foreach ($pipes as $p) {
    \fclose($p);
}
proc_close($proc);

$ms = (int) ((\microtime(true) - $t0) * 1000.0);
$say(\sprintf('bench.exe exit=%d · %d ms', (int) $code, $ms));

if ($err !== '') {
    $say('--- stderr/stdout ---');
    $say($err);
}

if (!is_file($out)) {
    $say('✗ build/bench-out.txt 没生成');
    exit(2);
}

$say('--- build/bench-out.txt ---');
$say(\file_get_contents($out));
exit(0);
