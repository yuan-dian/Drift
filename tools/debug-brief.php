#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * `debug-brief` —— 证据层的第一个可用出口：跑一段页面，把它压成一份预算内的简报。
 *
 * 它存在的目的不是"多一个命令"，是验证证据模型里那条假设：
 * **现有查询面的事实足以自动指出"哪里不对"，不需要任何新采集。**
 * 如果这份简报读起来像"有人已经看过一遍"，假设成立；如果它只能复述数字，
 * 说明缺的不是投影层，是采集层 —— 那要在写 S1 之前就知道。
 *
 * 输入全部来自查询面（没有一行直接摸 `Recorder`），所以本地会话与 `--remote`
 * 走的是同一条路：这正是"消费者平权"的第一个可执行证据。
 *
 * 用法：
 *   php tools/debug-brief.php --page=table --frames=1 --click=400,300
 *   php tools/debug-brief.php --page=table --frames=1 --click=400,300 --json
 *   php tools/debug-brief.php --page=async-table --rows=6 --frames=2 --resolve --hold=3
 *   php tools/debug-brief.php --page=table --frames=1 --remote
 *
 * 参数：
 *   --page=<路由id>   页面（与面板同义）
 *   --frames=N        驱动几帧（默认 1）
 *   --rows=N          取数替身参数（异步页需要）
 *   --click=x,y       点一下（可重复）
 *   --key= / --text= / --wheel= / --resolve / --hold=N  与面板同一套驱动词表
 *   --at=N            只重放到第 N 帧
 *   --remote          用宿主进程跑（验证跨进程读到同一份证据）
 *   --out=<文件>      把这次会话导出成证据包（NDJSON，首行 manifest）
 *   --from=<文件>     不跑会话，直接读证据包复算（与 --page / 驱动参数互斥）
 *   --budget=N        行预算（默认 40）
 *   --width=N         排版宽度（默认 100）
 *   --view-width=N    视口宽度（默认 800；布局复现用，别与排版宽度混）
 *   --height=N        视口高度（默认 600）
 *   --json            输出结构化结果（发现 + 裁剪账 + 指纹）
 *
 * @internal 未进 VerifyPlan：规则阈值还在定，进 CI 会变成"改阈值就红一片"
 */

require __DIR__ . '/autoload.php';

use Drift\App\Replay;
use Drift\Debug\Protocol\RemoteSession;
use Drift\Debug\Session\Evidence;
use Drift\Debug\Session\QueryRouter;
use Drift\Debug\Session\Recorder;
use Drift\Debug\Session\SessionQueries;
use Drift\Dev\Debug\Plan;
use Drift\Dev\Debug\ProcessTransport;
use Drift\Dev\Debug\Runner;
use Drift\Dev\Debug\View\Brief;

$target = null;
$page = null;
$frames = 1;
$framesGiven = false;
$from = null;
$out = null;
$width = 800;
$height = 600;
$rows = 0;
$hold = 1;
$at = null;
$remote = false;
$json = false;
$budget = 40;
$textWidth = 100;
/** @var list<array{0: 'key'|'text'|'click'|'wheel'|'resolve', 1: string}> */
$steps = [];

foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $json = true;
    } elseif ($arg === '--remote') {
        $remote = true;
    } elseif ($arg === '--resolve') {
        $steps[] = ['resolve', ''];
    } elseif (\str_starts_with($arg, '--frames=')) {
        $frames = \max(1, (int) \substr($arg, 9));
        $framesGiven = true;
    } elseif (\str_starts_with($arg, '--from=')) {
        $from = \substr($arg, 7);
    } elseif (\str_starts_with($arg, '--out=')) {
        $out = \substr($arg, 6);
    } elseif (\str_starts_with($arg, '--width=')) {
        $textWidth = \max(40, (int) \substr($arg, 8));
    } elseif (\str_starts_with($arg, '--view-width=')) {
        $width = \max(1, (int) \substr($arg, 13));
    } elseif (\str_starts_with($arg, '--height=')) {
        $height = \max(1, (int) \substr($arg, 9));
    } elseif (\str_starts_with($arg, '--rows=')) {
        $rows = \max(0, (int) \substr($arg, 7));
    } elseif (\str_starts_with($arg, '--hold=')) {
        $hold = \max(1, (int) \substr($arg, 7));
    } elseif (\str_starts_with($arg, '--at=')) {
        $at = \max(1, (int) \substr($arg, 5));
    } elseif (\str_starts_with($arg, '--budget=')) {
        $budget = \max(8, (int) \substr($arg, 9));
    } elseif (\str_starts_with($arg, '--page=')) {
        $page = \substr($arg, 7);
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

$label = $from !== null ? \basename($from) : ($page ?? $target);

$session = null;
$closable = null;
try {
    if ($from !== null) {
        // 离线：包里已经有完整的驱动记录，再给驱动参数就是两套说法，不猜以谁为准。
        if ($page !== null || $target !== null || $steps !== [] || $framesGiven) {
            throw new \InvalidArgumentException(
                '--from 与 --page / 目标 / --frames / 驱动参数互斥：包里已经有完整的驱动记录',
            );
        }
        if ($out !== null) {
            // 离线读出的录制器是游离实例，不再产生新记录。真要另存（裁域 / 换格式）
            // 得重跑一次会话——顺手"读进来再写出去"会写出与源包不同的东西而不自知。
            throw new \InvalidArgumentException(
                '--from 与 --out 不能同用：离线复算不产生新记录，要另存请重跑一次会话',
            );
        }

        $offline = Recorder::hydrate(Evidence::read($from));
        $session = QueryRouter::offline($offline);
        // 标签优先取包里的脚本目标：那才是"这份证据是跑哪一页得到的"。
        $label = $offline->script()?->target ?? \basename($from);
    } else {
        if ($label === null) {
            \fwrite(\STDERR, "用法：debug-brief <AppClass|路由id> [--page=<路由id>] [--frames=N] [--json]\n");
            \fwrite(\STDERR, "      debug-brief --from=<证据包> [--json]\n");
            exit(2);
        }
        if ($out !== null && $remote) {
            throw new \InvalidArgumentException('--out 不能与 --remote 同用：导出要本地那份记录');
        }

        $meta = $rows > 0 ? ['rows' => $rows] : [];
        if ($page !== null) {
            $meta['page'] = 1;
        }

        $drive = Plan::build((string) $label, (float) $width, (float) $height, $meta, $frames, $hold, $steps);

        if ($remote) {
            $session = new RemoteSession(ProcessTransport::spawn([
                \PHP_BINARY,
                \dirname(__DIR__) . '/tools/debug-host.php',
            ]));
            $session->open($drive->fork());
        } else {
            $opened = Runner::open((string) $label, (float) $width, (float) $height, $meta, $drive->fork());
            $session = $opened['session'];
        }
        $closable = $session;

        Replay::apply($session, $drive, [], $at, static function (int $id, float $ms): void {
        });
    }

    $facts = gather($session, (string) $label);

    // 导出放在取事实之后：几何是帧末采的，早于最后一帧导出会少一段。
    if ($out !== null) {
        $recorder = Recorder::current();
        if ($recorder === null) {
            throw new \RuntimeException('--out 要本地那份记录，当前没有录制器');
        }
        Evidence::write($recorder, $out);
    }
    $brief = Brief::project($facts, $budget, $textWidth);

    if ($json) {
        \fwrite(\STDOUT, (string) \json_encode([
            'label' => $label,
            'window' => $facts['window'],
            'digest' => $facts['digest']['digest'] ?? '',
            'findings' => \array_map(
                static fn(\Drift\Dev\Debug\View\Finding $one): array => $one->toArray(),
                $brief['findings'],
            ),
            'omitted' => $brief['omitted'],
            'lines' => $brief['lines'],
        ], \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT) . "\n");
    } else {
        foreach ($brief['lines'] as $line) {
            \fwrite(\STDOUT, $line . "\n");
        }
    }
} catch (\Throwable $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
} finally {
    $closable?->close();
}

/**
 * 从查询面取事实。**不碰 `Recorder`** —— 远端会话没有它。
 *
 * `limit: 0` 是"不截断"（查询层对 0 的约定）。这一条是自报裁剪的前提：
 * 裁剪只能发生在投影层，由查询层先截断的话，投影层就数不出自己裁了多少。
 *
 * @return array<string, mixed>
 */
function gather(SessionQueries $session, string $label): array
{
    $stats = $session->query('stats');
    // `frames` 自 schema 收口后返回信封（`frames` / `window` / `dropped`）：裸列表在类型上
    // 表达不了"漏了 N 帧"，而简报的每个数字都要能说清自己覆盖了哪一段。
    $framePage = $session->query('frames', ['limit' => 0]);
    $frames = $framePage['frames'];
    // `frames: 0` 与 `limit: 0` 是两件事：前者是**聚合窗口**（默认只看最近 300 帧），
    // 后者是**返回条数**（默认 10 条）。只关掉后者，聚合仍然只数最近 300 帧 ——
    // 而表头写的是整个窗口，"出现 3 帧"就会与 `frames` 的帧数对不上。
    // 两处都归零，简报里的每个数字才都能回溯到同一批帧。
    $hotspots = $session->query('hotspots', ['frames' => 0, 'limit' => 0]);
    $nodes = $session->query('nodes', ['frames' => 0, 'limit' => 0]);
    $digest = $session->query('digest');

    $ids = [];
    $worst = 0;
    $worstCost = -1.0;
    foreach ($frames as $frame) {
        $ids[] = (int) $frame['id'];
        $cost = (float) ($frame['cost'] ?? 0.0);
        if ($cost > $worstCost) {
            $worstCost = $cost;
            $worst = (int) $frame['id'];
        }
    }

    $facts = [
        'label' => $label,
        'stats' => $stats,
        'frames' => $frames,
        'hotspots' => $hotspots,
        'nodes' => $nodes,
        'digest' => $digest,
        'window' => [
            'from' => $ids === [] ? 0 : $ids[0],
            'to' => $ids === [] ? 0 : $ids[\count($ids) - 1],
            'count' => \count($ids),
            'capacity' => (int) ($stats['capacity'] ?? 0),
        ],
    ];

    if ($worst > 0) {
        $facts['explain'] = $session->query('explain', ['frame' => $worst]);
        // 因果树要**全量**（`maxChildren: 0`）：截断交给投影层，它才知道裁了多少。
        // 深度给 12：同形组常常在第三、第四层（表格页是 `Card > Column > SizedBox ×12`），
        // 只给 2 层就永远看不到"列表没虚拟化"这一类。
        //
        // ★ 请求的深度要**原样记进 facts**：投影层据此判断"规则看到的是不是完整的树"。
        //   规则自己不许另设一个深度上限 —— 两边各写一个常量，迟早打架，而打架的
        //   表现形式是"报告显示没问题"。
        $cascadeQuery = ['frame' => $worst, 'maxDepth' => 12, 'maxChildren' => 0];
        $facts['cascadeQuery'] = $cascadeQuery;
        $facts['cascade'] = $session->query('cascade', $cascadeQuery);
    }

    // 几何读同一个最贵帧：布局的"为什么"要与因果的"为什么"对齐到一帧，
    // 否则报告里 `frame:2 node:61` 的两种答案来自两次运行。
    // 走查询面而不是摸 Recorder —— 远端会话没有后者。
    $facts['geometry'] = $worst > 0
        ? $session->query('boxes', ['frame' => $worst])
        : $session->query('boxes');

    return $facts;
}
