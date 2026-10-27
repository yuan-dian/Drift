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
 * ```
 *
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

$args = inspectArgs($argv);
$pageClass = $args['page'] ?? 'App\\Showcase\\Pages\\TablePage';
$width = (int) ($args['w'] ?? 900);
$height = (int) ($args['h'] ?? 640);
$depth = (int) ($args['depth'] ?? 99);
$limit = (int) ($args['limit'] ?? 20);

if (!class_exists($pageClass)) {
    fwrite(STDERR, "找不到页面类：{$pageClass}\n");
    exit(2);
}

/** @var Widget $page */
$page = new $pageClass();

$t0 = hrtime(true);
$snapshot = Snapshot::ofPage($page, $width, $height);
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
    $best = null;
    foreach ($elements as $n) {
        if (!is_array($n)) {
            continue;
        }
        $r = $n['r'] ?? [null, null, null, null];
        if ($r[0] === null) {
            continue;
        }
        if ($ax >= $r[0] && $ax <= $r[0] + $r[2] && $ay >= $r[1] && $ay <= $r[1] + $r[3]) {
            if ($best === null || $n['d'] > $best['d']) {
                $best = $n; // 取**最深层**的包含节点（= 视觉最上层）
            }
        }
    }
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
