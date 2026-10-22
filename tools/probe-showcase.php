<?php
declare(strict_types=1);

/**
 * showcase.exe 真机验收探针：起进程 → 等窗口 → 点导航 → 截 N 张图 → 优雅关窗。
 *
 * 用法：php tools/probe-showcase.php [navX] [navY] [tag]
 *   navX/navY = 0   只截首屏（用来**量**导航坐标）
 *   navX/navY 给值  点一下再截 3 张（静态 + 两个动画中间帧）
 *
 * ★★ 三个只有真机跑一遍才知道的坑：
 *   ① **GUI exe 必须走 PHP `proc_open` 持管道**：bash `&` 起的 GUI 进程会被
 *      沙箱回收 ⇒ 1.5 秒返回、rc=0、什么产出都没有（静默）。
 *   ② **probe-grab 截图不能带 `--no-grab`**：那个分支是"只做控制 + 打印，
 *      不碰像素"⇒ 直接 `return 0`，`--png` 永远不会执行（rc 仍是 0，
 *      静默不存图）。存图路径还必须是 Windows 风格（反斜杠）—— WIC 的规矩。
 *   ③ **导航坐标每次重新量，不跨会话移植**：选中项是墨绿 `#009688` 的
 *      一条带（x=25 列扫出来），未选中是透明 ⇒ pitch 只能从"两个选中带"
 *      或"文字列"反推，而且导航项会折行（"长列表 Big"占两行）⇒ 间距不均。
 *      目测差 8–10px 就点在盒外、**完全不生效且静默**。
 *      W1 实测量（1920×1017 客户区）：Layout(0) 带 center=69、Feedback(7)
 *      带 center=313 ⇒ pitch≈35；自绘 Paint(6) = 279。
 *      点的验证方式：点完扫 x=25 的绿带，看它有没有移到目标行。
 */

$root = dirname(__DIR__);
$say = static function (string $s): void {
    fwrite(STDOUT, $s . "\n");
    flush();
};

$navX = (int) ($argv[1] ?? 0);   // 导航按钮的 x（0 = 只截首屏不定点）
$navY = (int) ($argv[2] ?? 0);   // 导航按钮的行 y
$tag = $argv[3] ?? 'probe';

$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$proc = proc_open($root . '/showcase.exe', $descriptors, $pipes, $root);
if (!is_resource($proc)) {
    $say('launch FAILED');
    exit(1);
}
$say('pid launched');

// 等窗口起来（probe-grab --auto 找不到窗口会报错 ⇒ 先睡足）
usleep(1800000);

$grab = $root . '/tools/probe-grab.exe';
// ★ probe-grab 的 --png 走 WIC ⇒ 路径必须是 Windows 风格（反斜杠）。
// ★★ **截图不能带 --no-grab**：那个分支"只做控制 + 打印，不碰像素"⇒ 直接
//   return 0，--png 永远不会被执行（rc 仍是 0，静默不存图）。
$shot = static function (string $name) use ($grab, $root, $say): void {
    $target = $root . '\\' . $name;
    $out = [];
    exec(sprintf('"%s" --auto --image=showcase.exe --settle=400 --png=%s 2>&1', $grab, $target), $out, $rc);
    $ok = is_file($target) ? 'saved' : 'MISSING';
    $say($name . ' rc=' . $rc . ' ' . $ok . ' ' . implode(' ', $out));
};

$shot('_build/showcase-' . $tag . '-home.png');

if ($navX > 0 && $navY > 0) {
    // 点导航按钮（PostMessage 点击，probe-grab --click=x,y）
    $out = [];
    exec(sprintf('"%s" --auto --image=showcase.exe --settle=500 --click=%d,%d --no-grab 2>&1', $grab, $navX, $navY), $out, $rc);
    $say('click rc=' . $rc . ' ' . implode(' ', $out));
    usleep(600000);

    // 图 1：静态部分（路径填充 + 圆形裁剪）
    $shot('_build/showcase-' . $tag . '-1-paint.png');

    // 图 2/3：动画中间帧（CustomPaintPage 有每帧指针行程）
    usleep(350000);
    $shot('_build/showcase-' . $tag . '-2-mid.png');
    usleep(350000);
    $shot('_build/showcase-' . $tag . '-3-mid.png');
}

// 优雅关窗
$out = [];
exec(sprintf('"%s" --auto --image=showcase.exe --settle=200 --no-grab --close 2>&1', $grab), $out, $rc);
$say('close rc=' . $rc . ' ' . implode(' ', $out));

usleep(400000);
foreach ($pipes as $p) {
    fclose($p);
}
proc_terminate($proc, 9);
proc_close($proc);
$say('done');
