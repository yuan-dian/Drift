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
 *   scale     12k 节点尺子（frame-tax --scale，5 条自洽判据）
 *   sym       崩溃符号翻译（rva2sym --selftest，6 条；合成 map，无构建前置）
 *   decode    图片解码通道序（decode-selftest；要 MSVC + WIC ⇒ 允许"没测到"）
 *   m2        M2 conformance（W1–W8 静态 + 夹具 + 量具反例自检）
 *   unit      纯单元
 *   feature★  场景测试（真挂载 + 推帧）
 *   golden    原语像素基线
 *   pages★    整页像素基线
 *   smoke★    端到端 smoke
 *
 * ★ 耗时**不写在这里**了（2026-11-02）：这份清单曾逐项标着"~4.6s / ~22.8s、合计 63.4s"，
 *   而实际早已是 120s 量级 —— 数字一旦没有判据就会烂，且烂了没人发现。
 *   要耗时就跑一遍看最后一行（`--- N 项全过（Xs） ---`）；**项数也以那一行为准**，
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
 *   php tools/gate.php --json       # 机器可读（stdout 上**只有** JSON）
 *
 * 退出码：**0 = 全过 · 1 = 有项失败 · 2 = 用法错**（fail-fast：失败后不再往下跑）。
 *
 * ★ 退出码口径在 2026-11-03 收窄过一次（兼容性破坏，故意的）：
 *   原来写"= 第一个失败项的退出码"，于是子工具崩成 127（可执行文件没找到）
 *   与判据红（1）从 gate 这一层**长得一模一样**——正是 `find_python()` 注释里
 *   骂的那种"环境的错扮成判据的错"。现在 gate 只回答"过没过"，
 *   子项的真实退出码进 `--json` 的 `data.exitCode`，要分辨去那里看。
 *
 * ★ 为什么用 proc_open 而不是 passthru：php.exe 的 shell 调用在 Windows 上走
 *   cmd.exe（路径带空格 / 引号会被二次解释）。数组式命令 + `bypass_shell` 直接
 *   CreateProcess，与 `drift-build.php` 同法（MEMORY 硬坑）。
 */

require __DIR__ . '/autoload.php';

use Drift\Dev\Support\CheckItem;
use Drift\Dev\Support\CheckReport;

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
    // ★ `docs/API-SURFACE.md` 的抬头写着"自动生成、勿手改"，但**没人检查它是新的**
    //   ⇒ 它漂了：`FrameStage` 没进文档，行号整体偏几十行。docref 查的是"引用的符号
    //   存在吗"，这一项查的是"这份清单是最新的吗" —— 两件事，都要有闸门。
    'apidoc'   => ['API 面清单（apidoc）', [PHP_BINARY, $root . '/tools/api-doc-check.php'], false],
    'probe'    => ['探针骨架（probe-audit）', [PHP_BINARY, $root . '/tools/probe-audit.php', '--selftest'], false],
    'pixels'   => [
        '像素契约（probe_pixels）',
        $python === null ? [] : [...$python, $root . '/tools/probe_pixels.py', '--selftest'],
        false,
    ],
    'tick'     => ['每帧固定税（frame-tax）', [PHP_BINARY, $root . '/tools/frame-tax-probe.php', '--check'], false],
    // 判据只认自证 / 比值 / 结构事实；绝对耗时当红线会随机器抖动误报。
    'scale'    => ['12k 节点尺子（frame-tax --scale）', [PHP_BINARY, $root . '/tools/frame-tax-probe.php', '--scale'], false],
    // ★ 崩溃日志里的 `模块+RVA` 能不能翻成函数名 —— 这是"崩溃可观测"闭环的最后一环。
    //   自检用**合成 map**，不依赖有没有构建过 exe（所以廉价、无前置）。
    'sym'      => ['崩溃符号翻译（rva2sym）', [PHP_BINARY, $root . '/tools/rva2sym.php', '--selftest'], false],
    // ★ WIC 解码只在**有 WIC 的进程**里跑得到（`php.exe` 没这个扩展），单测覆盖不到。
    //   这条自检拿一张期望值手写的色带图，钉"红带必须是红" —— 缩放的通道序曾经静默
    //   反了（IWICBitmapScaler 不认 32bppRGBA），六条"两个尺寸互相比"的判据全绿。
    //   要 MSVC 才能编译 ⇒ 没工具链的机器记"没测到"，不许记成通过。
    'decode'   => ['图片通道序（decode-selftest）', [PHP_BINARY, $root . '/tools/decode-selftest/run.php'], false],
    // ★ M2 conformance：W1–W3 静态扫 `src/Widget`（死字段 / 字段进盒 / 单一造盒路径）
    //   + W4–W8 夹具运行时判据 + 8 条量具反例自检。
    //   ★ 它此前**不在 gate 里**，于是 3 条假红挂了一个多月没人看 ——
    //   "闸门不在 gate 里 = 红了没人知道"。假红成因是尺子只认本类体（读点漏了
    //   `use` 进来的特征与子类）与只认一条造盒路径，2026-11-03 修准后进 gate。
    //   先判定再接线：红着的闸门加进来只会被人 --only 绕过去。
    'm2'       => ['M2 conformance（W1–W8）', [PHP_BINARY, $root . '/tools/M2Conformance.php'], false],
    'unit'     => ['unit（纯单元）', [PHP_BINARY, $root . '/tools/phpunit-run.php', 'unit'], false],
    'feature'  => ['feature（场景）', [PHP_BINARY, $root . '/tools/phpunit-run.php', 'feature'], true],
    'golden'   => ['golden 原语', [PHP_BINARY, $root . '/tools/golden-raster.php', '--check', '--canvas'], false],
    'pages'    => ['golden 整页', [PHP_BINARY, $root . '/tools/golden-raster.php', '--check', '--pages'], true],
    'smoke'    => ['smoke', [PHP_BINARY, $root . '/tools/smoke.php'], true],
];

/**
 * **机器能力项**：允许"没测到"（命令缺失 / 退出码 2）而不计失败。
 *
 * ★ 只许列**依赖本机装了什么**的检查（python / MSVC）。别把普通检查写进来 ——
 *   那会把真正的失败变成一句轻描淡写的"跳过"，正是本仓反复踩的"绿壳"。
 * ★ 跳过**不是通过**：总结里必须单独列出跳了几项、为什么。
 * ★ 这两项的"跳过"都只能由**入口自己的退出码 2** 触发（找不到解释器 / 找不到
 *   cl.exe），不能由"跑起来了但结果不对"触发 —— 后者是 1。
 */
$may_skip = ['pixels', 'decode'];

// ── 参数 ──
$args = array_slice($argv, 1);
$fast = in_array('--fast', $args, true);
$list = in_array('--list', $args, true);
/**
 * `--json`：stdout 上**只有**一份 JSON（见 `CheckReport`）。
 *
 * ★ 与"边跑边转发"互斥：转发会把子进程输出混进 stdout，agent 就得先猜"从哪一行
 *   开始才是 JSON"。所以 `--json` 下**不流式转发**，一律收进 `items[].data`。
 *   代价是 110 秒里看不到进度条 —— agent 本来也不看进度条，人不用 `--json`。
 */
$json = in_array('--json', $args, true);

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

/**
 * 一条耗时的人读写法。★ 只有这一份（此前它在两处各写了一遍 `sprintf('%.1fs')`）。
 */
$fmtMs = static fn(int $ms): string => $ms >= 1000
    ? sprintf('%.1fs', $ms / 1000.0)
    : sprintf('%dms', $ms);

// ── 逐项跑（fail-fast；`$may_skip` 里的项允许"没测到"）──
//
// ★ 结果一律进 `$items`，**不再边跑边 exit**：判据、计数、退出码都从这一份
//   `CheckReport` 出（`CheckReport::exitCode()`）。此前"第一个失败项的退出码"
//   把环境崩（127）与判据红（1）混成同一个信号。
$started = microtime(true);
$index = 0;
$total = count($selected);
/** @var list<CheckItem> $items */
$items = [];

foreach ($selected as $key => [$label, $cmd, $_slow]) {
    $index++;
    if (!$json) {
        fwrite(STDOUT, sprintf("\n▶ [%d/%d] %s\n", $index, $total, $label));
    }
    $t0 = hrtime(true);

    if ($cmd === []) {
        // 只有 $may_skip 里会出现空命令（依赖的东西没装）
        $items[] = new CheckItem(
            $key,
            true,
            $label . '：本机没有 python（设 DRIFT_PYTHON 可指定）',
            ['exitCode' => null],
            true,
        );
        if (!$json) {
            fwrite(STDOUT, "  · 跳过：本机没有 python —— 这是**没测到**，不是通过\n");
        }
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
        $items[] = new CheckItem(
            $key,
            false,
            $label . '：启动失败（命令 ' . implode(' ', $cmd) . '）',
            ['exitCode' => 127],
        );
        break;
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    // 边收边转（非 json）；退出码从 proc_get_status 取（proc_close 在已 reap 时可能返回 -1）
    $outBuf = '';
    $errBuf = '';
    $exitCode = -1;
    while (true) {
        $status = proc_get_status($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        if ($out !== '') {
            $outBuf .= $out;
            if (!$json) {
                fwrite(STDOUT, $out);
            }
        }
        if ($err !== '') {
            $errBuf .= $err;
            if (!$json) {
                fwrite(STDERR, $err);
            }
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
        $outBuf .= $out;
        if (!$json) {
            fwrite(STDOUT, $out);
        }
    }
    if ($err !== '') {
        $errBuf .= $err;
        if (!$json) {
            fwrite(STDERR, $err);
        }
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);

    $ms = (int) round((hrtime(true) - $t0) / 1e6);

    if ($exitCode === 0) {
        // ★ 通过项不带输出：471 条 smoke 的全文塞进 JSON 只会把 agent 的上下文吃掉，
        //   而"通过"没有现场可看。要现场就单独跑那一条。
        $items[] = new CheckItem($key, true, sprintf('%s（%s）', $label, $fmtMs($ms)), ['exitCode' => 0, 'durationMs' => $ms]);
        continue;
    }

    if ($exitCode === 2 && in_array($key, $may_skip, true)) {
        $items[] = new CheckItem(
            $key,
            true,
            sprintf('%s：这一项自己说"没测到"（退出码 2）', $label),
            ['exitCode' => 2, 'durationMs' => $ms, 'stdout' => $outBuf, 'stderr' => $errBuf],
            true,
        );
        if (!$json) {
            fwrite(STDOUT, "  · 跳过：这一项自己说\"没测到\"（退出码 2）—— 不算通过\n");
        }
        continue;
    }

    $items[] = new CheckItem(
        $key,
        false,
        sprintf('%s（exit %d，%s）', $label, $exitCode, $fmtMs($ms)),
        ['exitCode' => $exitCode, 'durationMs' => $ms, 'stdout' => $outBuf, 'stderr' => $errBuf],
    );
    break;
}

$elapsedMs = (int) round((microtime(true) - $started) * 1000);
$passed = count(array_filter($items, static fn(CheckItem $i): bool => $i->ok && !$i->skipped));
$skipped = count($items) - $passed;

// ★ 跳过信息**本身就是条目**（`  skip  pixels …` 那一行），所以收尾只需要一句数字；
//   此前它是"正文里没有、末尾补一段列表"，同一件事两个地方说。
$summary = $skipped === 0
    ? sprintf('%d 项全过（%s）', $passed, $fmtMs($elapsedMs))
    : sprintf('%d 项全过，%d 项没测到（%s）', $passed, $skipped, $fmtMs($elapsedMs));

(new CheckReport('gate', $items, $summary))->emit($json);
