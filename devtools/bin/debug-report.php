#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `drift debug` —— 无头跑一个应用（或示例台的某一页），回答"哪些节点重建了、因为谁"。
 *
 * 这是帧因果录制器的**真实使用者**：没有它，`Debug\Session` 就只有测试调用，
 * 而"写了但没被走通过的路径"是本项目最不想要的形态。
 *
 * 用法：
 *   php devtools/bin/debug-report.php SlowFrameApplication --frames=12
 *   php devtools/bin/debug-report.php --page=async-table --rows=6 --frames=2 --resolve --hold=3
 *   php devtools/bin/debug-report.php --page=table --frames=1 --click=400,300 --key=Down
 *   php devtools/bin/debug-report.php --page=table --frames=1 --wheel=400,300,20 --hold=8
 *   php devtools/bin/debug-report.php TextAreaApplication --key=Tab --text=abc --key=Enter --json
 *   php devtools/bin/debug-report.php --page=table --frames=1 --click=400,300 --save=case.json
 *   php devtools/bin/debug-report.php --page=table --replay=case.json --remote     # 换个进程跑
 *
 * 动作语义：先跑 `--frames` 帧（默认 1，用于挂载），再按命令行顺序执行
 * `--key=` / `--text=` / `--click=` / `--wheel=` / `--resolve`，**每个动作之后递进
 * `--hold` 帧**（默认 1）—— 重建发生在动作消费之后，不推帧就看不到因果。
 *
 * 为什么 `--hold` 默认只有 1 也要留着这个旋钮：滚轮 / 回弹 / 淡出都是**动画**，
 * 一帧只够"开始"。实测 `--page=table --wheel=…` 只推一帧会得到"滚了但什么都没变"，
 * 把 `--hold=8` 才看到滑窗换行那两帧的重建（293 / 343 节点，单帧 10ms）。
 *
 * 为什么必须有指针动作：焦点在挂载时是空的，`--text=` 打不进任何地方；
 * 表格的滚动也不认方向键（`Table` 不是焦点容器）。没有 `--click=` / `--wheel=`，
 * "表格页跑一轮"只能靠 `--key=Tab` 撞运气，且滚不动。
 *
 * 目标寻址两条路：
 *   - 位置参数 = Application 类名（`Drift\Dev\Smoke\App\*` 可省命名空间）
 *   - 位置参数或 `--page=` = 示例台**路由 id**（`table` / `async-table` / …）
 *     ⇒ 走 `PageHostApplication`，依赖页由组合根同款的工厂表装配
 *
 * `--remote`：这次会话交给一个**子进程**跑。同样的脚本、同样的查询，只是记录
 * 在另一头 —— 报告由同一段代码组装。它不是"性能开关"，而是让"跨进程读到的值
 * 是否与同进程一致"变成一条可执行的判据；也是将来"一次驱动、多次追问"（托管端
 * 留着会话，客户端来一条问一条）的地基。
 *
 * @internal 手工工具；不进 V2 判据清单
 */

require __DIR__ . '/../autoload.php';

use Drift\Debug\Session\Script;
use Drift\Debug\Session\Step;
use Drift\DevTools\Cli\Commands;
use Drift\DevTools\Report\ReportRunner;
use Drift\DevTools\View\Report;

$args = Commands::parseOrExit('report', $argv);
$target = $args->target();
$page = $args->str('page');
$json = $args->bool('json');
$verify = $args->bool('verify');
$remote = $args->bool('remote');
$frames = $args->int('frames');
$width = (int) $args->float('view-width');
$height = (int) $args->float('height');
$widthSet = $args->has('view-width');
$heightSet = $args->has('height');
$top = $args->int('top');
$depth = $args->int('depth');
$why = $args->has('why') ? $args->int('why') : null;
$whyFrame = $args->has('frame') ? $args->int('frame') : null;
$hold = $args->int('hold');
$rows = $args->int('rows');
$rowsSet = $args->has('rows');
$at = $args->at();
$savePath = $args->str('save');
$replayPath = $args->str('replay');
$steps = $args->steps();

// ---------------------------------------------------------------- 脚本：读入
//
// 脚本自带目标、视口与环境（`rows` 之类）⇒ 重放时命令行不必再重复一遍。
// 但**命令行显式给了就以命令行为准**，然后校验两者一致：拿 `table` 的脚本跑
// `form` 会得到一份语法正确、语义全错的报告，而报告本身看不出来 —— 这种
// "看起来跑通了"是本项目最不想要的形态。
$replay = null;
if ($replayPath !== null) {
    $raw = @\file_get_contents($replayPath);
    if ($raw === false) {
        \fwrite(\STDERR, "读不到脚本：{$replayPath}\n");
        exit(2);
    }
    try {
        $replay = Script::fromJson($raw);
    } catch (\Throwable $e) {
        \fwrite(\STDERR, "脚本不能用（{$replayPath}）：" . $e->getMessage() . "\n");
        exit(2);
    }

    if (!$widthSet) {
        $width = \max(1, (int) $replay->width);
    }
    if (!$heightSet) {
        $height = \max(1, (int) $replay->height);
    }
    if (!$rowsSet && $replay->metaValue('rows') !== null) {
        $rows = \max(0, (int) $replay->metaValue('rows'));
    }
    if ($page === null && $target === null) {
        $target = $replay->target;
    }
}

$label = $page ?? $target;
if ($label === null) {
    \fwrite(\STDERR, "用法：drift debug <AppClass|路由id> [--page=<路由id>] [--frames=N] [--rows=N]\n");
    \fwrite(\STDERR, "              [--key=Tab] [--text=abc] [--click=x,y[,键]] [--wheel=x,y,格[,h]]\n");
    \fwrite(\STDERR, "              [--hold=N] [--resolve] [--why=<节点id>] [--frame=N] [--top=N] [--depth=N]\n");
    \fwrite(\STDERR, "              [--width=W] [--height=H] [--json]\n");
    \fwrite(\STDERR, "              [--save=<脚本.json>] [--replay=<脚本.json> [--at=N] [--verify]] [--remote]\n");
    exit(2);
}

if ($replay !== null) {
    $mismatch = [];
    if ($replay->target !== $label) {
        $mismatch[] = "脚本是给 {$replay->target} 录的，当前目标是 {$label}";
    }
    if ((int) $replay->width !== $width || (int) $replay->height !== $height) {
        $mismatch[] = \sprintf(
            '脚本视口是 %dx%d，当前是 %dx%d',
            (int) $replay->width,
            (int) $replay->height,
            $width,
            $height,
        );
    }
    if ($mismatch !== []) {
        \fwrite(\STDERR, "脚本与本次运行对不上：\n  " . \implode("\n  ", $mismatch) . "\n");
        \fwrite(\STDERR, "（确实要换目标，请重新录一份：--save=<路径>）\n");
        exit(2);
    }
    if ($steps !== []) {
        \fwrite(\STDERR, "重放时不能再给 --key/--text/--click/--wheel/--resolve：动作已经在脚本里\n");
        exit(2);
    }
    if ($replay->truncated()) {
        \fwrite(\STDERR, '脚本被截断（超过 ' . Script::MAX_STEPS . " 步），后半段没记下来，不能重放\n");
        exit(2);
    }
    // 异步脚本必须带上同样的取数替身：少了它页面会退回自己的默认源（或直接挂死），
    // 重放就变成"一整段静止画面"，而报告本身看不出少了什么。
    $needsResolve = false;
    foreach ($replay->steps() as $step) {
        if ($step->kind === Step::EFFECT && ($step->data['name'] ?? '') === 'resolve') {
            $needsResolve = true;
            break;
        }
    }
    if ($needsResolve && $rows <= 0) {
        \fwrite(\STDERR, "脚本里有异步结算（resolve），但本次 rows=0：重放必须用同样的 --rows=N 提供取数替身\n");
        exit(2);
    }
}

if ($verify && $replay === null) {
    \fwrite(\STDERR, "--verify 需要 --replay=<脚本>：它做的是\"同一脚本跑两遍\"\n");
    exit(2);
}

// 规划路径上的 `--resolve` 同样要有取数替身。以前是跑到那一步才在 stderr 提一句，
// 于是一份 600 帧的报告白跑完才告诉人"这一笔什么都没结算"。
if ($replay === null && $rows <= 0) {
    foreach ($steps as [$kind]) {
        if ($kind === 'resolve') {
            \fwrite(\STDERR, "--resolve 需要 --rows=N：没有取数替身就没有在途请求可结算\n");
            exit(2);
        }
    }
}

$options = [
    'label' => (string) $label,
    'page' => $page !== null,
    'rows' => $rows,
    'width' => $width,
    'height' => $height,
    'script' => $replay,
    'steps' => $steps,
    'frames' => $frames,
    'hold' => $hold,
    'at' => $at,
    'top' => $top,
    'depth' => $depth,
    'why' => $why,
    'whyFrame' => $whyFrame,
    'remote' => $remote,
];

try {
    if ($verify) {
        $a = ReportRunner::runOnce($options);
        $b = ReportRunner::runOnce($options);
    } else {
        $run = ReportRunner::runOnce($options);
    }
} catch (\Throwable $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
}

if ($verify) {
    exit(ReportRunner::reportVerify($a, $b, (string) $replayPath, $json) ? 0 : 1);
}

if ($savePath !== null) {
    if (@\file_put_contents($savePath, $run['script']->json()) === false) {
        \fwrite(\STDERR, "写不了脚本：{$savePath}\n");
        exit(2);
    }
    \fwrite(\STDERR, "脚本已存 {$savePath}（{$run['script']->count()} 步）\n");
}

$report = $run['report'];
$report['digest'] = $run['digest'];
$report['script'] = ReportRunner::scriptSummary($run['script']);

if ($json) {
    \fwrite(\STDOUT, \json_encode($report, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) . "\n");
    exit(0);
}

foreach (Report::lines($report) as $line) {
    \fwrite(\STDOUT, $line . "\n");
}
exit(0);
