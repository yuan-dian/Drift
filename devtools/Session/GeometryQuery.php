<?php
declare(strict_types=1);

namespace Drift\DevTools\Session;

use Drift\Debug\Session\GeometryRecord;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Size;

/**
 * 几何域查询：盒子在哪、多大、约束怎么传下来的。
 *
 * 只读 {@see Recorder} 的几何窗口（`geometry` / `geometryBaseline` / `goneAt`），
 * 不碰活盒、不新采集 —— 所以导出的证据在另一个进程里也能问同一个问题。
 *
 * ## 为什么单独一类
 *
 * 这个域有三个**只对它成立**的口径，混在别处时最容易漂的就是它们：
 *
 * 1. **窗口只有 60 帧**（帧窗口 600），且按"有变化才落记录"跳帧 —— 缺省帧必须落在
 *    自己的覆盖范围里，否则静止几十秒后"打开面板一片空白"而每个环节都自认为正常。
 * 2. **往前找最近一次**只对窗口内成立。窗口外不是"没有这个盒"，是"这一帧没采"。
 * 3. **三种查不到必须分开**：复合控件没有自己的 RenderBox 是**永久事实**，窗口外是
 *    **暂时事实**，地址错了是**地址错了**。混成一句"没找到"，读者会一直等一条永远
 *    不会出现的记录。
 *
 * `lookup()` 是这三条的唯一入口：判帧在不在窗口 → 查记录 → 分类 miss。四个查询
 * （`boxModel` / `boxes` / `constraints` / `Query::align`）共用它。早先只有 `align`
 * 判了窗口，另外三个把窗口末端的旧几何当成目标帧的，于是"查一个根本没采样过的帧"
 * 返回 `found=true` —— 这类答案自洽、可读、全错。
 *
 * @internal 门面 {@see Query} 的实现细节，不是应用开发者的 API 面
 */
final class GeometryQuery
{
    public function __construct(private readonly History $history)
    {
    }

    /**
     * 一帧里所有盒的几何摘要，按 node id 升序。
     *
     * 这是 `boxModel` 的"整帧版"，只读同一份几何记录，不碰活盒、不新采集。
     * 它给投影层的是**原料**而不是结论：父子约束怎么比、什么算异常，由投影层
     * 按规则决定。查询层一旦自己判"这里不对"，证据与意见就长在同一处了。
     *
     * `window` 随答案一起给：几何窗口只有 60 帧（帧窗口是 600），查不到时
     * 必须说得清是"窗口外"还是"没有这个盒"。
     *
     * @return array{frame: int, rows: list<array<string, mixed>>, window: array<string, mixed>}
     */
    public function boxes(?int $frame = null): array
    {
        // 缺省帧取**几何窗口的最新**，不是帧窗口的最新。
        //
        // ★ 两个窗口宽度不同（几何 60、帧 600），且几何按"有变化才落记录"跳帧 ——
        //   用 `lastFrameId()` 当缺省，静止几十秒后它必然跑出几何覆盖范围，
        //   于是"打开面板一片空白"而每个环节都自认为正常。缺省值必须落在
        //   它自己要查的那个窗口里。
        $window = $this->history->geometryCoverage();
        $frameId = $frame ?? (int) ($window['to'] ?? 0);
        if ($frameId < (int) ($window['from'] ?? 0) || $frameId > (int) ($window['to'] ?? 0)) {
            return [
                'frame' => $frameId,
                'rows' => [],
                'window' => $window,
                'reason' => 'frame-out-of-window',
            ];
        }

        $records = $this->history->geometryRows($frameId);
        \ksort($records, \SORT_NUMERIC);

        $rows = [];
        // 几何按变化存：目标帧没重新采样就往前找。整张表的"实际生效帧"必须回显，
        // 否则读者会把 `rows` 当成 `frameId` 那一刻的快照 —— 那正是"三域看起来
        // 都对齐、实际几何是上一帧"的来源。
        $effective = 0;
        foreach ($records as $node => $record) {
            $rows[] = [
                'node' => $node,
                'parent' => $record->parent,
                'type' => $this->history->typeOf($node),
                'applied' => $record->applied,
                'inner' => $record->inner,
                'size' => $record->size,
                'box' => $record->box,
                // 相对父**内容盒原点**的偏移与内边距。留原样是为了让"为什么这个盒
                // 在这儿"能被复算；绝对矩形见下面的 `rect`。
                'offset' => $record->offset,
                'pad' => $record->pad,
            ];
            $effective = \max($effective, $record->frame);
        }

        // 容器 = 本帧有子盒的节点。页面据此把"框套框"的辅助线淡画，但选中/悬停的
        // 祖先链要保持清晰 —— 父链由这里给，页面不自己累加 parent 指针（那会成第四份真相）。
        $parents = [];
        foreach ($rows as $row) {
            $parents[(int) ($row['parent'] ?? 0)] = true;
        }
        $byNode = [];
        foreach ($rows as $row) {
            $byNode[(int) $row['node']] = $row;
        }
        $chainOf = static function (int $node) use ($byNode): array {
            $chain = [];
            $seen = [$node => true];
            $parent = (int) ($byNode[$node]['parent'] ?? 0);
            while ($parent > 0 && isset($byNode[$parent]) && !isset($seen[$parent])) {
                $chain[] = $parent;
                $seen[$parent] = true;
                $parent = (int) ($byNode[$parent]['parent'] ?? 0);
            }

            return $chain;
        };
        foreach ($rows as $i => $row) {
            $node = (int) $row['node'];
            $rows[$i]['container'] = isset($parents[$node]);
            $rows[$i]['chain'] = $chainOf($node);
        }

        // 绝对矩形只在这里算一次。
        //
        // 让每个消费者自己从 `offset` 累加，等于把一次树的先序遍历发给每个人抄一遍，
        // 而抄错的表现是**盒子错位**——没有读者会先怀疑自己的遍历。这不是判断（"这里
        // 不对"），是坐标变换，所以归查询层；口径与 `Query::pick()` 的 `hitTest` 一致：
        // 子盒原点是相对父内容盒原点的。
        $abs = self::absoluteRects($rows);
        foreach ($rows as $i => $row) {
            $rect = $abs[(int) $row['node']] ?? ['x' => 0.0, 'y' => 0.0];
            $rows[$i]['rect'] = [
                'x' => $rect['x'],
                'y' => $rect['y'],
                'w' => (float) ($row['size']['w'] ?? 0.0),
                'h' => (float) ($row['size']['h'] ?? 0.0),
            ];
        }

        return [
            'frame' => $frameId,
            'effective' => $effective,
            'stale' => $rows !== [] && $effective !== $frameId,
            'rows' => $rows,
            'window' => $window,
        ];
    }

    /**
     * 把"相对父内容盒原点的偏移"累加成视口坐标（border 盒左上角）。
     *
     * 记录里刻意不存绝对坐标：绝对坐标会"因为祖先挪了一像素"而整片失效，而几何是
     * 按签名变化存的 —— 存相对偏移时，一个盒的位置变只影响它自己这一条。
     *
     * 父不在记录里（根，或父本帧没采样）时该节点自成原点；成环时打断并给 0：
     * 环意味着记录层坏了，视图不该陪着一起死。
     *
     * @param list<array<string, mixed>> $rows
     * @return array<int, array{x: float, y: float}>
     */
    private static function absoluteRects(array $rows): array
    {
        $byNode = [];
        foreach ($rows as $row) {
            $byNode[(int) $row['node']] = $row;
        }

        $abs = [];
        $resolve = function (int $node, array $seen) use (&$resolve, &$abs, $byNode): array {
            if (isset($abs[$node])) {
                return $abs[$node];
            }

            $row = $byNode[$node] ?? null;
            if ($row === null || isset($seen[$node])) {
                return $abs[$node] = ['x' => 0.0, 'y' => 0.0];
            }
            $seen[$node] = true;

            $x = (float) ($row['offset']['x'] ?? 0.0);
            $y = (float) ($row['offset']['y'] ?? 0.0);
            $parent = (int) $row['parent'];
            if ($parent === $node || !isset($byNode[$parent])) {
                return $abs[$node] = ['x' => $x, 'y' => $y];
            }

            $origin = $resolve($parent, $seen);

            return $abs[$node] = [
                'x' => $origin['x'] + (float) ($byNode[$parent]['pad']['l'] ?? 0.0) + $x,
                'y' => $origin['y'] + (float) ($byNode[$parent]['pad']['t'] ?? 0.0) + $y,
            ];
        };

        foreach ($byNode as $node => $row) {
            $resolve($node, []);
        }

        return $abs;
    }

    /**
     * 一个节点的盒模型：margin / border / padding / content 四层 + 位置。
     *
     * 全部来自帧边界的几何快照，不碰活盒——所以导出的证据在另一个进程里也能问
     * 同一个问题。找不到时说清是"没这个节点"还是"几何窗口外"，不返回默认零值：
     * 默认零值会让一个不存在的盒子看起来像"尺寸正常"。
     *
     * @return array<string, mixed>
     */
    public function boxModel(int $node, ?int $frame = null): array
    {
        $frameId = $frame ?? $this->history->lastFrameId();
        $hit = $this->lookup($node, $frameId);
        if ($hit['record'] === null) {
            return $hit['reason'] === 'frame-out-of-window'
                ? $this->outOfWindow($node, $frameId, $hit['window'])
                : $this->miss($node, $frameId, $hit['window']);
        }

        return ['found' => true] + $hit['record']->toArray() + ['window' => $hit['window']];
    }

    /**
     * 一个节点收到的约束是从哪几跳传下来的。
     *
     * 每一跳查两件事：
     * ① 父的 `applied`（tighten 后）经 padding 内缩与 align 放松，是否得到父的 `inner`；
     * ② 父的 `inner` 经子的宽高修饰收紧，是否得到子记录的 `applied`。
     * 两者都对 ⇒ `base`（基类默认链）；①错 ⇒ `parent-performLayout`（父在
     * performLayout 里另算了 inner）；②错且"父 inner 无界 + 子带 flex" ⇒
     * `parent-flex`（Flex 父按 grow/shrink 给子分配尺寸，最典型的是 `Column` 里的
     * `Expanded`）；②错且不满足那个组合 ⇒ `child-custom`（aspect 等修饰，或中间
     * 还有别人改过）。**对不上不是错误，是答案**：能归默认的归默认，不能的说出不能，
     * 绝不猜一个来源填进去——那是"看起来很有道理"的证据，比没有更危险。
     *
     * 链是**复算**出来的（读快照 + 重跑纯函数），并与快照里实际记的值交叉验证；
     * 两次复算逐值相同即为判据，不需要在 layout 热路径插任何钩子。
     *
     * @return array<string, mixed>
     */
    public function constraints(int $node, ?int $frame = null, int $maxDepth = 12): array
    {
        $frameId = $frame ?? $this->history->lastFrameId();
        $hit = $this->lookup($node, $frameId);
        if ($hit['record'] === null) {
            return $hit['reason'] === 'frame-out-of-window'
                ? $this->outOfWindow($node, $frameId, $hit['window'])
                : $this->miss($node, $frameId, $hit['window']);
        }

        $window = $hit['window'];
        $all = [];
        $cursor = $hit['record'];
        // 环保护：父链来自记录，记录一旦坏掉（父指向自己/互指）这里会无限循环。
        // 与 `pathOf()` 的 `$guard` 同一条纪律：报告不该陪着记录一起死。
        $guard = 0;
        while ($cursor !== null && $cursor->parent > 0 && $guard++ < 64) {
            $parent = $this->history->geometryAt($frameId, $cursor->parent);
            if ($parent === null) {
                $all[] = [
                    'from' => $cursor->parent,
                    'to' => $cursor->node,
                    'verified' => false,
                    'source' => 'parent-outside-window',
                    'received' => $cursor->applied,
                ];
                break;
            }

            $expectedInner = GeometryRecord::unpack($parent->applied);
            $pad = $parent->pad;
            if ($pad['l'] != 0.0 || $pad['t'] != 0.0 || $pad['r'] != 0.0 || $pad['b'] != 0.0) {
                $expectedInner = $expectedInner->deflate(
                    new EdgeInsets($pad['l'], $pad['t'], $pad['r'], $pad['b']),
                );
            }
            if ($parent->align !== null) {
                $expectedInner = $expectedInner->loosen();
            }
            $innerOk = GeometryRecord::pack($expectedInner) === $parent->inner;

            $expectedApplied = GeometryRecord::unpack($parent->inner);
            if ($cursor->box !== null || $cursor->aspect !== null) {
                $size = new Size(
                    $cursor->box['w'] ?? $expectedApplied->maxWidth,
                    $cursor->box['h'] ?? $expectedApplied->maxHeight,
                );
                $expectedApplied = $expectedApplied->tighten($size);
            }
            $appliedOk = GeometryRecord::pack($expectedApplied) === $cursor->applied;

            // ---- 第四类：Flex 父在 performLayout 里按 grow/shrink 分配尺寸。
            //
            // 判据是两个**结构事实**，不猜：
            // ① 父是 Flex 容器（`Column` / `Row` / 任何 `extends Flex`，由记录层
            //    在登记节点时按 `instanceof` 判定，不是类名清单）；
            // ② 子自己**没有**宽高修饰（`box` / `aspect` 都为 null）。
            // 子没改约束却拿不到父的 inner ⇒ 只能是父在 performLayout 里重新分的。
            //
            // 有 box/aspect 的子不算：那是子侧改的，即使父是 Flex 也归 `child-custom`
            // —— 归因要指向真正动手的那一方。
            $childTweaked = $cursor->box !== null || $cursor->aspect !== null;
            $parentFlex = $this->history->isFlex($parent->node);

            $all[] = [
                'from' => $parent->node,
                'fromType' => $this->history->typeOf($parent->node),
                'to' => $cursor->node,
                'toType' => $this->history->typeOf($cursor->node),
                'received' => $cursor->applied,
                'expected' => GeometryRecord::pack($expectedApplied),
                'parentInner' => $parent->inner,
                'verified' => $innerOk && $appliedOk,
                'source' => !$innerOk
                    ? 'parent-performLayout'
                    : ($appliedOk
                        ? 'base'
                        : (($parentFlex && !$childTweaked) ? 'parent-flex' : 'child-custom')),
            ];
            $cursor = $parent;
        }

        $total = \count($all);
        $chain = $maxDepth > 0 ? \array_slice($all, 0, $maxDepth) : $all;
        $verified = 0;
        foreach ($chain as $hop) {
            if ($hop['verified']) {
                ++$verified;
            }
        }

        return [
            'found' => true,
            'frame' => $frameId,
            'node' => $node,
            'type' => $this->history->typeOf($node),
            'hops' => $total,
            'chain' => $chain,
            'verified' => $verified,
            'unverified' => \count($chain) - $verified,
            'cut' => $total > \count($chain),
            'omitted' => \max(0, $total - \count($chain)),
            'window' => $window,
        ];
    }

    /**
     * 几何查证的**唯一入口**：先判帧在不在窗口内，再查记录。
     *
     * ★ 为什么必须收成一处：`Recorder::geometryAt()` 的"往前找最近一次"只对
     *   **窗口内**成立。四个查询各判一遍窗口，实际上只会修到其中几个 —— 早先只有
     *   `Query::align()` 判了，`boxModel` / `boxes` / `constraints` 照样把窗口末端的
     *   旧几何当成目标帧的，于是"查一个根本没采样过的帧"返回 `found=true`。这类
     *   答案自洽、可读、全错。
     *
     * @param array<string, mixed>|null $window 已知窗口时传进来，省一次遍历
     * @return array{record: ?GeometryRecord, window: array<string, mixed>, reason: ?string}
     *   `reason` 非 null = 帧不在窗口，"连有没有这个盒"都回答不了。
     */
    public function lookup(int $node, int $frame, ?array $window = null): array
    {
        $window ??= $this->history->geometryCoverage();
        $from = (int) ($window['from'] ?? 0);
        $to = (int) ($window['to'] ?? 0);
        if ($frame < $from || $frame > $to) {
            return ['record' => null, 'window' => $window, 'reason' => 'frame-out-of-window'];
        }

        return ['record' => $this->history->geometryAt($frame, $node), 'window' => $window, 'reason' => null];
    }

    /**
     * 帧号在窗口外：与"窗口内但没有这个盒"是两件事，不能混。
     *
     * @param array<string, mixed> $window
     * @return array<string, mixed>
     */
    public function outOfWindow(int $node, int $frame, array $window): array
    {
        return [
            'found' => false,
            'node' => $node,
            'frame' => $frame,
            'reason' => 'frame-out-of-window',
            'window' => $window,
        ];
    }

    /**
     * 几何查不到的三种原因必须分开说。
     *
     * 复合控件没有自己的 RenderBox，那是**永久事实**；窗口外是**暂时事实**（换个帧号
     * 可能就有）；节点不存在是**地址错了**。三者混成一句"没找到"，读者会把第一种
     * 当成第二种，一直等一条永远不会出现的记录。
     *
     * @param array<string, mixed> $window
     * @return array<string, mixed>
     */
    public function miss(int $node, int $frame, array $window): array
    {
        $boxless = $this->history->isBoxless($node);
        $out = [
            'found' => false,
            'node' => $node,
            'frame' => $frame,
            'reason' => $boxless
                ? 'no-render-box'
                : match ($this->history->nodeStatus($node)) {
                    'evicted' => 'node-evicted',
                    'unknown' => 'unknown-node',
                    default => 'outside-geometry-window',
                },
            'window' => $window,
        ];

        if ($boxless) {
            $child = $this->history->firstBoxedChild($node);
            $out['suggest'] = $child === null
                ? '这个节点是复合节点，没有自己的盒；往下找有盒的后代'
                : \sprintf('查有盒的子节点 %d（%s）', $child, $this->history->typeOf($child));
        }

        return $out;
    }
}
