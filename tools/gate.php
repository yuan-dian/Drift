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
 * ★ 本脚本只覆盖 **T0（无头、解释器）**。T1 dev 真机 / T2 AOT 真机仍是显式动作，
 *   两者共用 `probe-window.py` 的五条判据。分层与「dev 绿 ≠ AOT 绿」清单见
 *   `docs/architecture/12-工程与工具.md` §3 —— 日常不碰 AOT 之后那份清单更要紧。
 *
 * 检查项清单**不写在这里**（2026-11-11）：这份名单曾两次与 `--list` 对不上
 * （26 项 vs 真实 28 项），漏掉的是最新加的那批 —— 而最新加的那批恰恰是还没被
 * 任何人记住的。**唯一的清单是 `--list`**（它从 `CheckRegistry::checks()` 算出来，
 * 不可能漂）。2026-11-12 起清单本体住在 `tools/Support/CheckRegistry.php`：
 * `gate.php` 与 `bin/drift` 读同一份，不再各抄一遍命令。
 *
 *   php tools/gate.php --list     # 当前项、顺序、是否 slow
 *
 * 顺序即执行顺序（廉价优先、失败即停）；标 slow 的在 `--fast` 下跳过。
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
use Drift\Dev\Support\CheckRegistry;
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
// 判据清单的唯一事实源：见 tools/Support/CheckRegistry.php（含每项为什么存在的注释）。
$checks = CheckRegistry::checks($root, $python);

$may_skip = CheckRegistry::maySkip();

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
 *
 * ★ 失败 / skip 项的 `stdout`/`stderr` 是**子进程原始字节**，可能是 GBK / cp1252
 *   而非 UTF-8（2026-11-12 CI 上 `json_encode` 因此 Fatal 255）。编码边界统一在
 *   `CheckReport::json()`：那里替换非法字节并在顶层 `encoding` 标记字段路径，
 *   这里不各修一遍。
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
