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
 *   ★ 耗时是 2026-10-28 本机实测（同一台机器逐项跑，合计 72.5s）。**别凭印象改**，
 *     改规模（迁用例 / 加 app 级断言）后要重测：文档里原来写的是 smoke 1727 条 /
 *     feature ~0.1s，那是 phpunit 还没有 660 个用例时的数。
 *   lint      php tools/lint.php               738 文件 · 0 error     ~4.6s
 *   selftest  php tools/lint.php --selftest    规则双向自检            ~0.6s
 *   docref    php tools/docref.php             十册 file:line / § 引用 ~1.5s
 *   unit      phpunit-run unit                 纯单元                 ~5.2s
 *   feature★  phpunit-run feature              场景测试（真挂载+推帧） ~21.6s
 *   golden    golden-raster --check --canvas   9 个原语场景像素基线    ~1.4s
 *   pages★    golden-raster --check --pages    20 个整页像素基线       ~5.2s
 *   smoke★    php tools/smoke.php              448 条断言             ~32.4s
 *
 * ★ 为什么这么慢（三项，按占比）：① smoke 里 **38 处**子进程调用点（33 处
 *   `runCase()` + 5 处 `runFatalCase()`）各起一个 php.exe（"期望响亮失败"只能
 *   子进程：`Errors::report` 走 `abort()`，`try/catch` 接不住）—— 实测单次
 *   启动+装载 0.24s ⇒ 约 9.1s 纯开机税；② 等真实时间（`php -S` mock / worker 池
 *   各睡 200ms / 一条"≥1.2s 说明真等了网络"）；③ feature 是**每例都真的建树+
 *   layout+paint**（内存峰值 692MB）。
 *   ⇒ 想快先砍 ①（并进程）与拆 B 桶（app 级集成单列一项），别去动 ③ 的断言密度。
 *
 * 用法：
 *   php tools/gate.php              # 全部（含整页 + smoke，实测约 72s）
 *   php tools/gate.php --fast       # 只跑廉价项（实测约 13s，日常迭代）
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
 * @var array<string, array{0: string, 1: list<string>, 2: bool}> 键 = 短名，
 *      值 = [显示名, 命令, 是否慢项]
 */
$checks = [
    'lint'     => ['lint', [PHP_BINARY, $root . '/tools/lint.php'], false],
    'selftest' => ['lint 自检', [PHP_BINARY, $root . '/tools/lint.php', '--selftest'], false],
    'docref'   => ['docref', [PHP_BINARY, $root . '/tools/docref.php'], false],
    'unit'     => ['unit（纯单元）', [PHP_BINARY, $root . '/tools/phpunit-run.php', 'unit'], false],
    'feature'  => ['feature（场景）', [PHP_BINARY, $root . '/tools/phpunit-run.php', 'feature'], true],
    'golden'   => ['golden 原语', [PHP_BINARY, $root . '/tools/golden-raster.php', '--check', '--canvas'], false],
    'pages'    => ['golden 整页', [PHP_BINARY, $root . '/tools/golden-raster.php', '--check', '--pages'], true],
    'smoke'    => ['smoke', [PHP_BINARY, $root . '/tools/smoke.php'], true],
];

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

// ── 逐项跑（fail-fast）──
$started = microtime(true);
$index = 0;
$total = count($selected);

foreach ($selected as $key => [$label, $cmd, $_slow]) {
    $index++;
    fwrite(STDOUT, sprintf("\n▶ [%d/%d] %s\n", $index, $total, $label));

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
        $elapsed = microtime(true) - $started;
        fwrite(STDERR, sprintf("\n✗ 停在 %s（exit %d，累计 %.1fs）\n", $label, $exitCode, $elapsed));
        exit($exitCode === 0 ? 1 : $exitCode);
    }
}

$elapsed = microtime(true) - $started;
fwrite(STDOUT, sprintf("\n✓ 全部通过（%d 项，%.1fs）\n", $total, $elapsed));
exit(0);
