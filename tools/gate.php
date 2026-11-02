<?php
declare(strict_types=1);

/**
 * `gate` —— 统一的 **T0** 验证入口（等价于 `flutter test` / `npx playwright test`）。
 *
 * 把散落的手动闸门收成**一条命令、一个退出码、正确顺序**（廉价优先、失败即停）。
 *
 * ★ 为什么需要它（2026-10-27）：成熟 GUI 框架都有一个 test 命令；本仓此前是
 *   "一串要人记住的脚本"——漏跑、跑错顺序是常态。golden 自动基线（P0）落地后
 *   更需要它，否则那个基线没人跑、等于没有。
 *
 * ★ 本脚本只覆盖 **T0（无头、解释器）**。AOT（T1）与真机（T2）仍是显式动作：
 *   `php tools/drift-build.php <app>`（构建）+ 真机探针。分层纪律见 `09` §8。
 *
 * 检查项（顺序即执行顺序，廉价优先；★ 标 slow 的在 `--fast` 下跳过）：
 *   doctor    环境（PHPRC / tpc / cl.exe / DPI…）
 *   lint      代码规则 · 0 error
 *   selftest  lint 规则双向自检
 *   docref    文档 file:line / § 引用
 *   probe     探针骨架（probe-audit --selftest）
 *   pixels    像素契约（probe_pixels --selftest，依赖本机 python ⇒ 允许"没测到"）
 *   tick      每帧固定税（frame-tax --check，6 条自洽判据）
 *   sym       崩溃符号翻译（rva2sym --selftest，6 条；合成 map，无构建前置）
 *   unit      纯单元
 *   feature★  场景测试（真挂载 + 推帧）
 *   golden    原语像素基线
 *   pages★    整页像素基线
 *   smoke★    端到端 smoke
 *
 * ★ 耗时**不写在这里**了（2026-11-02）：这份清单曾逐项标着"~4.6s / ~22.8s、合计 63.4s"，
 *   而实际早已是 120s 量级 —— 数字一旦没有判据就会烂，且烂了没人发现。
 *   要耗时就跑一遍看最后一行（`✓ 全部通过（N 项，Xs）`）；**项数也以那一行为准**，
 *   别把会漂的数抄进注释（加一项检查就过期一次）。
 *   历史快照（**不是当前值**，仅供"同一台机器波动有多大"的参考）：2026-11-02 12 项
 *   两次跑 135.3s / 120.8s。★ 两次差 11% —— 这本身就是「别把耗时抄进注释」的理由。
 *
 * ★ 为什么这么慢（2026-10-28 定位后的真实结构，仍未变）：
 *   ① **feature**：每例都真的建树+layout+paint（内存峰值 692MB）。这是"真挂载"
 *      的价钱，别去砍它的断言密度。
 *   ② **smoke**：主体是"必须真像素/真进程"的段落 —— 桥结构 golden、降采样灰度、
 *      常驻子进程、users 真异步（真 HTTP），另有 **38 处子进程调用点**（33 `runCase`
 *      + 5 `runFatalCase`，单次装载 0.24s）≈ 9s 开机税（"期望响亮失败"只能子进程：
 *      `Errors::report` 走 `abort()`，接不住）。
 *   ③ 想再快只剩两件事：子进程并发（省 5–9s）、各项并行（需先确认 golden/pages
 *      只读、smoke 临时目录不撞）。
 *
 * 用法：
 *   php tools/gate.php              # 全部（含整页 + smoke）
 *   php tools/gate.php --fast       # 只跑廉价项（跳过标 ★ 的慢项，日常迭代）
 *   php tools/gate.php --full       # 同默认（显式写法）
 *   php tools/gate.php --only=golden,pages,smoke
 *   php tools/gate.php --list
 *
 * 退出码：0 = 全过；否则 = **第一个失败项**的退出码（fail-fast，不再往下跑）。
 *
 * ★ 为什么用 proc_open 而不是 passthru：php.exe 的 shell 调用在 Windows 上走
 *   cmd.exe（路径带空格 / 引号会被二次解释）。数组式命令 + `bypass_shell` 直接
 *   CreateProcess，与 `drift-build.php` 同法（MEMORY 硬坑）。
 */

$root = dirname(__DIR__);

/**
 * 找 python（只有"像素契约"那项需要）。
 *
 * ★ 为什么自己找而不直接写 `'python'`：闸门用**数组式** `proc_open` +
 *   `bypass_shell`，可执行文件不存在时 `proc_open` 直接失败 ⇒ 报成
 *   "启动失败(exit 127)"，那是**环境的错**却长得像**判据的错**。
 *   这里先找，找不到就按"没测到"记（见 `$may_skip`）。
 * ★ `DRIFT_PYTHON` 可覆盖（多解释器 / venv 的机器）。
 */
function find_python(): ?array
{
    $env = getenv('DRIFT_PYTHON');
    if (is_string($env) && $env !== '') {
        return [$env];
    }

    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
        $dir = rtrim(trim($dir, '"'), '\\/');
        if ($dir === '') {
            continue;
        }
        foreach (['python.exe', 'python3.exe', 'python', 'python3'] as $name) {
            $path = $dir . DIRECTORY_SEPARATOR . $name;
            if (is_file($path)) {
                return [$path];
            }
        }
    }

    return null;
}

$python = find_python();

/**
 * @var array<string, array{0: string, 1: list<string>, 2: bool}> 键 = 短名，
 *      值 = [显示名, 命令, 是否慢项]
 */
$checks = [
    'doctor'   => ['环境（doctor）', [PHP_BINARY, $root . '/tools/doctor.php', '--quiet'], false],
    'lint'     => ['lint', [PHP_BINARY, $root . '/tools/lint.php'], false],
    'selftest' => ['lint 自检', [PHP_BINARY, $root . '/tools/lint.php', '--selftest'], false],
    'docref'   => ['docref', [PHP_BINARY, $root . '/tools/docref.php'], false],
    'probe'    => ['探针骨架（probe-audit）', [PHP_BINARY, $root . '/tools/probe-audit.php', '--selftest'], false],
    'pixels'   => [
        '像素契约（probe_pixels）',
        $python === null ? [] : [...$python, $root . '/tools/probe_pixels.py', '--selftest'],
        false,
    ],
    'tick'     => ['每帧固定税（frame-tax）', [PHP_BINARY, $root . '/tools/frame-tax-probe.php', '--check'], false],
    // ★ 崩溃日志里的 `模块+RVA` 能不能翻成函数名 —— 这是"崩溃可观测"闭环的最后一环。
    //   自检用**合成 map**，不依赖有没有构建过 exe（所以廉价、无前置）。
    'sym'      => ['崩溃符号翻译（rva2sym）', [PHP_BINARY, $root . '/tools/rva2sym.php', '--selftest'], false],
    'unit'     => ['unit（纯单元）', [PHP_BINARY, $root . '/tools/phpunit-run.php', 'unit'], false],
    'feature'  => ['feature（场景）', [PHP_BINARY, $root . '/tools/phpunit-run.php', 'feature'], true],
    'golden'   => ['golden 原语', [PHP_BINARY, $root . '/tools/golden-raster.php', '--check', '--canvas'], false],
    'pages'    => ['golden 整页', [PHP_BINARY, $root . '/tools/golden-raster.php', '--check', '--pages'], true],
    'smoke'    => ['smoke', [PHP_BINARY, $root . '/tools/smoke.php'], true],
];

/**
 * **机器能力项**：允许"没测到"（命令缺失 / 退出码 2）而不计失败。
 *
 * ★ 只许列**依赖本机装了什么**的检查（现在只有 python）。别把普通检查写进来 ——
 *   那会把真正的失败变成一句轻描淡写的"跳过"，正是本仓反复踩的"绿壳"。
 * ★ 跳过**不是通过**：总结里必须单独列出跳了几项、为什么。
 */
$may_skip = ['pixels'];

// ── 参数 ──
$args = array_slice($argv, 1);
$fast = in_array('--fast', $args, true);
$list = in_array('--list', $args, true);

$only = null;
foreach ($args as $a) {
    if (str_starts_with($a, '--only=')) {
        $only = array_values(array_filter(array_map('trim', explode(',', substr($a, 7)))));
    }
}

if ($list) {
    foreach ($checks as $key => [$label, $_cmd, $slow]) {
        echo '  ' . $key . "\t" . $label . ($slow ? "\t(slow：--fast 跳过)" : '') . "\n";
    }
    exit(0);
}

// 选定要跑的项（保持 $checks 的声明顺序）
$selected = [];
if ($only !== null) {
    foreach ($only as $k) {
        if (!isset($checks[$k])) {
            fwrite(STDERR, "未知检查项：{$k}（用 --list 看清单）\n");
            exit(2);
        }
        $selected[$k] = $checks[$k];
    }
} else {
    foreach ($checks as $k => $c) {
        if ($fast && $c[2]) {
            continue; // --fast：跳过慢项（整页 golden / smoke）
        }
        $selected[$k] = $c;
    }
}

if ($selected === []) {
    fwrite(STDERR, "没有要跑的检查项。\n");
    exit(2);
}

// ── 逐项跑（fail-fast；`$may_skip` 里的项允许"没测到"）──
$started = microtime(true);
$index = 0;
$total = count($selected);
$skipped = [];

foreach ($selected as $key => [$label, $cmd, $_slow]) {
    $index++;
    fwrite(STDOUT, sprintf("\n▶ [%d/%d] %s\n", $index, $total, $label));

    if ($cmd === []) {
        // 只有 $may_skip 里会出现空命令（依赖的东西没装）
        $skipped[] = $label . '（本机没找到 python，设 DRIFT_PYTHON 可指定）';
        fwrite(STDOUT, "  · 跳过：本机没有 python —— 这是**没测到**，不是通过\n");
        continue;
    }

    $proc = proc_open(
        $cmd,
        [0 => ['file', 'nul', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        null,
        ['bypass_shell' => true],
    );
    if (!is_resource($proc)) {
        fwrite(STDERR, "✗ 启动失败：{$label}\n");
        exit(127);
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    // 流式转发输出；退出码从 proc_get_status 取（proc_close 在已 reap 时可能返回 -1）
    $exitCode = -1;
    while (true) {
        $status = proc_get_status($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        if ($out !== '') {
            fwrite(STDOUT, $out);
        }
        if ($err !== '') {
            fwrite(STDERR, $err);
        }
        if (!$status['running']) {
            $exitCode = (int) $status['exitcode'];
            break;
        }
        usleep(20000);
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    if ($out !== '') {
        fwrite(STDOUT, $out);
    }
    if ($err !== '') {
        fwrite(STDERR, $err);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    if ($exitCode !== 0) {
        if ($exitCode === 2 && in_array($key, $may_skip, true)) {
            $skipped[] = $label . '（退出码 2）';
            fwrite(STDOUT, "  · 跳过：这一项自己说\"没测到\"（退出码 2）—— 不算通过\n");
            continue;
        }

        $elapsed = microtime(true) - $started;
        fwrite(STDERR, sprintf("\n✗ 停在 %s（exit %d，累计 %.1fs）\n", $label, $exitCode, $elapsed));
        exit($exitCode === 0 ? 1 : $exitCode);
    }
}

$elapsed = microtime(true) - $started;
fwrite(STDOUT, sprintf("\n✓ 全部通过（%d 项，%.1fs）\n", $total, $elapsed));
if ($skipped !== []) {
    fwrite(STDOUT, sprintf("· 另有 %d 项**没测到**（不是通过）：\n", count($skipped)));
    foreach ($skipped as $why) {
        fwrite(STDOUT, '    - ' . $why . "\n");
    }
}
exit(0);
