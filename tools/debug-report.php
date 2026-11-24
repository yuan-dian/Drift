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
 * @internal 手工工具；不进 V2 判据清单
 */

require __DIR__ . '/autoload.php';

use Drift\App\Application;
use Drift\App\DebugSession;
use Drift\Dev\Smoke\App\PageHostApplication;
use Drift\Dev\Smoke\App\PendingRows;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Input\ScrollEvent;

$target = null;
$page = null;
$frames = 1;
$width = 800;
$height = 600;
$json = false;
$top = 10;
$why = null;
$rows = 0;
$hold = 1;
/** @var list<array{0: 'key'|'text'|'click'|'wheel'|'resolve', 1: string}> */
$steps = [];

foreach (\array_slice($argv, 1) as $arg) {
    if ($arg === '--json') {
        $json = true;
    } elseif (\str_starts_with($arg, '--frames=')) {
        $frames = \max(1, (int) \substr($arg, 9));
    } elseif (\str_starts_with($arg, '--width=')) {
        $width = \max(1, (int) \substr($arg, 8));
    } elseif (\str_starts_with($arg, '--height=')) {
        $height = \max(1, (int) \substr($arg, 9));
    } elseif (\str_starts_with($arg, '--top=')) {
        $top = \max(1, (int) \substr($arg, 6));
    } elseif (\str_starts_with($arg, '--why=')) {
        $why = (int) \substr($arg, 6);
    } elseif (\str_starts_with($arg, '--hold=')) {
        $hold = \max(1, (int) \substr($arg, 7));
    } elseif (\str_starts_with($arg, '--rows=')) {
        $rows = \max(0, (int) \substr($arg, 7));
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

$label = $page ?? $target;
if ($label === null) {
    \fwrite(\STDERR, "用法：drift debug <AppClass|路由id> [--page=<路由id>] [--frames=N] [--rows=N]\n");
    \fwrite(\STDERR, "              [--key=Tab] [--text=abc] [--click=x,y[,键]] [--wheel=x,y,格[,h]]\n");
    \fwrite(\STDERR, "              [--hold=N] [--resolve] [--why=<节点id>] [--top=N] [--width=W] [--height=H] [--json]\n");
    exit(2);
}

$source = $rows > 0 ? new PendingRows($rows) : null;
$fetch = $source === null ? null : $source->fetch(...);

try {
    $app = target_app($page ?? $target, $page !== null, $fetch);
} catch (\Throwable $e) {
    \fwrite(\STDERR, $e->getMessage() . "\n");
    exit(2);
}

if (!$app instanceof Application) {
    \fwrite(\STDERR, "{$label} 不是 Application 子类\n");
    exit(2);
}

$constraints = BoxConstraints::tight(new Size((float) $width, (float) $height));

// ★ 挂载**不在任何帧里**（`buildRoot()` + 首次 layout 都发生在 `attach()` 内），
//   而"打开这一页要多久"恰恰是页面级调试的第二个问题。单独量、单独报，别让它
//   混进首帧 —— 混进去会让"首帧成本"这一列骗人。
$mountStart = \hrtime(true);
$app->attach(new SurfaceProbe(), $constraints);
$mountMs = (\hrtime(true) - $mountStart) / 1e6;

$session = DebugSession::open($app, $constraints);

try {
    $t = 0.0;
    /** @var array<int, float> 帧号 => 整帧墙钟（毫秒） */
    $wall = [];
    $ms = $session->pump($t);
    $wall[$session->recorder()->lastFrameId()] = $ms;
    for ($i = 1; $i < $frames; ++$i) {
        $t += 16.0;
        $ms = $session->pump($t);
        $wall[$session->recorder()->lastFrameId()] = $ms;
    }

    foreach ($steps as [$kind, $spec]) {
        $t += 16.0;
        act($session, $kind, $spec, $t, $source);
        // ★ 动作之后推 `--hold` 帧而不是固定 1 帧：滚轮 / 回弹 / 淡出都是**动画**，
        //   一帧只够"开始"，位移与重绘在后面几帧。固定 1 帧会让"滚了但什么都没变"。
        for ($i = 0; $i < $hold; ++$i) {
            $t += $i === 0 ? 0.0 : 16.0;
            $ms = $session->pump($t);
            $wall[$session->recorder()->lastFrameId()] = $ms;
        }
    }

    $recorded = $session->query('frames', ['limit' => $frames + \count($steps) * $hold + 1]);
    foreach ($recorded as $i => $frame) {
        $recorded[$i]['wall'] = $wall[(int) $frame['id']] ?? $frame['cost'];
    }

    // 逐帧归因走 `explain`：不另读 Recorder，免得 CLI 与面板各写一份"怎么展开一帧"。
    $attribution = [];
    foreach ($recorded as $frame) {
        if ((int) $frame['causes'] === 0) {
            continue;
        }
        $detail = $session->query('explain', ['frame' => (int) $frame['id']]);
        $attribution[] = [
            'id' => (int) $detail['id'],
            't' => (float) $detail['t'],
            'rebuilt' => \count($detail['rebuilt']),
            'byKind' => $detail['byKind'],
            'roots' => $detail['roots'],
        ];
    }

    $allHotspots = $session->query('hotspots', ['frames' => 0, 'limit' => 0]);
    $allNodes = $session->query('nodes', ['frames' => 0, 'limit' => 0]);

    $report = [
        'app' => $label,
        'viewport' => [$width, $height],
        'mountMs' => $mountMs,
        'stats' => $session->query('stats'),
        'frames' => $recorded,
        'attribution' => $attribution,
        // ★ 排行榜被 `--top` 截断时必须说还有多少：319 个节点各重建一次被切成
        //   十行，读者会以为"就这十个"。这正是本项目最不想要的"看起来完整"。
        //   `limit=0` = 全量，两次扫描算完再切，避免同一份聚合跑四遍。
        'hotspots' => \array_slice($allHotspots, 0, $top),
        'hiddenHotspots' => \max(0, \count($allHotspots) - $top),
        'nodes' => \array_slice($allNodes, 0, $top),
        'hiddenNodes' => \max(0, \count($allNodes) - $top),
        'why' => $why === null ? null : $session->query('why', ['node' => $why]),
    ];
} finally {
    $session->close();
}

if ($json) {
    \fwrite(\STDOUT, \json_encode($report, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) . "\n");
    exit(0);
}

render_text($report);
exit(0);

// ---------------------------------------------------------------- 解析与驱动

/**
 * 目标 → 应用实例。
 *
 * 先按 Application 类名解析，再退到示例台路由 id：`table` 这种单词不可能是类名，
 * 而 `ShowcaseApplication` 这种类名也不可能是路由 id —— 两个候选集的交集是空的，
 * 所以"先类名后路由"不会把用户的意图判歪。
 */
function target_app(string $name, bool $forceRoute, ?\Closure $fetchRows = null): Application
{
    if (!$forceRoute) {
        $class = resolve_app($name);
        if ($class !== null) {
            try {
                return new $class();
            } catch (\ArgumentCountError $e) {
                throw new \RuntimeException(
                    "{$class} 的构造函数需要参数：本命令只跑无参应用（装配归组合根）。\n"
                    . '要跑示例台的页面请用 --page=<路由 id>：' . \implode(' / ', PageHostApplication::routes()),
                );
            }
        }
    }

    try {
        return PageHostApplication::forRoute($name, $fetchRows);
    } catch (\InvalidArgumentException $e) {
        throw new \RuntimeException(
            "找不到目标：{$name}\n"
            . "  作为应用类：试过 Drift\\Dev\\Smoke\\App\\ 与 Drift\\Dev\\Smoke\\\n"
            . '  作为页面路由：' . $e->getMessage(),
        );
    }
}

/** @return class-string<Application>|null */
function resolve_app(string $name): ?string
{
    $candidates = \str_contains($name, '\\')
        ? [$name]
        : ['Drift\\Dev\\Smoke\\App\\' . $name, 'Drift\\Dev\\Smoke\\' . $name];

    foreach ($candidates as $candidate) {
        if (\class_exists($candidate) && \is_subclass_of($candidate, Application::class)) {
            return $candidate;
        }
    }

    return null;
}

/** 走真实派发链（加速键 → IME → 焦点 → 手势），不直接改 State。 */
function act(DebugSession $session, string $kind, string $spec, float $t, ?PendingRows $source): void
{
    switch ($kind) {
        case 'text':
            $session->send(KeyEvent::character($spec), $t);

            return;
        case 'key':
            $session->send(KeyEvent::down(key_chord($spec)), $t);

            return;
        case 'resolve':
            // 结算在**帧外**发生：这正是真实异步的形状（后端在两次帧之间回贴），
            // 写入由下一帧的 settle 消费 ⇒ 报告里能看到是"哪一帧"接住的。
            if ($source === null || $source->resolveNext() === null) {
                \fwrite(\STDERR, "没有在途的取数请求：先给 --rows=N 再 --resolve\n");
            }

            return;
        case 'click':
            // 按下 → 抬起一次成型：焦点/选中在前者，激活在后者，中间不推帧
            // （真实用户点击也不可能在两者之间插一帧）。
            [$x, $y, $button] = parse_click($spec);
            $session->send(PointerEvent::down($x, $y, $button), $t);
            $session->send(PointerEvent::up($x, $y, $button), $t);

            return;
        case 'wheel':
            [$x, $y, $delta, $axis] = parse_wheel($spec);
            $session->send(ScrollEvent::make($x, $y, $delta, $axis), $t);

            return;
    }
}

/** `x,y[,键]` → 三个值。键：0 左 / 1 中 / 2 右。 */
function parse_click(string $spec): array
{
    $parts = \explode(',', $spec);
    if (\count($parts) < 2) {
        \fwrite(\STDERR, "点击坐标要写成 --click=x,y[,键]：{$spec}\n");
        exit(2);
    }

    return [(float) $parts[0], (float) $parts[1], isset($parts[2]) ? (int) $parts[2] : 0];
}

/** `x,y,格[,h]` → 四个值。**单位是格**（`ScrollEvent` 的口径），正 = 往正方向看。 */
function parse_wheel(string $spec): array
{
    $parts = \explode(',', $spec);
    if (\count($parts) < 3) {
        \fwrite(\STDERR, "滚轮要写成 --wheel=x,y,格[,h]：{$spec}\n");
        exit(2);
    }
    $axis = isset($parts[3]) && \str_starts_with(\strtolower($parts[3]), 'h')
        ? Axis::Horizontal
        : Axis::Vertical;

    return [(float) $parts[0], (float) $parts[1], (float) $parts[2], $axis];
}

/** `Ctrl+A` / `Shift+Tab` / `Enter` / `a` → 键码；单字符走文本输入。 */
function key_chord(string $spec): KeyChord
{
    $ctrl = false;
    $alt = false;
    $shift = false;

    while (($pos = \strpos($spec, '+')) !== false) {
        $modifier = \strtolower(\substr($spec, 0, $pos));
        $spec = \substr($spec, $pos + 1);
        match ($modifier) {
            'ctrl' => $ctrl = true,
            'alt' => $alt = true,
            'shift' => $shift = true,
            default => null,
        };
    }

    $named = [
        'tab' => 'Tab', 'enter' => 'Enter', 'esc' => 'Escape', 'escape' => 'Escape',
        'space' => ' ', 'backspace' => 'Backspace', 'delete' => 'Delete', 'del' => 'Delete',
        'left' => 'Left', 'right' => 'Right', 'up' => 'Up', 'down' => 'Down',
        'home' => 'Home', 'end' => 'End', 'pageup' => 'PageUp', 'pagedown' => 'PageDown',
    ];
    $key = $named[\strtolower($spec)] ?? $spec;
    if ($key === '') {
        $key = ' ';
    }
    if (\mb_strlen($key, 'UTF-8') === 1) {
        $key = \mb_strtoupper($key, 'UTF-8'); // 单字符键码统一大写，与 KeyEvent::character 同源
    }

    return KeyChord::make($key, $ctrl, $alt, $shift);
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
        "应用  %s\n视口  %dx%d     帧数 %d     登记节点 %d     挂载 %.2fms\n",
        (string) $report['app'],
        (int) $report['viewport'][0],
        (int) $report['viewport'][1],
        (int) $stats['frames'],
        (int) $stats['nodes'],
        (float) $report['mountMs'],
    ));
    \fwrite(\STDOUT, \sprintf(
        "墙钟  末帧 %.2fms   平均 %.2fms   p95 %.2fms   最慢 %.2fms(#%d)\n\n",
        $last,
        \array_sum($walls) / \max(1, \count($walls)),
        percentile($walls, 0.95),
        \max($walls),
        slowest_frame($frames),
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

    \fwrite(\STDOUT, "\n根因（kind=state，次数 / 出现帧数 / 涉及节点 / 调用点）\n");
    if ($hotspots === []) {
        \fwrite(\STDOUT, "  （这段里没有状态写入：静止画面不会产生因果边）\n");
    }
    foreach ($hotspots as $row) {
        \fwrite(\STDOUT, \sprintf(
            "  %-4d %-4d %-4d %s  %s\n",
            (int) $row['writes'],
            (int) $row['frames'],
            (int) $row['nodes'],
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

    \fwrite(\STDOUT, "\n重建最多（次数 / 节点id / 自身写入 / 父传播 / 路径）\n");
    if ($nodes === []) {
        \fwrite(\STDOUT, "  （这段里没有节点重建）\n");
    }
    foreach ($nodes as $row) {
        \fwrite(\STDOUT, \sprintf(
            "  %-4d %-6d %-4d %-4d %s\n",
            (int) $row['rebuilds'],
            (int) $row['id'],
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
        foreach ($why['causes'] as $cause) {
            \fwrite(\STDOUT, \sprintf(
                "  %-8s %s%s\n",
                (string) $cause['kind'],
                (string) $cause['site'],
                $cause['detail'] === '' ? '' : '  ' . (string) $cause['detail'],
            ));
        }
    }
}

/** 最慢的一帧的帧号（用于把"最慢"与帧表对上）。 */
function slowest_frame(array $frames): int
{
    $best = 0;
    $bestWall = -1.0;
    foreach ($frames as $frame) {
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
