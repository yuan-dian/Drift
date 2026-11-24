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
 *   php tools/debug-report.php SlowFrameApplication --frames=12
 *   php tools/debug-report.php --page=async-table --rows=6 --frames=2 --resolve --hold=3
 *   php tools/debug-report.php --page=table --frames=1 --click=400,300 --key=Down
 *   php tools/debug-report.php --page=table --frames=1 --wheel=400,300,20 --hold=8
 *   php tools/debug-report.php TextAreaApplication --key=Tab --text=abc --key=Enter --json
 *   php tools/debug-report.php --page=table --frames=1 --click=400,300 --save=case.json
 *   php tools/debug-report.php --page=table --replay=case.json --remote     # 换个进程跑
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

require __DIR__ . '/autoload.php';

use Drift\App\Replay;
use Drift\Debug\Protocol\RemoteSession;
use Drift\Debug\Session\Digest;
use Drift\Debug\Session\Script;
use Drift\Debug\Session\Step;
use Drift\Dev\Debug\Plan;
use Drift\Dev\Debug\ProcessTransport;
use Drift\Dev\Debug\Runner;

$target = null;
$page = null;
$frames = 1;
$width = 800;
$height = 600;
$widthSet = false;
$heightSet = false;
$json = false;
$top = 10;
$depth = 6;
$why = null;
$whyFrame = null;
$rows = 0;
$rowsSet = false;
$hold = 1;
$at = null;
$savePath = null;
$replayPath = null;
$verify = false;
$remote = false;
/** @var list<array{0: 'key'|'text'|'click'|'wheel'|'resolve', 1: string}> */
$steps = [];

foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $json = true;
    } elseif ($arg === '--verify') {
        $verify = true;
    } elseif ($arg === '--remote') {
        $remote = true;
    } elseif (\str_starts_with($arg, '--frames=')) {
        $frames = \max(1, (int) \substr($arg, 9));
    } elseif (\str_starts_with($arg, '--width=')) {
        $width = \max(1, (int) \substr($arg, 8));
        $widthSet = true;
    } elseif (\str_starts_with($arg, '--height=')) {
        $height = \max(1, (int) \substr($arg, 9));
        $heightSet = true;
    } elseif (\str_starts_with($arg, '--top=')) {
        $top = \max(1, (int) \substr($arg, 6));
    } elseif (\str_starts_with($arg, '--depth=')) {
        $depth = \max(1, (int) \substr($arg, 8));
    } elseif (\str_starts_with($arg, '--why=')) {
        $why = (int) \substr($arg, 6);
    } elseif (\str_starts_with($arg, '--frame=')) {
        $whyFrame = (int) \substr($arg, 8);
    } elseif (\str_starts_with($arg, '--hold=')) {
        $hold = \max(1, (int) \substr($arg, 7));
    } elseif (\str_starts_with($arg, '--rows=')) {
        $rows = \max(0, (int) \substr($arg, 7));
        $rowsSet = true;
    } elseif (\str_starts_with($arg, '--at=')) {
        $at = \max(1, (int) \substr($arg, 5));
    } elseif (\str_starts_with($arg, '--save=')) {
        $savePath = \substr($arg, 7);
    } elseif (\str_starts_with($arg, '--replay=')) {
        $replayPath = \substr($arg, 9);
    } elseif (\str_starts_with($arg, '--page=')) {
        $page = \substr($arg, 7);
    } elseif ($arg === '--resolve') {
        $steps[] = ['resolve', ''];
    } elseif (\str_starts_with($arg, '--key=')) {
        $steps[] = ['key', \substr($arg, 6)];
    } elseif (\str_starts_with($arg, '--text=')) {
        $steps[] = ['text', \substr($arg, 7)];
    } elseif (\str_starts_with($arg, '--click=')) {
        $steps[] = ['click', \substr($arg, 8)];
    } elseif (\str_starts_with($arg, '--wheel=')) {
        $steps[] = ['wheel', \substr($arg, 8)];
    } elseif (\str_starts_with($arg, '--')) {
        \fwrite(\STDERR, "未知参数：{$arg}\n");
        exit(2);
    } else {
        $target = $arg;
    }
}

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
        $a = run_once($options);
        $b = run_once($options);
    } else {
        $run = run_once($options);
    }
} catch (\Throwable $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
}

if ($verify) {
    exit(report_verify($a, $b, (string) $replayPath, $json) ? 0 : 1);
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
$report['script'] = script_summary($run['script']);

if ($json) {
    \fwrite(\STDOUT, \json_encode($report, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) . "\n");
    exit(0);
}

render_text($report);
exit(0);

// -------------------------------------------------------------------- 驱动

/**
 * 跑一次：建应用 → 开会话 → 照脚本驱动 → 收报告。
 *
 * 一次调用 = 一次完整运行。`--verify` 因此只是"调两遍再比"，
 * 不需要另写一条"为了比对而跑的路径"—— 那种路径迟早和正常路径分叉。
 *
 * 本地与 `--remote` 走的是**同一段代码**：差别只在"谁来执行那串步骤"（本进程的
 * 会话，还是子进程里的镜像）。所以判据可以写成"两边读回来的值逐值相同"。
 *
 * @param array{
 *     label: string, page: bool, rows: int,
 *     width: int, height: int, script: Script|null,
 *     steps: list<array{0: string, 1: string}>, frames: int, hold: int, at: int|null,
 *     top: int, depth: int, why: int|null, whyFrame: int|null, remote: bool
 * } $o
 *
 * @return array{report: array<string, mixed>, script: Script, digest: array<string, mixed>}
 */
function run_once(array $o): array
{
    $meta = $o['rows'] > 0 ? ['rows' => $o['rows']] : [];
    // 目标是不是路由 id 也写进脚本：宿主进程只看脚本，没有 `--page` 这条命令行可依。
    if ($o['page']) {
        $meta['page'] = 1;
    }

    // 要驱动的那份：重放时是**原脚本**（不可变），否则由命令行规划出来。
    $drive = $o['script'] ?? Plan::build(
        $o['label'],
        (float) $o['width'],
        (float) $o['height'],
        $meta,
        $o['frames'],
        $o['hold'],
        $o['steps'],
    );

    // ★ 同一份脚本跑两遍，靠的是"脚本不被改动"。录制落在**分叉**上（同目标同视口、
    //   步骤为空），原脚本保持不可变；"录下来的"与"要驱动的"是否一致，就是 `--verify`
    //   的第一级判据。
    $skeleton = $drive->fork();

    /** @var list<array{0: int, 1: float}> $frameTimes 按推进顺序：帧号 + 整帧墙钟 */
    $frameTimes = [];
    $onFrame = static function (int $id, float $ms) use (&$frameTimes): void {
        $frameTimes[] = [$id, $ms];
    };

    $session = null;

    try {
        // ★ 驱动只有**一条**路径（脚本 + {@see Replay}），本地与 `--remote` 的差别只在
        //   "谁执行那串步骤"。以前本地是手写 pump 循环：`--at` 截断、动作后的 `--hold`、
        //   时间戳递进各写一遍，远端得再写一遍 —— 两处只要有一处不同，同一份脚本
        //   给出的报告就是两个故事，而两份报告看起来都正常。
        if ($o['remote']) {
            $session = new RemoteSession(ProcessTransport::spawn([
                \PHP_BINARY,
                \dirname(__DIR__) . '/tools/debug-host.php',
            ]));
            $mountMs = $session->open($skeleton);
        } else {
            $opened = Runner::open($o['label'], (float) $o['width'], (float) $o['height'], $meta, $skeleton);
            $session = $opened['session'];
            $mountMs = $opened['mountMs'];
        }

        Replay::apply($session, $drive, [], $o['at'], $onFrame);

        // 帧表：`limit` 就是**这一轮实际推了几帧**。原来按"--frames + 动作 × hold"推算，
        // 那是"打算推几帧"；`--at` 截断时两者不是一回事，报告会把没发生的帧也列进去。
        $recorded = $session->query('frames', ['limit' => \max(1, \count($frameTimes))]);
        $wall = [];
        foreach ($frameTimes as [$id, $ms]) {
            $wall[$id] = $ms;
        }
        foreach ($recorded as $i => $frame) {
            $recorded[$i]['wall'] = $wall[(int) $frame['id']] ?? (float) $frame['cost'];
        }

        // 逐帧归因走 `explain`：不另读 Recorder，免得 CLI 与面板各写一份"怎么展开一帧"。
        $attribution = [];
        foreach ($recorded as $frame) {
            if ((int) $frame['causes'] === 0) {
                continue;
            }
            $detail = $session->query('explain', ['frame' => (int) $frame['id']]);
            $ids = [];
            foreach ($detail['rebuilt'] as $row) {
                $ids[] = (int) $row['id'];
            }
            $attribution[] = [
                'id' => (int) $detail['id'],
                't' => (float) $detail['t'],
                'rebuilt' => \count($ids),
                // ★ 存 id 而不只存个数：`--verify` 要说出"第 N 帧两边差在哪几个节点"，
                //   只有总数时只能说"有差"，把定位又退回给读者。
                'ids' => $ids,
                // 段名与跳过标记也在记录指纹里（摘要不比耗时，但比"跑了哪几段"），
                // 所以它们必须能出现在差异报告里，否则"指纹不同而看不出不同"。
                'stages' => $detail['stages'],
                'skip' => [$detail['settleSkipped'], $detail['paintSkipped']],
                'byKind' => $detail['byKind'],
                'roots' => $detail['roots'],
            ];
        }

        $allHotspots = $session->query('hotspots', ['frames' => 0, 'limit' => 0]);
        $allNodes = $session->query('nodes', ['frames' => 0, 'limit' => 0]);

        // 因果森林只取最慢帧：读者问"为什么这么慢"问的是那一帧，不是平均。
        //
        // 但**最慢帧常常是挂载帧**（首次 layout 最贵，却一个节点都没重建），拿它做
        // 因果树只会得到"（没有重建节点）"，而"点了之后那一帧动的是什么"就在旁边
        // 几行里。所以退一格：优先"最慢的**有重建**的帧"，都没有才回到最慢帧
        // （那时"没有重建"是这一整段的事实，不是选错了帧）。
        $slowestId = slowest_frame($recorded);
        $cascadeId = slowest_frame($recorded, true);
        if ($cascadeId <= 0) {
            $cascadeId = $slowestId;
        }
        $cascade = $cascadeId > 0
            ? $session->query('cascade', ['frame' => $cascadeId, 'maxDepth' => $o['depth'], 'maxChildren' => 4])
            : null;

        $report = [
            'app' => $o['label'],
            'replayed' => $o['script'] !== null,
            'viewport' => [$o['width'], $o['height']],
            'mountMs' => $mountMs,
            // 跑这次会话的进程：同进程跑时为 null。报告要能说清"这份因果是谁跑出来的"，
            // 也是 `--remote` 真的交出去了的唯一可观测量。
            'hostPid' => $session instanceof RemoteSession ? $session->hostPid() : null,
            'stats' => $session->query('stats'),
            'frames' => $recorded,
            'attribution' => $attribution,
            // ★ 排行榜被 `--top` 截断时必须说还有多少：319 个节点各重建一次被切成
            //   十行，读者会以为"就这十个"。这正是本项目最不想要的"看起来完整"。
            //   `limit=0` = 全量，两次扫描算完再切，避免同一份聚合跑四遍。
            'hotspots' => \array_slice($allHotspots, 0, $o['top']),
            'hiddenHotspots' => \max(0, \count($allHotspots) - $o['top']),
            'nodes' => \array_slice($allNodes, 0, $o['top']),
            'hiddenNodes' => \max(0, \count($allNodes) - $o['top']),
            'why' => $o['why'] === null ? null : $session->query('why', $o['whyFrame'] === null ? ['node' => $o['why']] : ['node' => $o['why'], 'frame' => $o['whyFrame']]),
            'cascade' => $cascade,
            // 因果树取的是"最慢的**有重建**的帧"，与 `最慢帧` 可能不是一个：
            // 纯重绘的帧（动画 / 滚动插值）可以最慢却一个节点都没重建，拿它做因果树
            // 只会得到"（没有重建节点）"。报告要把这件事说出来，不能默默换帧。
            'slowestFrame' => $slowestId,
        ];

        /** @var array<string, mixed> $scriptRaw */
        $scriptRaw = $session->query('script');

        return [
            'report' => $report,
            // 脚本与摘要都走**查询**：本地与远端因此完全同形，不必给远端多加动词。
            'script' => Script::fromArray($scriptRaw),
            'digest' => $session->query('digest'),
        ];
    } finally {
        $session?->close();
    }
}

/** 脚本的一句话概览（报告头与 JSON 共用）。 */
function script_summary(Script $script): array
{
    return [
        'steps' => $script->count(),
        'frames' => $script->count(Step::FRAME),
        'inputs' => $script->count(Step::KEY) + $script->count(Step::POINTER) + $script->count(Step::SCROLL),
        'effects' => $script->count(Step::EFFECT),
        'digest' => Digest::short($script->digest()),
        'truncated' => $script->truncated(),
    ];
}

/**
 * "同一脚本跑两遍"的判据报告。
 *
 * 比较三级：驱动摘要（脚本是否一样）→ 节点表 → 逐帧因果。分开报是必要的：
 * 驱动相同而因果不同 ⇒ **引擎不确定**（真 bug）；驱动就不同 ⇒ 脚本读写出错，
 * 谈因果没意义。混成一句"不一致"，下次就得重新查是哪一种。
 *
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function report_verify(array $a, array $b, string $path, bool $json): bool
{
    /** @var array<string, mixed> $sa */
    $sa = $a['script']->toArray();
    /** @var array<string, mixed> $sb */
    $sb = $b['script']->toArray();
    $scriptSame = $a['script']->digest() === $b['script']->digest();
    $nodesSame = $a['digest']['nodes'] === $b['digest']['nodes'];
    $digestSame = $a['digest']['digest'] === $b['digest']['digest'];

    /** @var array<int, string> $fa */
    $fa = $a['digest']['frames'];
    /** @var array<int, string> $fb */
    $fb = $b['digest']['frames'];
    $firstDiff = null;
    foreach ($fa as $id => $hash) {
        if (($fb[$id] ?? null) !== $hash) {
            $firstDiff = $id;
            break;
        }
    }
    $ok = $scriptSame && $nodesSame && $digestSame && $firstDiff === null;

    if ($json) {
        \fwrite(\STDOUT, \json_encode([
            'ok' => $ok,
            'script' => $path,
            'steps' => \count($sa['steps'] ?? []),
            'frames' => \count($fa),
            'scriptDigestSame' => $scriptSame,
            'nodesSame' => $nodesSame,
            'digestSame' => $digestSame,
            'firstDifferentFrame' => $firstDiff,
            'a' => ['nodes' => Digest::short((string) $a['digest']['nodes']), 'digest' => Digest::short((string) $a['digest']['digest'])],
            'b' => ['nodes' => Digest::short((string) $b['digest']['nodes']), 'digest' => Digest::short((string) $b['digest']['digest'])],
        ], \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) . "\n");

        return $ok;
    }

    \fwrite(\STDOUT, \sprintf("重放一致性（同一脚本跑两遍）  %s\n", $path));
    \fwrite(\STDOUT, \sprintf(
        "  脚本     %d 步 / %d 帧    驱动摘要 %s  %s\n",
        \count($sa['steps'] ?? []),
        \count($fa),
        $scriptSame ? '相同' : '不同',
        $scriptSame ? '✓' : '✗',
    ));
    \fwrite(\STDOUT, \sprintf(
        "  节点表   %s / %s  %s\n",
        Digest::short((string) $a['digest']['nodes']),
        Digest::short((string) $b['digest']['nodes']),
        $nodesSame ? '✓' : '✗',
    ));
    \fwrite(\STDOUT, \sprintf(
        "  逐帧     %s  %s\n",
        $firstDiff === null ? \sprintf('%d 帧全部相同', \count($fa)) : "第 {$firstDiff} 帧起不同",
        $firstDiff === null ? '✓' : '✗',
    ));
    \fwrite(\STDOUT, \sprintf(
        "  记录摘要 %s / %s  %s\n",
        Digest::short((string) $a['digest']['digest']),
        Digest::short((string) $b['digest']['digest']),
        $digestSame ? '✓' : '✗',
    ));

    if ($firstDiff !== null) {
        /** @var array<string, mixed>|null $rowA */
        $rowA = attribution_of($a, $firstDiff);
        /** @var array<string, mixed>|null $rowB */
        $rowB = attribution_of($b, $firstDiff);
        $idsA = \is_array($rowA) ? $rowA['ids'] : [];
        $idsB = \is_array($rowB) ? $rowB['ids'] : [];

        foreach ([['A', $rowA, $idsA], ['B', $rowB, $idsB]] as [$tag, $row, $ids]) {
            if ($row === null) {
                \fwrite(\STDOUT, "    {$tag} #{$firstDiff}  这一帧没有重建（或不存在）\n");
                continue;
            }
            \fwrite(\STDOUT, \sprintf(
                "    %s #%d  重建 %d 个，段 %s，跳过 %s\n",
                $tag,
                $firstDiff,
                \count($ids),
                \implode('+', \array_map('strval', (array) $row['stages'])),
                \implode('/', \array_map(static fn(bool $s): string => $s ? '1' : '0', (array) $row['skip'])),
            ));
            if ($ids !== []) {
                \fwrite(\STDOUT, '      节点：' . \implode(',', \array_slice($ids, 0, 24)) . (\count($ids) > 24 ? '…' : '') . "\n");
            }
        }

        $onlyA = \array_values(\array_diff($idsA, $idsB));
        $onlyB = \array_values(\array_diff($idsB, $idsA));
        if ($onlyA !== [] || $onlyB !== []) {
            \fwrite(\STDOUT, '    只在 A：' . ($onlyA === [] ? '—' : \implode(',', \array_slice($onlyA, 0, 24))) . "\n");
            \fwrite(\STDOUT, '    只在 B：' . ($onlyB === [] ? '—' : \implode(',', \array_slice($onlyB, 0, 24))) . "\n");
        }

        \fwrite(\STDOUT, $scriptSame
            ? "  ⇒ 驱动相同而因果不同：这是引擎的不确定性（随机数 / 墙钟 / 对象 id 序），不是脚本的问题\n"
            : "  ⇒ 驱动本身就不同：先查脚本读写出错，谈因果没意义\n");
    }

    return $ok;
}

/**
 * 从报告里取某一帧的归因行（不存在时 `null`）。
 *
 * @param array<string, mixed> $run
 *
 * @return array<string, mixed>|null
 */
function attribution_of(array $run, int $frame): ?array
{
    foreach ($run['report']['attribution'] as $row) {
        if (is_array($row) && (int) ($row['id'] ?? 0) === $frame) {
            return $row;
        }
    }

    return null;
}

// -------------------------------------------------------------------- 渲染

/** @param array<string, mixed> $report */
function render_text(array $report): void
{
    /** @var array<string, mixed> $stats */
    $stats = $report['stats'];
    /** @var list<array<string, mixed>> $frames */
    $frames = $report['frames'];
    /** @var list<array<string, mixed>> $attribution */
    $attribution = $report['attribution'];
    /** @var list<array<string, mixed>> $hotspots */
    $hotspots = $report['hotspots'];
    /** @var list<array<string, mixed>> $nodes */
    $nodes = $report['nodes'];

    $walls = \array_map(static fn(array $f): float => (float) $f['wall'], $frames);
    $last = $walls === [] ? 0.0 : $walls[\count($walls) - 1];

    \fwrite(\STDOUT, \sprintf(
        "应用  %s%s\n视口  %dx%d     帧数 %d     登记节点 %d     挂载 %.2fms\n",
        (string) $report['app'],
        $report['hostPid'] === null ? '' : \sprintf('（宿主进程 %d）', (int) $report['hostPid']),
        (int) $report['viewport'][0],
        (int) $report['viewport'][1],
        (int) $stats['frames'],
        (int) $stats['nodes'],
        (float) $report['mountMs'],
    ));
    \fwrite(\STDOUT, \sprintf(
        "墙钟  末帧 %.2fms   平均 %.2fms   p95 %.2fms   最慢 %.2fms(#%d)\n",
        $last,
        \array_sum($walls) / \max(1, \count($walls)),
        percentile($walls, 0.95),
        \max($walls),
        slowest_frame($frames),
    ));

    /** @var array<string, mixed> $scriptInfo */
    $scriptInfo = $report['script'] ?? [];
    \fwrite(\STDOUT, \sprintf(
        "脚本  %d 步（帧 %d / 输入 %d / 效果 %d）%s  驱动摘要 %s\n\n",
        (int) ($scriptInfo['steps'] ?? 0),
        (int) ($scriptInfo['frames'] ?? 0),
        (int) ($scriptInfo['inputs'] ?? 0),
        (int) ($scriptInfo['effects'] ?? 0),
        (bool) ($report['replayed'] ?? false) ? '  ← 本次由脚本重放' : '',
        (string) ($scriptInfo['digest'] ?? ''),
    ));

    \fwrite(\STDOUT, "帧号   t(ms)    墙钟    帧内    帧外    重建  边   跳过\n");
    foreach ($frames as $frame) {
        $skipped = [];
        if ((bool) $frame['settleSkipped']) {
            $skipped[] = 'settle';
        }
        if ((bool) $frame['paintSkipped']) {
            $skipped[] = 'paint';
        }
        // 帧内 = 记录器包住的 `shell->frame()`；帧外 = 应用覆写的 `frame()` 里
        // 在父调用之后干的那段。两者差很多时，"帧内"这一列会让人以为这帧很便宜。
        $outside = \max(0.0, (float) $frame['wall'] - (float) $frame['cost']);
        \fwrite(\STDOUT, \sprintf(
            "%-6d %-9.1f %-7.2f %-7.2f %-7.2f %-5d %-4d %s\n",
            (int) $frame['id'],
            (float) $frame['t'],
            (float) $frame['wall'],
            (float) $frame['cost'],
            $outside,
            (int) $frame['rebuilt'],
            (int) $frame['causes'],
            $skipped === [] ? '-' : \implode('+', $skipped),
        ));
    }

    // 分段耗时取最慢帧：只看平均会把那一帧埋掉，而"该优化哪一段"恰恰问的是它。
    // 未归属必须单列 —— 段账不覆盖帧内全部时间，藏着它等于报告自己冒充完整。
    $slowest = slowest_frame($frames);
    $profile = null;
    foreach ($frames as $frame) {
        if ((int) $frame['id'] === $slowest) {
            $profile = $frame;
            break;
        }
    }
    if ($profile !== null) {
        /** @var array<string, float> $stageMs */
        $stageMs = $profile['stageMs'];
        \fwrite(\STDOUT, \sprintf("\n耗时分布（最慢帧 #%d，帧内 %.2fms）\n", $slowest, (float) $profile['cost']));
        if ($stageMs === []) {
            \fwrite(\STDOUT, "  （这一帧没有分段记录）\n");
        }
        foreach ($stageMs as $stage => $ms) {
            \fwrite(\STDOUT, \sprintf("  %-22s %7.2fms\n", (string) $stage, $ms));
        }
        $unattributed = (float) $profile['unattributedMs'];
        if ($unattributed > 0.0) {
            \fwrite(\STDOUT, \sprintf("  %-22s %7.2fms  ← 不在任何段里\n", '（段外）', $unattributed));
        }
    }

    /** @var null|array<string, mixed> $cascade */
    $cascade = $report['cascade'];
    if (\is_array($cascade)) {
        render_cascade($cascade);
    }

    \fwrite(\STDOUT, "\n根因（kind=state，次数 / 出现帧数 / 涉及节点 / 波及 / 调用点）\n");
    if ($hotspots === []) {
        \fwrite(\STDOUT, "  （这段里没有状态写入：静止画面不会产生因果边）\n");
    }
    foreach ($hotspots as $row) {
        \fwrite(\STDOUT, \sprintf(
            "  %-4d %-4d %-4d %-4d %s  %s\n",
            (int) $row['writes'],
            (int) $row['frames'],
            (int) $row['nodes'],
            (int) ($row['subtree'] ?? 0),
            (string) $row['site'],
            (string) $row['detail'],
        ));
    }
    if ((int) $report['hiddenHotspots'] > 0) {
        \fwrite(\STDOUT, \sprintf(
            "  …… 还有 %d 处根因没列出（--top=N 看更多）\n",
            (int) $report['hiddenHotspots'],
        ));
    }

    \fwrite(\STDOUT, "\n重建聚合（次数 / 节点id / 覆盖 / 自身写入 / 父传播 / 路径）\n");
    if ($nodes === []) {
        \fwrite(\STDOUT, "  （这段里没有节点重建）\n");
    }
    // 单帧动作里常常每个节点各重建 1 次：这时次数没有区分度，排序实际由"覆盖"决定。
    // 不说清楚，读者会以为榜首就是"重建最多的那个节点"。
    if (\count($nodes) > 1
        && (int) $nodes[0]['rebuilds'] === (int) $nodes[\count($nodes) - 1]['rebuilds']
    ) {
        \fwrite(\STDOUT, \sprintf(
            "  （本段每个节点都只重建 %d 次：次数无区分度，已按覆盖子树排序）\n",
            (int) $nodes[0]['rebuilds'],
        ));
    }
    foreach ($nodes as $row) {
        \fwrite(\STDOUT, \sprintf(
            "  %-4d %-6d %-6d %-4d %-4d %s\n",
            (int) $row['rebuilds'],
            (int) $row['id'],
            (int) ($row['subtree'] ?? 0),
            (int) $row['self'],
            (int) $row['inherited'],
            (string) $row['path'],
        ));
    }
    if ((int) $report['hiddenNodes'] > 0) {
        \fwrite(\STDOUT, \sprintf(
            "  …… 还有 %d 个节点没列出（--top=N 看更多）\n",
            (int) $report['hiddenNodes'],
        ));
    }

    // 逐帧归因：聚合排名回答"谁总在重建"，这里回答"这一帧为什么动"。
    // 只有根因（kind=state）值得逐帧列：父传播是它的下游，列出来只是把同一件事
    // 重复 N 遍，反而看不出是哪一次写入引起的。
    \fwrite(\STDOUT, "\n逐帧归因（有根因的帧 / 该帧重建节点数 / 根因）\n");
    if ($attribution === []) {
        \fwrite(\STDOUT, "  （没有带根因的帧）\n");
    }
    foreach ($attribution as $row) {
        $roots = $row['roots'];
        $shown = [];
        foreach (\array_slice($roots, 0, 3) as $root) {
            $shown[] = \sprintf('%s @ %s ×%d', (string) $root['detail'], (string) $root['site'], (int) $root['count']);
        }
        if (\count($roots) > 3) {
            $shown[] = '还有 ' . (\count($roots) - 3) . ' 类';
        }
        \fwrite(\STDOUT, \sprintf(
            "  #%-5d %-5d %s\n",
            (int) $row['id'],
            (int) $row['rebuilt'],
            $shown === [] ? '（无 kind=state 根因：只有父传播或 unknown）' : \implode('；', $shown),
        ));
    }

    $why = $report['why'];
    if (\is_array($why)) {
        \fwrite(\STDOUT, \sprintf("\n为什么 #%d（%s）在第 %s 帧重建\n", (int) $why['node'], (string) $why['path'], (string) ($why['frame'] ?? '-')));
        if (!(bool) $why['rebuilt']) {
            \fwrite(\STDOUT, "  该帧它没有重建\n");
        }
        if ($why['causes'] === []) {
            \fwrite(\STDOUT, "  没有归因边：本次会话里没记到这个节点的重建\n");
        }
        /** @var list<array<string, mixed>> $trail */
        $trail = \is_array($why['trail'] ?? null) ? $why['trail'] : [];

        // parent 边本来只有一个空 site：补上是**哪个父节点**，否则"因为父重建了"
        // 等于没说。父路径从 trail 取，两处共用同一份拓扑。
        $trailById = [];
        foreach ($trail as $step) {
            $trailById[(int) $step['id']] = (string) $step['path'];
        }
        foreach ($why['causes'] as $cause) {
            $extra = '';
            if ((string) $cause['kind'] === 'parent' && isset($trailById[(int) ($cause['from'] ?? 0)])) {
                $fromId = (int) $cause['from'];
                $extra = \sprintf('  ← #%d %s', $fromId, tail_path($trailById[$fromId]));
            }
            \fwrite(\STDOUT, \sprintf(
                "  %-8s %s%s%s\n",
                (string) $cause['kind'],
                (string) $cause['site'],
                $cause['detail'] === '' ? '' : '  ' . (string) $cause['detail'],
                $extra,
            ));
        }

        // 直接边只说"因为父节点重建了"，不说父是谁。链路把一路的父节点与终点
        // （state 写入点）摊开 —— 那才是"我该改哪一行"。
        if (\count($trail) > 1) {
            \fwrite(\STDOUT, "\n  链路（目标 → 根）：\n");
            foreach ($trail as $step) {
                \fwrite(\STDOUT, \sprintf(
                    "    #%-5d %-8s %s%s\n",
                    (int) $step['id'],
                    (string) $step['kind'],
                    (string) $step['site'],
                    $step['detail'] === '' ? '' : '  ' . (string) $step['detail'],
                ));
            }
            \fwrite(\STDOUT, "  ↑ 末行是链路终点：改它才治本，改下游只是少重建几个节点\n");
        }
    }
}

/** 最慢的一帧的帧号（用于把"最慢"与帧表对上）。 */
/**
 * 最慢的那一帧。
 *
 * `$rebuiltOnly` 只要"重建过的帧"：因果树问的是"这一帧为什么动"，而最慢帧常常是
 * 挂载帧 —— 它最贵，却一个节点都不重建，拿它做因果树只会得到"（没有重建节点）"。
 *
 * @param list<array<string, mixed>> $frames
 */
function slowest_frame(array $frames, bool $rebuiltOnly = false): int
{
    $best = 0;
    $bestWall = -1.0;
    foreach ($frames as $frame) {
        if ($rebuiltOnly && (int) $frame['rebuilt'] === 0) {
            continue;
        }
        if ((float) $frame['wall'] > $bestWall) {
            $bestWall = (float) $frame['wall'];
            $best = (int) $frame['id'];
        }
    }

    return $best;
}

/**
 * 分位数（最近秩法）：n 个样本取第 `ceil(p*n)` 个。
 *
 * 不用插值：帧耗时的样本本来就少，插值出来的数在报告里不可回指到某一帧。
 *
 * @param list<float> $values 未排序
 */
function percentile(array $values, float $p): float
{
    if ($values === []) {
        return 0.0;
    }
    \sort($values);
    $rank = (int) \ceil($p * \count($values));

    return $values[\min(\count($values) - 1, \max(0, $rank - 1))];
}

/**
 * 单帧的"构成 + 因果覆盖"两段。
 *
 * 构成回答"这一帧是哪些控件在重建"（类型聚合，React Profiler 的 component ×N 口径），
 * 覆盖回答"从哪个根长下来、每个节点牵动多少"。两者都不受 --top 影响：
 * 它们是这一帧的全量事实，不是排行榜。
 *
 * @param array<string, mixed> $cascade
 */
function render_cascade(array $cascade): void
{
    // 因果树取的是"最慢的**有重建**的帧"：纯重绘的帧（动画 / 滚动插值）可以最慢却
    // 一个节点都没重建。换过帧就要说，否则"最慢帧 #3"会指着一棵属于 #2 的树。
    $slowest = (int) ($cascade['slowestFrame'] ?? $cascade['frame']);
    $which = $slowest === (int) $cascade['frame']
        ? \sprintf('最慢帧 #%d', $slowest)
        : \sprintf('帧 #%d（最慢帧 #%d 只重绘不重建）', (int) $cascade['frame'], $slowest);
    \fwrite(\STDOUT, \sprintf(
        "\n重建构成（%s，%d 个节点，最深 %d 层）\n",
        $which,
        (int) $cascade['rebuilt'],
        (int) $cascade['depthMax'],
    ));

    /** @var list<array{type: string, nodes: int}> $byType */
    $byType = $cascade['byType'];
    if ($byType === []) {
        \fwrite(\STDOUT, "  （没有重建节点）\n");

        return;
    }

    $parts = [];
    foreach (\array_slice($byType, 0, 8) as $row) {
        $parts[] = $row['type'] . '×' . $row['nodes'];
    }
    \fwrite(\STDOUT, '  ' . \implode('   ', $parts) . "\n");
    if (\count($byType) > 8) {
        \fwrite(\STDOUT, \sprintf("  … 还有 %d 类\n", \count($byType) - 8));
    }

    \fwrite(\STDOUT, "\n因果覆盖（根 = 父没有重建的节点；×N 折叠同形兄弟，数字按每个实例计）\n");
    $budget = 30;
    /** @var list<array<string, mixed>> $roots */
    $roots = $cascade['roots'];
    foreach ($roots as $root) {
        if ($budget <= 0) {
            break;
        }
        cascade_line($root, 0, $budget);
    }
    // 森林这一层也会被 maxChildren 截断：不说出来的话，"树里的数加起来不等于总重建数"
    // 就成了读者无法核对的一笔账。
    if ((int) $cascade['hidden'] > 0) {
        \fwrite(\STDOUT, \sprintf(
            "  … 另有 %d 支根（%d 个节点）未展开\n",
            (int) $cascade['omitted'],
            (int) $cascade['hidden'],
        ));
    }
    if ($budget <= 0) {
        \fwrite(\STDOUT, "  … 已达到显示上限（层级与分支都只显示前几层）\n");
    }
}

/**
 * 递归打印一个树节点。
 *
 * 三个标记：`←` 是 state 写入点（带调用点），`← ?` 是没有可见原因的边，
 * `…` 是被截断 / 被折叠掉的分支。`×N` 是**同形的兄弟份数**，行内其余数字
 * （子树规模、未展开的支数与节点数）都按**每个实例**计 —— 一行读作
 * "这个形状重复了 N 份，每份这么大"。`>` 是**单链折叠**：一串各只有一个孩子的
 * 节点，中间没有写入点也没有截断。
 *
 * @param array<string, mixed> $node
 * @param int                  $budget 剩余可打印行数（引用传，跨层级共享）
 */
function cascade_line(array $node, int $depth, int &$budget): void
{
    if ($budget <= 0) {
        return;
    }

    // 单链折叠：`A > B > C` 各自只有一个孩子时，每层规模都只是下一层减一，
    // 三行说的是同一件事 —— 而真正要看的是链尾那个分叉。节点树页顶部有 8 层这样的
    // 链（壳 → 卡片 → 列 → 展开 → 卡片 → 树 → 懒滚动 → 滚动盒），折完只剩一行。
    //
    // 三个断开条件都必要：
    // - 写入点要留在自己那一行（`←` 必须紧挨着写它的那个类型，混在链中间就分不清）；
    // - `omitted > 0` 时规模不能再当"下一层减一"往回推，数会对不上；
    // - 只有"单实例"能做链中间的跳板。折叠组可以当链尾（下一层从它展开才有意义），
    //   但夹在中间读起来是"每份里还有一层"，那是另一种话。
    $chain = [$node];
    while (true) {
        $last = $chain[\count($chain) - 1];
        if ((string) $last['kind'] !== 'parent' || (int) $last['omitted'] > 0 || (int) $last['count'] !== 1) {
            break;
        }
        $kids = $last['children'];
        if (\count($kids) !== 1) {
            break;
        }
        $chain[] = $kids[0];
    }
    --$budget;

    /** @var array<string, mixed> $tail 链尾：规模、标记、截断信息都只可能来自它 */
    $tail = $chain[\count($chain) - 1];
    $names = [];
    foreach ($chain as $hop) {
        $names[] = (string) $hop['type'];
    }
    $label = \str_repeat('  ', $depth) . \implode(' > ', $names);
    $count = (int) $tail['count'];
    if ($count > 1) {
        // 折叠行给一个入口 id：`composer debug --why=<id>` 与面板展开都从这里下钻。
        // 单个节点不印 id —— 树讲形状，名单（重建聚合）讲 id，两处各给一份不重复。
        $label .= ' ×' . $count . ' #' . (int) $tail['id'];
    }
    $line = \sprintf('  %-44s %5d', $label, (int) $tail['subtree']);
    if ($count > 1) {
        $line .= \sprintf('  共 %d 个', $count * (int) $tail['subtree']);
    }
    $kind = (string) $tail['kind'];
    if ($kind === 'state') {
        $line .= '  ← ' . (string) $tail['site'];
        if ($tail['detail'] !== '') {
            $line .= '  ' . (string) $tail['detail'];
        }
    } elseif ($kind === 'unknown') {
        // `unknown` 是"重建了但找不到原因"，本身就是信号：不印出来，读者会以为
        // 那几行和别的行一样有归属。
        $line .= '  ← ? 无可见原因';
    }
    // 截断标在行尾：单独一行"还有 N 个分支"每层都来一遍，会把树本身的形状冲散。
    if ((int) $tail['omitted'] > 0) {
        $line .= \sprintf('  … 另 %d 支（%d 个节点）未展开', (int) $tail['omitted'], (int) $tail['hidden']);
    }
    \fwrite(\STDOUT, $line . "\n");

    /** @var list<array<string, mixed>> $kids */
    $kids = $tail['children'];
    foreach ($kids as $kid) {
        cascade_line($kid, $depth + 1, $budget);
        if ($budget <= 0) {
            return;
        }
    }

}

/** 只留路径尾部 N 段：完整链路已经单独展开，行内再贴一遍会挤掉别的信息。 */
function tail_path(string $path, int $keep = 2): string
{
    $parts = \explode(' > ', $path);
    if (\count($parts) <= $keep) {
        return $path;
    }

    return \implode(' > ', \array_slice($parts, -$keep));
}
