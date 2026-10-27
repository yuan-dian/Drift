<?php
declare(strict_types=1);

/**
 * `probe-real` —— **真机定位外壳**（P1'）：按**身份**驱动真机，不再手量像素坐标。
 *
 * ```bash
 * php tools/probe-real.php --app=App\Showcase\ShowcaseApplication --point=path:0.0.0.1.0.0.0.0.7
 * php tools/probe-real.php --app=… --point=path:… --do=dblclick --tag=cell
 * php tools/probe-real.php --app=… --point=… --do=wheel --delta=120 --tag=scroll
 * ```
 *
 * ★★ 为什么需要它（2026-10-27）：真机验证此前只能**手量坐标** —— 导航差 8–10px
 *   就点在盒外，且**完全静默**（不报错、不生效）。定位核心（`inspect.php` 的
 *   `--point/--emit`）能按 key / 结构路径算出坐标，但要把它接到"起进程 → 点 →
 *   截图 → 关窗"这条真机链上，还差一个壳。本脚本就是那个壳：
 *
 *     身份（key / path） → 离线定位 → 真机动作 → 前后两张图
 *
 * ★★ **尺寸不靠人填**：真机客户区尺寸（含 DPI 虚拟化）只有**跑起来**才知道
 *   （实测 1916×1006，不是想当然的 1920×1017）。所以流程是**先截基线图**拿
 *   `client=WxH`，再按这个尺寸离线挂载定位 ⇒ 坐标系天然对齐，无需 `--w/--h`。
 *
 * ★ 三条真机坑（血泪，别删）：
 *   ① **GUI exe 必须走 PHP `proc_open` 持管道**：bash `&` 起的 GUI 进程会被沙箱
 *      回收 ⇒ 1.5 秒返回、rc=0、什么都没发生。
 *   ② **截图不能带 `--no-grab`**：那个分支直接 `return 0` ⇒ `--png` 永不执行、
 *      rc 仍是 0（静默不存图）。`--png` 路径还必须是 **Windows 反斜杠**（WIC）。
 *   ③ **`--click` 是客户区坐标**，不要 `ClientToScreen`（WM_LBUTTONDOWN 的 lParam
 *      本就是客户区坐标，多换算一次就整体偏一个窗口原点）。
 *
 * ★ 本脚本是**驱动器**不是判据：它产出"定位到了谁 + 前后两张图"，判据交给
 *   golden / 像素比对。分层纪律见 `09` §8。
 */

require __DIR__ . '/autoload.php';

/** @return array<string, string> */
function realArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $body = substr($arg, 2);
        $eq = strpos($body, '=');
        if ($eq === false) {
            $out[$body] = '1';
        } else {
            $out[substr($body, 0, $eq)] = substr($body, $eq + 1);
        }
    }

    return $out;
}

/**
 * @param list<string> $extra
 * @return array{0: string, 1: int}
 */
function runGrab(string $grab, string $image, array $extra): array
{
    $command = sprintf('"%s" --auto --image=%s', $grab, $image);
    foreach ($extra as $flag) {
        $command .= ' ' . $flag;
    }
    $out = [];
    exec($command . ' 2>&1', $out, $rc);

    return [implode("\n", $out), $rc];
}

$args = realArgs($argv);
$appClass = $args['app'] ?? '';
$selector = $args['point'] ?? '';
$exe = $args['exe'] ?? 'showcase.exe';
$tag = $args['tag'] ?? 'real';
$do = $args['do'] ?? 'click';
$delta = (int) ($args['delta'] ?? 120);
$anchor = $args['anchor'] ?? 'center';

if ($appClass === '' || $selector === '') {
    fwrite(STDERR, "用法：php tools/probe-real.php --app=<Application类> --point=<key|path:|x,y> [--do=click|dblclick|move|wheel|hwheel] [--tag=名]\n");
    exit(2);
}

$root = dirname(__DIR__);
$grab = $root . '/tools/probe-grab.exe';
$imageName = $exe;

// ── ① 起进程（坑 ①）──
$pipes = [];
$proc = proc_open($root . '/' . $exe, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
if (!is_resource($proc)) {
    fwrite(STDERR, "起不来 {$exe}\n");
    exit(1);
}
usleep(2000000);

// ── ② 基线图：**只为拿 client=WxH**（坑 ② 不能带 --no-grab）──
$before = $root . '\\_build\\probe-' . $tag . '-before.png';
[$outBefore, $rcBefore] = runGrab($grab, $imageName, ['--settle=400', '--png=' . $before]);
echo "基线 rc={$rcBefore} " . str_replace("\n", ' ', $outBefore) . "\n";

if (preg_match('/client=(\d+)x(\d+)/', $outBefore, $m) !== 1) {
    fwrite(STDERR, "拿不到客户区尺寸（窗口没起来？）⇒ 无法定位\n");
    runGrab($grab, $imageName, ['--settle=200', '--no-grab', '--close']);
    usleep(400000);
    foreach ($pipes as $p) {
        fclose($p);
    }
    proc_terminate($proc, 9);
    proc_close($proc);
    exit(1);
}
$width = (int) $m[1];
$height = (int) $m[2];
echo "客户区 {$width}×{$height}\n";

// ── ③ 离线定位：按**真机尺寸**挂应用根 ⇒ 矩形即窗口坐标 ──
$locateCmd = sprintf(
    'php %s --app=%s --w=%d --h=%d --point=%s --anchor=%s --emit=%s --delta=%d',
    escapeshellarg($root . '/tools/inspect.php'),
    escapeshellarg($appClass),
    $width,
    $height,
    escapeshellarg($selector),
    escapeshellarg($anchor),
    escapeshellarg($do),
    $delta,
);
$locOut = [];
exec($locateCmd . ' 2>&1', $locOut, $locRc);
$flag = '';
foreach ($locOut as $line) {
    if (str_starts_with(trim($line), '--')) {
        $flag = trim($line);
    }
}
echo '定位 ' . implode(' | ', array_map('trim', $locOut)) . "\n";
if ($flag === '') {
    fwrite(STDERR, "定位失败 ⇒ 真机动作放弃（不猜坐标）\n");
    runGrab($grab, $imageName, ['--settle=200', '--no-grab', '--close']);
    usleep(400000);
    foreach ($pipes as $p) {
        fclose($p);
    }
    proc_terminate($proc, 9);
    proc_close($proc);
    exit(1);
}

// ── ④ 真机动作 ──
[$outAct, $rcAct] = runGrab($grab, $imageName, ['--settle=500', $flag, '--no-grab']);
echo "动作 rc={$rcAct} " . str_replace("\n", ' ', $outAct) . "\n";
usleep(700000);

// ── ⑤ 动作后截图 ──
$after = $root . '\\_build\\probe-' . $tag . '-after.png';
[$outAfter, $rcAfter] = runGrab($grab, $imageName, ['--settle=400', '--png=' . $after]);
echo "动作后 rc={$rcAfter} " . str_replace("\n", ' ', $outAfter) . "\n";

// ── ⑥ 关窗 ──
runGrab($grab, $imageName, ['--settle=200', '--no-grab', '--close']);
usleep(400000);
foreach ($pipes as $p) {
    fclose($p);
}
proc_terminate($proc, 9);
proc_close($proc);

echo "产物 before={$before}\n      after ={$after}\n";
exit(0);
