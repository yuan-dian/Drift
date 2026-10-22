<?php
declare(strict_types=1);
// 真机闸门探针：build/frame.trace + build/layout.check 两个文件开关打开时跑 users.exe，
// 靠**进程外部**读 build/*.out 报告文件来验证「0 条段顺序违规」/「mismatches === 0」。
//
// ★★ 为什么走 PHP proc_open：users.exe 是 GUI 子系统（no-console），bash 起的这种
//    进程会被沙箱回收 ⇒ 现象是"1.5 秒返回、rc=0、没有任何产出"（MEMORY 既有结论）。
//
// 用法：php _build/probe-frame-gates.php [等待秒数] [是否驱动输入]
$root = dirname(__DIR__);
$waitSeconds = (int) ($argv[1] ?? 6);
$drive = ($argv[2] ?? '1') !== '0';

$say = static function (string $s): void {
    fwrite(STDOUT, $s . "\n");
    flush();
};

// ── ① 打开两个文件开关（与报告同目录 ⇒ 目录一定存在，写不会静默失败）──────────
foreach (['build/frame.trace', 'build/layout.check'] as $sw) {
    file_put_contents($root . '/' . $sw, "on\n");
}
foreach (['build/frame.trace.out', 'build/layout.check.out'] as $out) {
    @unlink($root . '/' . $out);
}

// ── ② 起进程 ────────────────────────────────────────────────────────────────
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open($root . '/users.exe', $descriptors, $pipes, $root);
if (!is_resource($proc)) {
    $say('launch FAILED');
    exit(1);
}

$readOut = static function (string $path): ?array {
    if (!is_file($path)) {
        return null;
    }
    $text = (string) file_get_contents($path);
    $out = [];
    foreach (explode("\n", $text) as $line) {
        if (preg_match('/^(\w+)\s+(\d+)$/', trim($line), $m) === 1) {
            $out[$m[1]] = (int) $m[2];
        }
    }

    return $out === [] ? null : $out;
};

// ── ③ 等报告文件出现（证明开关真的被翻开、帧真的在跑）──────────────────────
$frameReport = $root . '/build/frame.trace.out';
$layoutReport = $root . '/build/layout.check.out';
$deadline = microtime(true) + 12.0;
$seen = null;
while (microtime(true) < $deadline) {
    $seen = $readOut($frameReport);
    if ($seen !== null) {
        break;
    }
    usleep(200000);
}
if ($seen === null) {
    $say('FAIL: 12 秒内没出现 build/frame.trace.out —— 开关没被翻开或一帧都没跑');
    foreach ($pipes as $p) {
        fclose($p);
    }
    proc_terminate($proc);
    exit(2);
}
$say('开关已翻开，首份报告：' . json_encode($seen, JSON_UNESCAPED_UNICODE));

// ── ④ 驱动输入产生帧（可选）────────────────────────────────────────────────
// 帧时钟是自适应的（Bootstrap: pace(wantsFrame())）：没有活就睡，不会空转。
// ⇒ 想要 300 帧必须真的有事做。用 --mdrag 在列表区来回挪物理光标：
//    每步一次 WM_MOUSEMOVE ⇒ 命中不同行 ⇒ hover/光标形状变化 ⇒ 产脏 ⇒ 一帧。
if ($drive) {
    $grab = $root . '/tools/probe-grab.exe';
    $steps = 0;
    $end = microtime(true) + $waitSeconds;
    $dir = 1;
    while (microtime(true) < $end) {
        $y = 300 + $dir * 120;
        $y2 = 300 - $dir * 120;
        $cmd = sprintf(
            '"%s" --auto --image=users.exe --settle=60 --mdrag=120,%d,120,%d --wheel-wait=16 --no-grab',
            $grab,
            $y,
            $y2
        );
        $out = [];
        exec($cmd . ' 2>&1', $out, $rc);
        $steps++;
        $dir = $dir === 1 ? -1 : 1;
    }
    $say('驱动输入：' . $steps . ' 轮 mdrag');
} else {
    sleep($waitSeconds);
}

// ── ⑤ 读最终报告 ───────────────────────────────────────────────────────────
usleep(300000);
$frame = $readOut($frameReport);
$layout = $readOut($layoutReport);
$say('--- build/frame.trace.out ---');
$say((string) file_get_contents($frameReport));
$say('--- build/layout.check.out ---');
$say((string) file_get_contents($layoutReport));

// ── ⑥ 优雅关窗（WM_CLOSE，让 persist 跑到）─────────────────────────────────
exec(sprintf(
    '"%s" --auto --image=users.exe --settle=200 --no-grab --close 2>&1',
    $root . '/tools/probe-grab.exe'
), $closeOut, $closeRc);
$say('close rc=' . $closeRc . ' ' . implode(' ', $closeOut));

usleep(400000);
foreach ($pipes as $p) {
    fclose($p);
}
proc_terminate($proc, 9);
proc_close($proc);

// ── ⑦ 判据 ────────────────────────────────────────────────────────────────
$ok = true;
if ($frame === null || !isset($frame['violations'])) {
    $say('FAIL: frame.trace.out 读不到 violations');
    $ok = false;
} elseif ($frame['violations'] !== 0) {
    $say('FAIL: 段顺序违规 ' . $frame['violations'] . ' 条');
    $ok = false;
}
if ($layout === null || !isset($layout['mismatches'])) {
    $say('FAIL: layout.check.out 读不到 mismatches');
    $ok = false;
} elseif ($layout['mismatches'] !== 0) {
    $say('FAIL: 布局不纯 ' . $layout['mismatches'] . ' 次');
    $ok = false;
}

$say($ok ? 'PASS' : 'FAIL');
exit($ok ? 0 : 3);
