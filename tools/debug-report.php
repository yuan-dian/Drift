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
use Drift\Dev\Debug\Cli\Commands;
use Drift\Dev\Debug\View\Report;
use Drift\Dev\Debug\Plan;
use Drift\Dev\Debug\ProcessTransport;
use Drift\Dev\Debug\Runner;

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

foreach (Report::lines($report) as $line) {
    \fwrite(\STDOUT, $line . "\n");
}
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
        $recorded = $session->query('frames', ['limit' => \max(1, \count($frameTimes))])['frames'];
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
        $slowestId = Report::slowestFrame($recorded);
        $cascadeId = Report::slowestFrame($recorded, true);
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
