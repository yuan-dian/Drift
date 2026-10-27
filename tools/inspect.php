<?php
declare(strict_types=1);

/**
 * `inspect` —— 把一棵已渲染的树导出成**统一观测快照**，并支持与基线比对。
 *
 * 这是"检查器"的**数据侧**（不是 UI 侧）：同一份快照同时喂给
 * ① 机器判据（`--check` 与基线 diff）② 人看的树（`--tree`）③ 后续的真机定位。
 * 三者此前各自有入口（smoke 几何断言 / golden 像素 / 真机截图），失败消息互不相通。
 *
 * ```bash
 * php tools/inspect.php --page=App\Showcase\Pages\TablePage            # 四段计数
 * php tools/inspect.php --page=App\Showcase\Pages\TablePage --tree     # 人读的树
 * php tools/inspect.php --page=App\Showcase\Pages\TablePage --json     # 完整快照
 * php tools/inspect.php --page=… --update=_build/base.json             # 写基线
 * php tools/inspect.php --page=… --check=_build/base.json              # 与基线比（不同 ⇒ 退出码 1）
 * php tools/inspect.php --app=App\Showcase\ShowcaseApplication --w=1920 --h=1017 --point=5   # 真机定位
 * ```
 *
 * ★★ `--page` vs `--app`（**P1' 真机定位的关键区别**）：
 *   - `--page` 挂**页面**：矩形是**页面内**坐标。真机上页面只是客户区的一块，
 *     还差一层壳偏移（顶条 + 左导航）⇒ **不能直接喂 probe-grab**。
 *   - `--app`  挂**整个应用根**（走 `Application::buildRoot()`，对任何应用通用）
 *     ⇒ 顶层矩形就是**窗口坐标**，配上真机客户区尺寸（`--w/--h`）即可直接用。
 *   ★ 挂哪个决定"坐标属于哪个坐标系" —— 这正是真机点击差 8–10px 就**静默失效**的根因。
 *
 * ★ `--point` / `--emit`：把"身份"翻译成"真机能用的一个点"。
 *   `--point=<key>` 或 `--point=path:0.3.2` 或 `--point=x,y`（自动判别三种选择器），
 *   `--emit=click|dblclick|move|wheel|hwheel` 直接拼出 probe-grab 的参数串。
 * ★ **它不替代 smoke / golden**：
 *   - smoke 断言"某个值必须等于某数"（判据写死在代码里，改行为要先改断言）
 *   - golden 断言"像素一个字节都不许变"（外观）
 *   - inspect 断言"**结构 + 几何 + 语义 + 指令**与基线一致"（可读、可定位）
 *   三者观测面不同，互为补充。inspect 的独有价值是**失败消息可读**：
 *   `元素 0.3.2 Text 矩形 (20,120 100×24) ⇒ (20,124 100×24)` 直接指向病灶。
 *
 * ★ 为什么不进 `gate.php`：快照基线对"合法的结构改动"极敏感（加一个节点就变），
 *   现在还没有稳定的字段集与更新纪律 ⇒ 先当**手动量具**用（同 golden 早期的定位）。
 */

require __DIR__ . '/autoload.php';

use Drift\App\Application;
use Drift\Dev\Inspect\Snapshot;
use Drift\Widget\Widget;

/** @return array<string, string> */
function inspectArgs(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $body = substr($arg, 2);
        $eq = strpos($body, '=');
        if ($eq === false) {
            $out[$body] = '1';
        } else {
            $out[substr($body, 0, $eq)] = substr($body, $eq + 1);
        }
    }

    return $out;
}

/**
 * 取**最深层**的包含节点（= 视觉最上层）。
 *
 * ★ `--at` 与 `--point=坐标` 共用这一份：命中规则只有一处，改行为不会只改一边。
 *
 * @param list<mixed> $elements
 * @return array<string, mixed>|null
 */
function deepestAt(array $elements, float $x, float $y): ?array
{
    $best = null;
    foreach ($elements as $n) {
        if (!is_array($n)) {
            continue;
        }
        $r = $n['r'] ?? [null, null, null, null];
        if ($r[0] === null) {
            continue;
        }
        if ($x >= (float) $r[0] && $x <= (float) $r[0] + (float) $r[2]
            && $y >= (float) $r[1] && $y <= (float) $r[1] + (float) $r[3]) {
            if ($best === null || $n['d'] > $best['d']) {
                $best = $n;
            }
        }
    }

    return $best;
}

/**
 * 身份 → 节点。三种选择器自动判别：`path:0.3.2` / `key` / `x,y`。
 *
 * @return array<string, mixed>|null
 */
function resolveTarget(array $snapshot, string $selector): ?array
{
    $elements = $snapshot['trees']['elements'] ?? [];
    if (!is_array($elements)) {
        return null;
    }
    if (str_contains($selector, ',')) {
        [$ax, $ay] = array_map('floatval', explode(',', $selector));

        return deepestAt($elements, $ax, $ay);
    }
    if (str_starts_with($selector, 'path:')) {
        $want = substr($selector, 5);
        foreach ($elements as $n) {
            if (is_array($n) && ($n['p'] ?? null) === $want) {
                return $n;
            }
        }

        return null;
    }
    foreach ($elements as $n) {
        if (is_array($n) && ($n['key'] ?? null) !== null && (string) $n['key'] === $selector) {
            return $n;
        }
    }

    return null;
}

/**
 * 节点 → 动作点。`anchor` 只影响 x（真机点击常要落在左/右边缘，比如插入符落点）。
 *
 * @param array<string, mixed> $node
 * @return array{0: float, 1: float}
 */
function pointOf(array $node, string $anchor): array
{
    $r = $node['r'];
    $x = (float) $r[0];
    $y = (float) $r[1];
    $w = (float) $r[2];
    $h = (float) $r[3];
    if ($anchor === 'left') {
        $cx = $x + 1.0;
    } elseif ($anchor === 'right') {
        $cx = $x + $w - 1.0;
    } else {
        $cx = $x + $w / 2.0;
    }

    return [$cx, $y + $h / 2.0];
}

/** 把动作点拼成 probe-grab 的参数（P1' 真机外壳直接消费这一行输出） */
function emitProbe(string $op, float $x, float $y, int $delta): string
{
    $xy = number_format($x, 0, '.', '') . ',' . number_format($y, 0, '.', '');
    if ($op === 'click') {
        return '--click=' . $xy;
    }
    if ($op === 'dblclick') {
        return '--dblclick=' . $xy;
    }
    if ($op === 'move') {
        return '--move=' . $xy;
    }
    if ($op === 'wheel') {
        return '--wheel=' . $xy . ',' . $delta;
    }
    if ($op === 'hwheel') {
        return '--hwheel=' . $xy . ',' . $delta;
    }
    if ($op === 'at') {
        return $xy;
    }
    fwrite(STDERR, "未知 --emit 动作：{$op}（at / click / dblclick / move / wheel / hwheel）\n");
    exit(2);
}

$args = inspectArgs($argv);
$appClass = $args['app'] ?? null;
$pageClass = $args['page'] ?? 'App\\Showcase\\Pages\\TablePage';
$width = (int) ($args['w'] ?? 900);
$height = (int) ($args['h'] ?? 640);
$depth = (int) ($args['depth'] ?? 99);
$limit = (int) ($args['limit'] ?? 20);

// ★ `--app=` 挂**整个应用根**（顶条 + 导航 + 内容都在这一棵树里）⇒ 矩形即**窗口坐标**，
//   真机探针可直接用。`--page` 只挂页面 ⇒ 坐标还差一层壳偏移，喂给 probe-grab 会点偏。
if ($appClass !== null) {
    if (!class_exists($appClass) || !is_subclass_of($appClass, Application::class)) {
        fwrite(STDERR, "--app 必须是 Application 子类：{$appClass}\n");
        exit(2);
    }
    /** @var Application $app */
    $app = new $appClass();
    $root = $app->buildRoot();
    $pageClass = $appClass; // 后续文案统一用它
} else {
    if (!class_exists($pageClass)) {
        fwrite(STDERR, "找不到页面类：{$pageClass}\n");
        exit(2);
    }
    /** @var Widget $root */
    $root = new $pageClass();
}

$t0 = hrtime(true);
$snapshot = Snapshot::ofPage($root, $width, $height);
$elapsed = (hrtime(true) - $t0) / 1e6;

// ★ `JSON_PRESERVE_ZERO_FRACTION`：不加它 `0.0` 会被写成 `0`，读回来是 int
//   ⇒ 快照的"几何"段在往返后类型就变了（严格比较会全红）。见 Snapshot::diff() 的注释。
$json = json_encode(
    $snapshot,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_PRESERVE_ZERO_FRACTION,
);
if ($json === false) {
    fwrite(STDERR, "快照序列化失败\n");
    exit(2);
}

// ── 模式：按坐标命中 / 按 key 定位（S4 交互式 Inspector 与 P1' 真机定位的共享核心）──
// 两者都依赖"节点↔坐标"的可机读映射：本模式只产出数据，窗口外壳（S4）与真机探针
// （P1'）都是它的薄消费者。纯离线、与 Snapshot 同管线 ⇒ 现在就能验证。
if (isset($args['at'])) {
    [$ax, $ay] = array_map('floatval', explode(',', $args['at']));
    $elements = is_array($snapshot['trees']['elements'] ?? null) ? $snapshot['trees']['elements'] : [];
    $best = deepestAt($elements, $ax, $ay);
    if ($best === null) {
        echo "点 ({$ax},{$ay}) 未命中任何节点\n";
        exit(1);
    }
    $idx = [];
    foreach ($elements as $n) {
        if (is_array($n) && isset($n['p'])) {
            $idx[$n['p']] = $n;
        }
    }
    $chain = []; // 祖先链（按结构路径前缀逐级收拢）
    $cur = '';
    foreach (explode('.', $best['p']) as $seg) {
        $cur = $cur === '' ? $seg : $cur . '.' . $seg;
        if (isset($idx[$cur])) {
            $chain[] = $idx[$cur];
        }
    }
    $rb = $best['r'];
    echo '命中 ' . $best['p'] . ' ' . $best['w'] . ' → ' . $best['b'] . ' @('
        . number_format((float) $rb[0], 1) . ',' . number_format((float) $rb[1], 1) . ' '
        . number_format((float) $rb[2], 1) . '×' . number_format((float) $rb[3], 1) . ")\n";
    echo "祖先链：\n";
    foreach ($chain as $c) {
        echo '  ' . $c['p'] . ' ' . $c['w'] . ($c['key'] !== null ? ' #' . $c['key'] : '') . "\n";
    }
    exit(0);
}

if (isset($args['locate'])) {
    $kv = $args['locate'];
    $found = [];
    foreach (($snapshot['trees']['elements'] ?? []) as $n) {
        if (!is_array($n)) {
            continue;
        }
        $k = $n['key'] ?? null;
        if ($k === null) {
            continue;
        }
        if ((string) $k === (string) $kv) {
            $found[] = $n;
        }
    }
    if ($found === []) {
        echo "未找到 key={$kv} 的节点\n";
        exit(1);
    }
    echo '定位 key=' . $kv . '：' . count($found) . " 个节点\n";
    foreach ($found as $n) {
        $r = $n['r'];
        echo '  ' . $n['p'] . ' ' . $n['w'] . ' → ' . $n['b'] . ' @('
            . number_format((float) $r[0], 1) . ',' . number_format((float) $r[1], 1) . ' '
            . number_format((float) $r[2], 1) . '×' . number_format((float) $r[3], 1) . ")\n";
    }
    exit(0);
}

// ── 模式：身份 → 动作点（**P1' 真机外壳**消费的就是这一行）──
// ★ 为什么还要它：`--locate` 给的是**矩形**，而 probe-grab 要的是**一个点**；
//   且这点必须是窗口坐标 ⇒ 配合 `--app=` + 真机客户区尺寸使用。
if (isset($args['point'])) {
    $selector = (string) $args['point'];
    $node = resolveTarget($snapshot, $selector);
    if ($node === null) {
        fwrite(STDERR, "定位不到目标：{$selector}\n");
        exit(1);
    }
    $anchor = (string) ($args['anchor'] ?? 'center');
    [$px, $py] = pointOf($node, $anchor);
    echo '目标 ' . $node['p'] . ' ' . $node['w']
        . ($node['key'] !== null ? ' #' . $node['key'] : '')
        . ' @(' . number_format($px, 1) . ',' . number_format($py, 1) . ")\n";
    if (isset($args['emit'])) {
        echo emitProbe((string) $args['emit'], $px, $py, (int) ($args['delta'] ?? 120)) . "\n";
    }
    exit(0);
}

// ── 模式一：与基线比对 ──
if (isset($args['check'])) {
    $file = $args['check'];
    if (!is_file($file)) {
        fwrite(STDERR, "没有基线：{$file}（先跑 --update={$file}）\n");
        exit(2);
    }
    /** @var array<string, mixed>|null $base */
    $base = json_decode((string) file_get_contents($file), true);
    if (!is_array($base)) {
        fwrite(STDERR, "基线不是合法 JSON：{$file}\n");
        exit(2);
    }
    if (($base['schema'] ?? null) !== Snapshot::SCHEMA) {
        fwrite(STDERR, '基线 schema ' . (string) ($base['schema'] ?? '无') . ' ≠ 当前 '
            . Snapshot::SCHEMA . "（格式变了，基线要重新生成）\n");
        exit(2);
    }

    $diff = Snapshot::diff($base, $snapshot, $limit);
    if ($diff['total'] === 0) {
        echo "SNAPSHOT OK（{$pageClass}，无差异）\n";
        exit(0);
    }

    echo "SNAPSHOT DIFF（{$pageClass}，共 {$diff['total']} 条）\n";
    foreach ($diff['lines'] as $line) {
        echo '  · ' . $line . "\n";
    }
    if ($diff['total'] > count($diff['lines'])) {
        echo '  …还有 ' . ($diff['total'] - count($diff['lines'])) . " 条（--limit= 可放宽）\n";
    }
    exit(1);
}

// ── 模式二：写基线 ──
if (isset($args['update'])) {
    $file = $args['update'];
    $dir = dirname($file);
    if ($dir !== '' && !is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        fwrite(STDERR, "建不了目录：{$dir}\n");
        exit(2);
    }
    if (file_put_contents($file, $json . "\n") === false) {
        fwrite(STDERR, "写不了：{$file}\n");
        exit(2);
    }
    echo "updated {$file}（" . strlen($json) . " 字节）\n";
    exit(0);
}

// ── 模式三：完整 JSON ──
if (isset($args['json'])) {
    if (isset($args['out'])) {
        if (file_put_contents($args['out'], $json . "\n") === false) {
            fwrite(STDERR, "写不了：{$args['out']}\n");
            exit(2);
        }
        echo "written {$args['out']}（" . strlen($json) . " 字节）\n";
    } else {
        echo $json . "\n";
    }
    exit(0);
}

// ── 模式四：人读的树 ──
if (isset($args['tree'])) {
    echo Snapshot::toTree($snapshot, $depth);
    exit(0);
}

// ── 默认：四段计数（一眼看出"这棵树有多复杂"）──
$elements = $snapshot['trees']['elements'] ?? [];
$boxes = $snapshot['trees']['boxes'] ?? [];
$semantics = $snapshot['semantics']['count'] ?? 0;
$paint = $snapshot['paint'] ?? ['ops' => 0, 'histogram' => []];

echo "page      {$pageClass}（{$width}×{$height}）\n";
echo '元素树    ' . (is_array($elements) ? count($elements) : 0) . " 节点\n";
echo '盒树      ' . (is_array($boxes) ? count($boxes) : 0) . " 节点\n";
echo '语义树    ' . (is_string($semantics) || is_int($semantics) ? $semantics : 0) . " 节点\n";
echo '绘制指令  ' . (int) ($paint['ops'] ?? 0) . " 条\n";
$histogram = $paint['histogram'] ?? [];
if (is_array($histogram) && $histogram !== []) {
    $parts = [];
    foreach ($histogram as $op => $n) {
        $parts[] = $op . '×' . (int) $n;
    }
    echo '  分布    ' . implode(' ', $parts) . "\n";
}
echo '快照      ' . strlen($json) . " 字节（JSON 缩进后）· 生成 {$elapsed}ms\n";
echo "  --tree 看树 · --json 全量 · --update=<f> 写基线 · --check=<f> 比基线\n";
exit(0);
