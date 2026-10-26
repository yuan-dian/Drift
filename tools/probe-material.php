<?php
declare(strict_types=1);

/**
 * 真机材质探针：窗口背后模糊（backdrop）是否**真的**生效。
 *
 *   php tools/probe-material.php              # 三组受控对照 + 断言，exit 0/1
 *   php tools/probe-material.php --keep-png   # 保留每组截图到 _build/ 供人眼复核
 *
 * ── 判据（受控对照）──────────────────────────────────────────────────────
 * 三次运行的应用内容**完全相同**，唯一变量是 backdrop 模式 ⇒ 画面差异只能来自
 * 窗口背后。全透明底色下"背后"就是桌面：桌面清晰 ⇒ `detail` 高；被模糊 ⇒
 * 高频被压掉、`detail` 低。所以 `detail(acrylic) << detail(none)` 是"模糊真的
 * 生效"唯一可证伪的指标 ——"看着糊"不是判据（2026-10-26 就靠目测把方角当成了
 * 圆角）。
 *
 * ★ `detail` 是**绝对值**，随桌面内容变化 ⇒ 一律换算成"占同矩形桌面基准的
 *   比例"，且**每组各取自己的基准**（关窗后抓同一矩形）——三次跑之间的桌面
 *   内容会变（动画、时钟），共用一份基准会引入假差异。
 *
 * ★ 判据的**前提**是基准本身有高频 —— 但这里不用绝对阈值判它。用的是
 *   **对照组锚定**：拿 `off`（透明但不模糊，同一应用同一几何）当锚，断言
 *   "acrylic 比对照至少低 0.25 / blur 至少低 0.35"。背景素时 off 与 acrylic
 *   都同样高 ⇒ 差值趋零 ⇒ 照样失败，外部变量被对照组吸收。
 *   绝对阈值（"acrylic/base < 0.60"）是历史某次 base=4.83 拍的，桌面一变就
 *   误伤：本机 base 掉到 2.75 时三组仍清楚地分出 0.94 / 0.57 / 0.35，却被
 *   绝对阈值全判死。基准 < 3.0 现在只打一条警告（结论余量小），不判失败。
 * ★ `off/base` 自身**不判断言，只打诊断**：off 组窗口里约 26% 是应用自绘内容、
 *   只有 74% 透出桌面 ⇒ 它天然小于 1，且随桌面细节与应用内容占比一起漂
 *   （实测 0.77 与 0.97 都正常）。把它当"≈1"会把正常行为判成失败。
 *   ★ 两个真实踩过的坑让这条"前提"必须存在：一次浏览器切到空白页、一次被测
 *   窗口被别的窗口压住，两次的 detail 都与基准**完全相等**（读起来像没生效）。
 *
 * ── 两条前置条件，缺一结论就是假的 ──────────────────────────────────────
 * ① `DRIFT_WINDOW_FRAMELESS=1` + 带 alpha 的底色：不透明窗每个像素都由应用画满，
 *    DWM 画在背后的模糊被整块盖住（不是失败，是**看不见**）；
 * ② 高细节背景窗口必须在被测窗口**之前**激活：新建窗口默认就是前台，顺序反了
 *    就要靠 `SetForegroundWindow` 去抢，而它有前台锁 ⇒ 时灵时不灵（实测：同一
 *    条命令，一次抓到窗口、一次抓到桌面，`detail` 与基准**完全相等**）。
 *
 * ── 为什么在 tools/ 而不是 _build/ ────────────────────────────────────────
 * `_build/` 是 gitignored 的构建工作目录，把可证伪的判据放那里 = 每次会话重造
 * 一遍。探索用的单次取证脚本仍在 `_build/backdrop-shot.php`（可指定任意 mode
 * 编号、可调色、可换窗口几何）。
 *
 * ── 不进 selftest ────────────────────────────────────────────────────────
 * 它要前台桌面、要抢焦点、每轮 3.2s 等待。闸门必须能无头反复跑 ⇒ 这条留作
 * 手动回归，不进 `selftest.php`。
 */

$root = dirname(__DIR__);
$grab = $root . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'probe-grab.exe';
$app = $root . DIRECTORY_SEPARATOR . 'users.exe';
$keepPng = in_array('--keep-png', $argv, true);

// 窗口几何：放在桌面中部偏左上，900×560 足够容纳 sidebar + 内容区。
$winX = 600;
$winY = 150;
$winW = 900;
$winH = 560;

// 高细节背景候选（按"像素高频丰富度"排序）。第一个在跑的用它。
$backdropCandidates = ['GitHubDesktop.exe', 'Code.exe', 'chrome.exe', 'msedge.exe'];

$failures = [];
$rows = [];

function fail(string $why): void
{
    global $failures;
    $failures[] = $why;
    echo "FAIL  {$why}\n";
}

/** 跑一条 probe-grab 命令，原样返 stdio 合并输出。 */
function grabRun(string $cmd): string
{
    $out = [];
    exec($cmd . ' 2>&1', $out, $rc);

    return 'rc=' . $rc . ' ' . implode(' | ', $out);
}

/** 从 `detail=6.83` 里取数；取不到返 null（调用方负责判失败）。 */
function detailOf(string $output): ?float
{
    return preg_match('/detail=([0-9]+(?:\.[0-9]+)?)/', $output, $m) === 1
        ? (float) $m[1]
        : null;
}

function processRunning(string $image): bool
{
    $out = [];
    exec('tasklist /FI "IMAGENAME eq ' . $image . '" /NH 2>&1', $out);

    return str_contains(implode("\n", $out), $image);
}

function pickBackdrop(array $candidates): ?string
{
    foreach ($candidates as $image) {
        if (processRunning($image)) {
            return $image;
        }
    }

    return null;
}

/**
 * 跑一组：激活背景 → 启动被测应用 → 抓窗 → 关窗 → 抓同矩形基准。
 *
 * @return array{mode:string, window:float, base:float, ratio:float}|null
 */
function runCase(string $mode, string $grab, string $app, string $root, string $backdrop, array $rect, bool $keepPng): ?array
{
    [$x, $y, $w, $h] = $rect;
    $area = $x . ',' . $y . ',' . $w . ',' . $h;
    $png = $root . '/_build/probe-material-' . $mode . '.png';

    // 上一次可能残留：残留窗口会被 --auto 抓到，那抓的是旧进程的画面。
    @exec('"' . $grab . '" --auto --image=users.exe --close 2>&1');
    @unlink($root . '/Errors.log');
    // 持久化几何会污染测量：restore 出来的尺寸不受 --move 完全约束。
    @unlink(getenv('APPDATA') . '/Drift/users.window');

    // ① 背景窗口先激活（见文件头 §②）
    @exec('"' . $grab . '" --auto --image=' . $backdrop . ' --activate --no-grab 2>&1');
    usleep(600 * 1000);

    // ② 启动被测应用
    $env = getenv();
    $env['DRIFT_WINDOW_FRAMELESS'] = '1';
    $env['DRIFT_WINDOW_BACKGROUND'] = '#00000000';
    $env['DRIFT_WINDOW_BACKDROP'] = $mode;
    $appLog = $root . '/_build/probe-material-' . $mode . '.app.log';
    $errLog = $root . '/_build/probe-material-' . $mode . '.err.log';
    @unlink($appLog);
    @unlink($errLog);

    $proc = proc_open(
        $app,
        [1 => ['file', $appLog, 'w'], 2 => ['file', $errLog, 'w']],
        $pipes,
        $root,
        $env,
        ['bypass_shell' => true],
    );

    if (!is_resource($proc)) {
        fail($mode . ' · 启动 users.exe 失败');

        return null;
    }

    usleep(3200 * 1000);

    // ③ 抓窗口（--screen：模糊是 DWM 画在屏幕上的，PrintWindow 抓不到）
    $grabCmd = '"' . $grab . '" --auto --image=users.exe --screen --rect --detail --activate'
        . ' --move=' . $area;
    if ($keepPng) {
        $grabCmd .= ' --png=' . $png;
    }
    $windowOut = grabRun($grabCmd);
    echo '      grab: ' . $windowOut . "\n";

    // ④ 关窗
    $closeOut = grabRun('"' . $grab . '" --auto --image=users.exe --close');
    proc_close($proc);
    echo '      close: ' . $closeOut . "\n";

    if (is_file($root . '/Errors.log')) {
        fail($mode . ' · 应用写了 Errors.log —— ' . trim((string) file_get_contents($root . '/Errors.log')));
    }

    // ⑤ 基准：关窗后同一矩形（关窗会把焦点交回背景窗口，再显式提一次更稳）
    @exec('"' . $grab . '" --auto --image=' . $backdrop . ' --activate --no-grab 2>&1');
    usleep(400 * 1000);
    $baseOut = grabRun('"' . $grab . '" --region=' . $area . ' --detail');
    echo '      base: ' . $baseOut . "\n";

    $window = detailOf($windowOut);
    $base = detailOf($baseOut);

    if ($window === null || $base === null) {
        fail($mode . ' · 取不到 detail（window=' . var_export($window, true) . ', base=' . var_export($base, true) . '）');

        return null;
    }

    return ['mode' => $mode, 'window' => $window, 'base' => $base, 'ratio' => $window / $base];
}

// ── 前置检查 ─────────────────────────────────────────────────────────────
if (!is_file($grab)) {
    fwrite(STDERR, "找不到 {$grab}（先跑 tools/drift-build.php 或确认探针工具已构建）\n");
    exit(2);
}
if (!is_file($app)) {
    fwrite(STDERR, "找不到 {$app}（先 php tools/drift-build.php users）\n");
    exit(2);
}

$backdrop = pickBackdrop($backdropCandidates);
if ($backdrop === null) {
    fwrite(STDERR, "没有可用的高细节背景窗口（候选：" . implode(' / ', $backdropCandidates) . "）\n");
    exit(2);
}

echo "背景窗口 = {$backdrop} · 矩形 = {$winW}x{$winH} @ {$winX},{$winY}\n";

foreach (['off', 'acrylic', 'blur'] as $mode) {
    echo "── {$mode} ──\n";
    $row = runCase($mode, $grab, $app, $root, $backdrop, [$winX, $winY, $winW, $winH], $keepPng);
    if ($row !== null) {
        $rows[$mode] = $row;
    }
}

// ── 断言 ─────────────────────────────────────────────────────────────────
echo "\n── 结果 ──\n";
printf("%-9s %8s %8s %8s\n", 'mode', 'window', 'base', 'ratio');
foreach ($rows as $row) {
    printf("%-9s %8.2f %8.2f %8.2f\n", $row['mode'], $row['window'], $row['base'], $row['ratio']);
}

if (!isset($rows['off'], $rows['acrylic'], $rows['blur'])) {
    fail('有一组没跑出结果，无法比对（见上面各自的失败原因）');
} else {
    // 基准细节只作**诊断**，不判失败（理由见下方"对照组锚定"）。
    $minBase = min(array_column($rows, 'base'));
    if ($minBase < 3.0) {
        echo sprintf(
            "⚠     基准 detail=%.2f 偏低（桌面本身高频就少）⇒ 结论余量变小，"
            . "建议背景换成代码 diff / 图片页\n",
            $minBase,
        );
    }

    // ① 对照组的**绝对**比值只作诊断，不判失败。
    //   ★ 曾断言过 `off/base ≈ 1`，那是错的：off 组窗口里约 26% 是应用自绘内容
    //     （侧栏 / 顶条 / 列表），只有 74% 透出桌面 ⇒ 比值**天然 < 1**，且随
    //     "桌面有多细"和"应用内容占多少"两个量一起漂。实测 0.77（基准 4.93）
    //     与 0.97（基准 4.80）都出现过 —— 差的是桌面内容，不是行为。
    //   ★ 真正蕴含"透明底生效"的是下面的降幅：ULW 没生效时窗口被应用画满，
    //     DWM 画在背后的模糊没有观察通道 ⇒ 三组 detail 几乎相同 ⇒ 降幅趋零 ⇒
    //     照样失败。所以这里不需要独立阈值，打印出来供人判断即可。
    $off = $rows['off']['ratio'];
    printf(
        "      off/base = %.2f（诊断：典型 0.75~1.0，随应用内容占比与桌面细节漂移）\n",
        $off,
    );

    // ②③ 用**对照组**做锚，而不是基准的绝对 detail。
    //   ★ 为什么不用"acrylic/base < 0.60"这种绝对阈值：那个数是历史某次测量
    //     （base=4.83）拍的，桌面内容一变就失效 —— 本机实测 base 掉到 2.75 时，
    //     比值仍清楚地分出 0.94 / 0.57 / 0.35，却会被绝对阈值全判死。
    //   ★ 相对差值自带"背景够不够细"的自检：背景素 ⇒ off≈1 且 acrylic≈1 ⇒
    //     差值趋零 ⇒ 照样失败。外部变量被对照组吸收了。
    $acrylic = $rows['acrylic']['ratio'];
    $blur = $rows['blur']['ratio'];
    $dropAcrylic = $off - $acrylic;
    $dropBlur = $off - $blur;

    // 历史实测降幅 0.64（off 0.97 → acrylic 0.33）与 0.79（→ blur 0.18），
    // 本轮 0.37 / 0.59（基准更素）。阈值取 0.25 / 0.35：容得下桌面变淡，仍能
    // 抓住"没生效"（那时降幅≈0）。
    if ($dropAcrylic < 0.25) {
        fail(sprintf(
            'acrylic 相对对照只降了 %.2f（应 ≥ 0.25）—— 模糊未生效，或背景无高频可压'
            . '（base=%.2f）',
            $dropAcrylic,
            $rows['acrylic']['base'],
        ));
    } else {
        echo sprintf("OK    acrylic 比对照低 %.2f（≥ 0.25，磨砂生效）\n", $dropAcrylic);
    }

    if ($dropBlur < 0.35) {
        fail(sprintf(
            'blur 相对对照只降了 %.2f（应 ≥ 0.35）—— 模糊未生效，或背景无高频可压'
            . '（base=%.2f）',
            $dropBlur,
            $rows['blur']['base'],
        ));
    } else {
        echo sprintf("OK    blur 比对照低 %.2f（≥ 0.35，轻模糊生效）\n", $dropBlur);
    }

    // ④ 强度序：BLURBEHIND 比 ACRYLICBLURBEHIND 压得更狠（实测 0.18 vs 0.33）。
    //    这条防的是"两个 mode 走进了同一个分支"—— 那种错上面两条都能过。
    if ($blur >= $acrylic) {
        fail(sprintf('blur(%.2f) 应 < acrylic(%.2f)：两个 mode 可能走进了同一分支', $blur, $acrylic));
    } else {
        echo sprintf("OK    blur(%.2f) < acrylic(%.2f)（两种模式确实不同）\n", $blur, $acrylic);
    }
}

echo "\n";
if ($failures === []) {
    echo "PASS  材质探针全绿（" . count($rows) . " 组）\n";
    exit(0);
}

echo 'FAIL  ' . count($failures) . " 项\n";
exit(1);
