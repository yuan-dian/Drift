<?php
declare(strict_types=1);

namespace Drift\Dev\Inspect;

use Drift\App\Frame;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderObject;
use Drift\Rendering\DisplayList;
use Drift\Rendering\RecordingCanvas;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Element;
use Drift\Widget\SemanticsBuilder;
use Drift\Widget\Widget;
use ReflectionClass;

/**
 * 统一观测快照：**一棵树、一份数据、三个消费者**（`07` §1.5 的四层观测面合流）。
 *
 * ## 为什么要有它（2026-10-27）
 *
 * 本仓此前有**四个互不相通的观测面**，各有自己的入口脚本：
 *
 * | 面 | 数据 | 现入口 |
 * |---|---|---|
 * | 元素树 | Widget 结构 + 盒几何 | 无（只有 `LayoutSelfCheck` 的私有扁平化） |
 * | 盒树 | 绘制顺序 + 尺寸 | 无 |
 * | 语义树 | role / name / value / 矩形 | `SemanticsBuilder` → UIA |
 * | 绘制指令 | 19 种 op 的序列 | `RecordingCanvas` → `DisplayList` |
 *
 * 于是**同一个 bug 要在三处各写一遍**（smoke 的几何断言 / golden 的像素 /
 * 真机截图），而三处的失败消息彼此不可跳转。这份类把它们导出成**一份 JSON**，
 * 让"断言 / 基线 diff / 人看的报告 / 真机定位"都从同一份数据派生。
 *
 * ## 形状
 *
 * ```json
 * {"schema":1, "size":{"w":900,"h":640},
 *  "trees":{"elements":[{"p":"0.3.2","d":2,"w":"Card","b":"RenderCard","r":[20,120,100,80]}],
 *           "boxes":  [{"p":"0.3.2","c":"RenderCard","w":100.0,"h":80.0}]},
 *  "semantics":{"count":13,"nodes":[…]},          // 语义段：SemanticsTree::toArrays() 原样
 *  "paint":{"ops":304,"histogram":{"SAVE":84,…}}} // 指令段：按 op 名计数
 * ```
 *
 * `p` 是**结构路径**（子序号用 `.` 连），不是数组下标 —— 加一个兄弟节点时
 * 后面所有下标都错位，路径不会。diff 按 `p` 配对，所以"多了个节点"与
 * "某个节点的几何变了"能分开报。
 *
 * ## 成本（实测 TablePage 900×640）
 *
 * | 段 | 耗时 | 量 |
 * |---|---|---|
 * | 元素树 | 2.7ms | 559 节点 |
 * | 盒树 | 0.3ms | 559 节点 |
 * | 语义树 | 3.1ms | 4 节点 |
 * | 绘制指令 | 0.2ms | 2317 条 |
 * | **合计** | **6.3ms** | JSON 61KB（渲染本身 97.7ms ⇒ 开销 6%） |
 *
 * ⇒ 便宜到可以进闸门，也便宜到可以"失败时顺手再导一份"。
 *
 * ## 边界（诚实声明）
 *
 * - **不替代像素**：抗锯齿、字形光栅、混合模式只有像素能验 ⇒ golden 继续存在。
 * - **语义树很稀疏**（实测 4–13 节点 vs 26–559 元素）：它是**无障碍**的观察面，
 *   不是通用元素定位面。要按名字定位控件得用 `TestApp` 的选择器（`#key` 或类名）。
 * - **指令段只记 op 名与条数**，不记参数：参数（矩形、颜色）体积大且噪声高，
 *   真要参数时直接读 `DisplayList::argAt()`（`opAt`/`argAt` 已在）。
 */
final class Snapshot
{
    /** 快照格式版本（字段增删要 +1，否则旧基线会被静默误读） */
    public const SCHEMA = 1;

    /**
     * 渲染一个页面并取回快照。
     *
     * ★ 走的是**真管线**（`Frame::run`：settle → layout → paint），不是手抄副本 ——
     *   手抄副本会漏掉"跳过重排"这类判据（MEMORY：量具复刻管线只复刻顺序不复刻判据）。
     */
    public static function ofPage(Widget $page, int $width, int $height): array
    {
        $surface = new RecordingSurface();
        $context = new BuildContext(
            (float) $width,
            (float) $height,
            ThemeData::light(),
            new DeterministicMetricsProvider(
                DeterministicFontManager::standard()->collection(),
                new DeterministicShaper(),
            ),
        );

        $element = $page->createElement();
        $element->mount(null, $context);
        (new Frame($surface))->run(
            $element,
            BoxConstraints::tight(new Size((float) $width, (float) $height)),
            0.0,
        );

        return self::ofElement($element, $surface->last, $width, $height);
    }

    /**
     * 从**已挂载并排过版**的根元素取快照（夹具里已有树时用这个，省一次渲染）。
     *
     * @param RecordingCanvas|null $canvas 绘制段来源；`null` ⇒ 绘制段为空（不报错）
     * @return array<string, mixed>
     */
    public static function ofElement(Element $root, ?RecordingCanvas $canvas, int $width, int $height): array
    {
        $elements = [];
        self::walkElement($root, '0', 0, $elements);

        $boxes = [];
        self::walkBox($root->effectiveBox(), '0', $boxes);

        return [
            'schema' => self::SCHEMA,
            'size' => ['w' => $width, 'h' => $height],
            'trees' => ['elements' => $elements, 'boxes' => $boxes],
            'semantics' => SemanticsBuilder::build($root, null)->toArrays(),
            'paint' => self::paintSection($canvas),
        ];
    }

    /**
     * 人类可读的树（`--tree`）。
     *
     * ★ 用 `absoluteOrigin()` 而不是自己沿父链累加：MEMORY 记着"手抄父链累加都是 bug"。
     * ★ 每行 = `缩进 类名 @(x,y w×h)`；盒类与元素类不同时补一个 `→ 盒类`
     *   （`StatelessWidget` 这类不产盒的节点靠它看出"几何其实来自谁"）。
     *
     * @param array<string, mixed> $snapshot
     */
    public static function toTree(array $snapshot, int $maxDepth = 99): string
    {
        $trees = $snapshot['trees'] ?? [];
        $elements = is_array($trees) ? ($trees['elements'] ?? []) : [];
        if (!is_array($elements) || $elements === []) {
            return "（空树）\n";
        }

        $lines = [];
        foreach ($elements as $node) {
            if (!is_array($node)) {
                continue;
            }
            $depth = (int) ($node['d'] ?? 0);
            if ($depth > $maxDepth) {
                continue;
            }
            $rect = $node['r'] ?? [null, null, null, null];
            $r = is_array($rect) ? $rect : [null, null, null, null];
            $widget = (string) ($node['w'] ?? '?');
            $box = (string) ($node['b'] ?? '');
            $key = $node['key'] ?? null;
            $geom = ($r[0] === null)
                ? '(无盒)'
                : sprintf('@(%.1f,%.1f %.1f×%.1f)', (float) $r[0], (float) $r[1], (float) $r[2], (float) $r[3]);

            $lines[] = str_repeat('  ', $depth) . $widget
                . ($box !== '' && $box !== $widget ? ' → ' . $box : '')
                . ($key !== null ? ' #' . $key : '')
                . ' ' . $geom;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * 两份快照的差异（按结构路径配对）。
     *
     * ★ 返回**人类可读的行**而不是布尔：快照的用途就是"说清哪里不一样"。
     * ★ 只比"会因 bug 而变"的字段：几何、结构、语义属性、指令条数。
     *   不比 JSON 的键顺序、不比浮点的字符串形（那是 `===` 的事，不是 diff 的事）。
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array{lines: list<string>, total: int} lines 已按 $limit 截断，total 是真实条数
     */
    public static function diff(array $before, array $after, int $limit = 20): array
    {
        $lines = [];

        $sb = $before['size'] ?? [];
        $sa = $after['size'] ?? [];
        if ($sb !== $sa) {
            $lines[] = '尺寸 ' . self::rectText($sb) . ' ⇒ ' . self::rectText($sa);
        }

        $eb = self::indexByPath($before['trees']['elements'] ?? []);
        $ea = self::indexByPath($after['trees']['elements'] ?? []);
        foreach ($eb as $path => $node) {
            if (!isset($ea[$path])) {
                $lines[] = "元素 -{$path} {$node['w']}（消失）";
            }
        }
        foreach ($ea as $path => $node) {
            if (!isset($eb[$path])) {
                $lines[] = "元素 +{$path} {$node['w']}（新增）";
            }
        }
        foreach ($ea as $path => $node) {
            $old = $eb[$path] ?? null;
            if ($old === null) {
                continue;
            }
            if (($old['w'] ?? '') !== ($node['w'] ?? '')) {
                $lines[] = "元素 {$path} 类 {$old['w']} ⇒ {$node['w']}";
            }
            // ★ 比**格式化后的文本**而不是原始数组：JSON 往返会把 `0.0` 变成 `0`
            //   （PHP 的 serialize_precision 会省掉 `.0`）⇒ 严格比数组时"同一份数据"
            //   会报 929 条假差异（实测踩过）。文本比法对两侧来源都不敏感。
            if (self::rectText($old['r'] ?? []) !== self::rectText($node['r'] ?? [])) {
                $lines[] = "元素 {$path} {$node['w']} 矩形 " . self::rectText($old['r'] ?? [])
                    . ' ⇒ ' . self::rectText($node['r'] ?? []);
            }
        }

        $bb = self::indexByPath($before['trees']['boxes'] ?? []);
        $ba = self::indexByPath($after['trees']['boxes'] ?? []);
        foreach ($ba as $path => $node) {
            $old = $bb[$path] ?? null;
            if ($old === null) {
                $lines[] = "盒 +{$path} {$node['c']}（新增）";
                continue;
            }
            if (($old['c'] ?? '') !== ($node['c'] ?? '')) {
                $lines[] = "盒 {$path} 类 {$old['c']} ⇒ {$node['c']}";
            }
            if (self::sizeText($old) !== self::sizeText($node)) {
                $lines[] = "盒 {$path} {$node['c']} 尺寸 " . self::sizeText($old) . ' ⇒ ' . self::sizeText($node);
            }
        }
        foreach ($bb as $path => $node) {
            if (!isset($ba[$path])) {
                $lines[] = "盒 -{$path} {$node['c']}（消失）";
            }
        }

        $semB = $before['semantics']['nodes'] ?? [];
        $semA = $after['semantics']['nodes'] ?? [];
        $nb = is_array($semB) ? count($semB) : 0;
        $na = is_array($semA) ? count($semA) : 0;
        if ($nb !== $na) {
            $lines[] = "语义节点 {$nb} ⇒ {$na}";
        } else {
            for ($i = 0; $i < $na; $i++) {
                $x = $semB[$i] ?? [];
                $y = $semA[$i] ?? [];
                if (!is_array($x) || !is_array($y)) {
                    continue;
                }
                foreach (['role', 'name', 'value', 'x', 'y', 'w', 'h'] as $field) {
                    if (($x[$field] ?? null) !== ($y[$field] ?? null)) {
                        $lines[] = "语义 [{$i}] {$field} "
                            . self::scalar($x[$field] ?? null) . ' ⇒ ' . self::scalar($y[$field] ?? null);
                    }
                }
            }
        }

        $pb = $before['paint']['histogram'] ?? [];
        $pa = $after['paint']['histogram'] ?? [];
        $keys = [];
        if (is_array($pb)) {
            $keys = array_keys($pb);
        }
        if (is_array($pa)) {
            $keys = array_merge($keys, array_keys($pa));
        }
        $keys = array_values(array_unique($keys));
        sort($keys);
        foreach ($keys as $op) {
            $cb = is_array($pb) ? (int) ($pb[$op] ?? 0) : 0;
            $ca = is_array($pa) ? (int) ($pa[$op] ?? 0) : 0;
            if ($cb !== $ca) {
                $lines[] = "绘制 {$op} {$cb} ⇒ {$ca}";
            }
        }

        return ['lines' => array_slice($lines, 0, $limit), 'total' => count($lines)];
    }

    // ───────────────────────────── 内部 ─────────────────────────────

    /**
     * 元素树 DFS（文档顺序）。
     *
     * @param list<array<string, mixed>> $out
     */
    private static function walkElement(Element $el, string $path, int $depth, array &$out): void
    {
        $box = $el->effectiveBox();
        $rect = [null, null, null, null];
        $boxClass = '';
        if ($box !== null) {
            $boxClass = self::shortName($box);
            [$ax, $ay] = RenderBox::absoluteOrigin($box);
            $size = $box->size();
            $rect = [$ax, $ay, $size?->width, $size?->height];
        }

        // ★ `key`：控件跨位置稳定的身份（Widget::identity() ⇒ ?Key）。是"节点↔坐标"
        //   映射的基石——S4（点元素高亮）与 P1'（真机按 #key 点坐标）共用同一份快照。
        //   纯增量字段：旧基线无它、diff 也不比它 ⇒ 零破坏、零误报。
        $idKey = $el->widget()->identity();
        $keyVal = $idKey === null ? null : $idKey->value;

        $out[] = [
            'p' => $path,
            'd' => $depth,
            'w' => self::shortName($el->widget()),
            'b' => $boxClass,
            'r' => $rect,
            'key' => $keyVal,
        ];

        $kids = [];
        $el->visitChildren(static function (Element $child) use (&$kids): void {
            $kids[] = $child;
        });
        foreach ($kids as $i => $child) {
            self::walkElement($child, $path . '.' . $i, $depth + 1, $out);
        }
    }

    /**
     * 盒树 DFS（= 绘制顺序）。
     *
     * @param list<array<string, mixed>> $out
     */
    private static function walkBox(?RenderBox $root, string $path, array &$out): void
    {
        if ($root === null) {
            return;
        }

        /** @var list<array{RenderObject, string}> $stack */
        $stack = [[$root, $path]];
        while ($stack !== []) {
            [$node, $nodePath] = array_pop($stack);
            if (!$node instanceof RenderBox) {
                continue;
            }
            $size = $node->size();
            $out[] = [
                'p' => $nodePath,
                'c' => self::shortName($node),
                'w' => $size?->width,
                'h' => $size?->height,
            ];

            $kids = [];
            $node->visitChildren(static function (RenderObject $child) use (&$kids): void {
                $kids[] = $child;
            });
            // 逆序入栈 ⇒ 弹出顺序 = 文档顺序（确定性是比对的前提）
            for ($i = count($kids) - 1; $i >= 0; $i--) {
                $stack[] = [$kids[$i], $nodePath . '.' . $i];
            }
        }
    }

    /**
     * 绘制段：op 条数直方图。
     *
     * ★ op 名从 `DisplayList` 的常量表反射出来 —— 新增 op 时这里**自动**跟上，
     *   不用手工维护一张会腐烂的映射表。
     *
     * @return array{ops: int, histogram: array<string, int>}
     */
    private static function paintSection(?RecordingCanvas $canvas): array
    {
        $list = $canvas?->displayList();
        if (!$list instanceof DisplayList) {
            return ['ops' => 0, 'histogram' => []];
        }

        $names = self::opNames();
        $histogram = [];
        $count = $list->count();
        for ($i = 0; $i < $count; $i++) {
            $op = $list->opAt($i);
            $key = $names[$op] ?? ('OP_' . $op);
            $histogram[$key] = ($histogram[$key] ?? 0) + 1;
        }
        ksort($histogram);

        return ['ops' => $count, 'histogram' => $histogram];
    }

    /** @return array<int, string> opcode ⇒ 短名（`OP_DRAW_RECT` ⇒ `DRAW_RECT`） */
    private static function opNames(): array
    {
        $names = [];
        foreach ((new ReflectionClass(DisplayList::class))->getConstants() as $name => $value) {
            if (is_int($value) && str_starts_with((string) $name, 'OP_')) {
                $names[$value] = substr((string) $name, 3);
            }
        }

        return $names;
    }

    /**
     * @param mixed $nodes
     * @return array<string, array<string, mixed>>
     */
    private static function indexByPath($nodes): array
    {
        $out = [];
        if (!is_array($nodes)) {
            return $out;
        }
        foreach ($nodes as $node) {
            if (is_array($node) && isset($node['p'])) {
                $out[(string) $node['p']] = $node;
            }
        }

        return $out;
    }

    private static function shortName(object $o): string
    {
        $class = get_class($o);
        $pos = strrpos($class, '\\');

        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /** @param mixed $rect */
    private static function rectText($rect): string
    {
        if (!is_array($rect) || count($rect) < 4) {
            return '(无)';
        }
        if ($rect[0] === null) {
            return '(无盒)';
        }

        return sprintf('(%.1f,%.1f %.1f×%.1f)', (float) $rect[0], (float) $rect[1], (float) $rect[2], (float) $rect[3]);
    }

    /** @param array<string, mixed> $node */
    private static function sizeText(array $node): string
    {
        $w = $node['w'] ?? null;
        $h = $node['h'] ?? null;
        if ($w === null || $h === null) {
            return '(未排)';
        }

        return sprintf('%.1f×%.1f', (float) $w, (float) $h);
    }

    /** @param mixed $v */
    private static function scalar($v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }

        return (string) $v;
    }
}
