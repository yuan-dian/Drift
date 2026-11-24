<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Size;
use Drift\Widget\Element;

/**
 * 查询面：把录制记录读成"能回答问题的形状"。
 *
 * 纯函数：只读 {@see Recorder} 的不可变帧记录，不改状态、不碰元素树。
 * 所以可以重试、可以缓存、可以在任意线程问卷（记录本身在写时拷贝）。
 *
 * ★ 唯一例外是 {@see self::props()}：属性只在 dev 形态可反射，且读的是**当前活树**
 *   （每帧采样必掉帧）。它把结果存进 Recorder 的独立快照窗口，所以查询本身
 *   有副作用。其余查询仍不改任何状态，这条例外不外溢。
 *
 * ## 两个聚合的口径不同，不要合并
 *
 * - `hotspots()` 回答"谁在写"：只聚合 `kind=state` 的根因边，按调用点 + State 类排名。
 * - `nodes()` 回答"谁在重建"：按节点聚合，含父传播。
 *
 * 一个 `setState` 会让整棵子树逐层产生 `parent` 边。把传播算进根因，排名会全是
 * 下游节点，真正的原因反而沉底。反过来只看根因，又看不出"哪个子树被牵连最重"。
 *
 * @api
 */
final class Query
{
    /** @var array<int, array<string, mixed>> 帧号 => 该帧的森林骨架（复用于 cascade / 聚合） */
    private array $shapeCache = [];

    private readonly PropReader $reader;

    public function __construct(private readonly Recorder $recorder)
    {
        $this->reader = new PropReader();
    }

    /** @return array<string, mixed> 帧数 / 节点数 / 容量 / 最近帧耗时与重建数 */
    public function stats(): array
    {
        return $this->recorder->stats();
    }

    /**
     * 按节点读 Widget / State 配置（dev 反射，活树按需快照）。
     *
     * ★ 帧号不是参数：属性读的是**现在**的活树，给一个历史帧号却返回当前值
     *   就是"伪称对齐"。返回值里的 `liveFrame` 明说这是哪一帧读到的。
     *
     * @param list<Element> $roots 全部树根（主树 + 浮层），由派发层给 —— Debug 层不依赖 App
     * @return array<string, mixed>
     */
    public function props(int $node, array $roots, int $maxDepth = 3, int $maxFields = 120): array
    {
        if (!$this->reader->available()) {
            return [
                'found' => false,
                'node' => $node,
                'reason' => 'reflection-unavailable',
                'window' => $this->recorder->propsCoverage(),
            ];
        }

        $element = $this->findElement($roots, $node);
        if ($element === null) {
            // "号没发过"与"号发过但节点已卸载"必须分开：前者是地址写错，
            // 后者是活树里已经没有它（快照窗口里仍可能有当时的读数）。
            $known = $this->recorder->nodeIndex()[$node] ?? null;

            return [
                'found' => false,
                'node' => $node,
                'reason' => $known === null ? 'unknown-node' : 'node-unmounted',
                'type' => $known['c'] ?? null,
                'window' => $this->recorder->propsCoverage(),
            ];
        }

        $frame = $this->recorder->lastFrameId();
        $snapshot = $this->reader->read(
            $element,
            $node,
            $frame,
            $maxDepth,
            $maxFields,
            $this->recorder->redactionEnabled(),
        );
        $this->recorder->rememberProps($snapshot);

        return [
            'found' => true,
            'stored' => true,
            'liveFrame' => $frame,
        ] + $snapshot->toArray() + ['window' => $this->recorder->propsCoverage()];
    }

    /** @param list<Element> $roots */
    private function findElement(array $roots, int $node): ?Element
    {
        foreach ($roots as $root) {
            if (!$root instanceof Element) {
                continue;
            }
            if ($this->recorder->owns($root) && $root->debugId === $node) {
                return $root;
            }

            $found = null;
            $root->visitChildren(function (Element $child) use (&$found, $node): void {
                if ($found !== null) {
                    return;
                }
                if ($this->recorder->owns($child) && $child->debugId === $node) {
                    $found = $child;
                }
            });
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * 最近 N 帧的摘要，按帧号升序。
     *
     * `since` 是**增量游标**：只要帧号大于它的帧。游标由调用方持有（记最后一条的
     * `id`），服务端不记"你看到哪了" —— 无状态的读可以重试、可以并行、可以
     * 两个消费者各拿一份互不干扰。这也正是"订阅"给不了的东西：订阅一旦漏一条，
     * 之后每一条都是错的，而游标只是"再问一次"。
     *
     * @return list<array<string, mixed>>
     */
    public function frames(int $limit = 30, bool $rebuiltOnly = false, ?int $since = null): array
    {
        if ($since !== null) {
            $out = [];
            foreach ($this->recorder->frames() as $id => $frame) {
                if ($id <= $since) {
                    continue;
                }
                if ($rebuiltOnly && $frame->rebuilt === []) {
                    continue;
                }
                $out[] = $this->frameSummary($frame);
            }

            // 游标语义下 `limit` 是"最多给这么多"，从**最老的未读**开始：
            // 丢弃最新的会让读者以为"就这些"，而最老的丢不掉才是真相。
            return $limit > 0 ? \array_slice($out, 0, $limit) : $out;
        }

        $window = $this->window($limit);
        $out = [];
        foreach ($window as $frame) {
            if ($rebuiltOnly && $frame->rebuilt === []) {
                continue;
            }
            $out[] = $this->frameSummary($frame);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function frameSummary(FrameRecord $frame): array
    {
        return [
            'id' => $frame->id,
            't' => $frame->t,
            'cost' => $frame->costMs,
            'rebuilt' => \count($frame->rebuilt),
            'causes' => \count($frame->causes),
            'stageMs' => FrameRecord::toMs($frame->stageNs),
            'unattributedMs' => $frame->unattributedMs(),
            'settleSkipped' => $frame->settleSkipped,
            'paintSkipped' => $frame->paintSkipped,
        ];
    }

    /**
     * 单帧展开：谁重建了、根因怎么分布。
     *
     * @return array<string, mixed>
     */
    public function explain(int $frameId): array
    {
        $frame = $this->recorder->frame($frameId);
        if ($frame === null) {
            throw new \InvalidArgumentException("没有这一帧：{$frameId}（缓冲窗口之外或尚未发生）");
        }

        $rebuilt = [];
        foreach ($frame->rebuilt as $id) {
            $rebuilt[] = ['id' => $id, 'path' => $this->recorder->pathOf($id)];
        }

        $byKind = ['state' => 0, 'parent' => 0, 'unknown' => 0];
        $byRoot = [];
        foreach ($frame->causes as $edge) {
            if (isset($byKind[$edge->kind])) {
                ++$byKind[$edge->kind];
            }
            if ($edge->kind !== 'state') {
                continue;
            }
            $key = $edge->site . "\0" . $edge->detail;
            $byRoot[$key] ??= ['site' => $edge->site, 'detail' => $edge->detail, 'count' => 0];
            ++$byRoot[$key]['count'];
        }

        $roots = \array_values($byRoot);
        \usort($roots, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

        return [
            'id' => $frame->id,
            't' => $frame->t,
            'cost' => $frame->costMs,
            'stages' => $frame->stages,
            'stageMs' => FrameRecord::toMs($frame->stageNs),
            'unattributedMs' => $frame->unattributedMs(),
            'settleSkipped' => $frame->settleSkipped,
            'paintSkipped' => $frame->paintSkipped,
            'rebuilt' => $rebuilt,
            'byKind' => $byKind,
            'roots' => $roots,
        ];
    }

    /**
     * 某个节点在某一帧为什么重建（D0 `why()` 的转发，帧缺省为最近一帧）。
     *
     * @return array<string, mixed>
     */
    public function why(int $nodeId, ?int $frameId = null): array
    {
        return $this->recorder->why($nodeId, $frameId);
    }

    /**
     * 单帧的**因果森林**：重建节点按父边连成树，state 写入点作为树里的标记。
     *
     * ## 为什么是森林而不是排行榜
     *
     * 一次动作帧里 350 个节点各重建 1 次，"按次数排名"没有任何区分度，排序退化成
     * 路径先序，前几行必然全是祖先。成熟工具（React Profiler / Flutter Widget
     * Rebuild Stats）在单帧里给的是**层级**，次数留到多帧聚合才有意义。
     *
     * ## 根的定义：父没有重建的节点
     *
     * 森林因此在结构上**不重叠**：`Σ(根.subtree × 根.count) + hidden == rebuilt`，可判据。
     * state 写入点**不另起根**（它常常嵌在另一个写入点的子树里，比如
     * `PageShell ⊃ Table`），只标 `kind` + `site` —— 嵌套关系正是"两次写入谁在谁里面"
     * 的答案，另起根会把它抹平。
     *
     * ## 同形兄弟折叠
     *
     * 表格页的一帧 360 个重建，其中 `Column` 底下 12 个 `SizedBox` 完全同形 —— 逐条
     * 列出来就是 12 行一模一样的东西，把树本身的形状冲散。所以按**结构指纹**把同形
     * 兄弟折成一组（`count=N`，另带全部 `ids`），一层里有多少种形状就显示几行。
     * 这是 perf / WPA 折叠相同调用栈、Instruments 折叠递归的同一手法。
     *
     * 折叠是**无损**的：组里留着每个成员的真实 id，展开与 `why(id)` 都还能用；
     * 丢掉的只有兄弟之间的先后次序，而重建次序不承载因果。
     *
     * 指纹是**递归**的（类型 + 因果 + 子树规模 + 子节点指纹的多重集）。只比类型与规模
     * 会把 `Column[Text, Text]` 和 `Column[Padding[Text]]` 折成一组：规模一样、结构
     * 不同，那是在报告里编造结构。
     *
     * 口径：`×N` 是兄弟个数，其余数字（`subtree` / `omitted` / `hidden`）都是**每个
     * 实例**的。于是一行可以读成"这个形状重复了 N 份，每份这么大"。
     *
     * @param int $maxDepth     树往下展开几层（更深标 `cut`）
     * @param int $maxChildren  每层最多留几**组**（其余计入 `omitted` / `hidden`）
     * @return array<string, mixed>
     */
    public function cascade(int $frameId, int $maxDepth = 5, int $maxChildren = 8): array
    {
        $frame = $this->recorder->frame($frameId);
        if ($frame === null) {
            throw new \InvalidArgumentException("没有这一帧：{$frameId}（缓冲窗口之外或尚未发生）");
        }

        $shape = $this->shapeOf($frame);
        /** @var array<int, list<int>> $children */
        $children = $shape['children'];
        /** @var array<int, int> $subtree */
        $subtree = $shape['subtree'];
        $causeOf = $frame->causeOf();
        $signature = $this->signatures($frame, $children, $causeOf, $subtree);

        $writes = [];
        foreach ($frame->rebuilt as $id) {
            $edge = $causeOf[$id] ?? null;
            if ($edge === null || $edge->kind !== 'state') {
                continue;
            }
            $writes[] = [
                'id' => $id,
                'path' => $this->recorder->pathOf($id),
                'type' => $this->recorder->typeOf($id),
                'site' => $edge->site,
                'detail' => $edge->detail,
                'subtree' => $subtree[$id] ?? 1,
            ];
        }
        \usort($writes, static fn(array $a, array $b): int => $b['subtree'] <=> $a['subtree']
            ?: \strcmp((string) $a['path'], (string) $b['path']));

        // 森林这一层同样受 `maxChildren` 约束 —— 一条规则用到底，不为"根"开特例。
        // 截断量单独报出去（顶层的 `omitted` / `hidden`），调用方才能把"树加起来
        // 不等于 rebuilt"解释清楚，而不是让它看起来像漏了节点。
        /** @var list<array<string, mixed>> $tree */
        [$tree, $omitted, $hidden] = $this->foldGroups($shape['roots'], $children, $causeOf, $subtree, $signature, 0, $maxDepth, $maxChildren);

        return [
            'frame' => $frame->id,
            'rebuilt' => \count($frame->rebuilt),
            'depthMax' => (int) $shape['depthMax'],
            'roots' => $tree,
            'omitted' => $omitted,
            'hidden' => $hidden,
            'writes' => $writes,
            'byType' => $this->byType($frame),
        ];
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
        $frameId = $frame ?? $this->recorder->lastFrameId();
        $window = $this->recorder->geometryCoverage();
        if ($frameId < (int) ($window['from'] ?? 0) || $frameId > (int) ($window['to'] ?? 0)) {
            return [
                'frame' => $frameId,
                'rows' => [],
                'window' => $window,
                'reason' => 'frame-out-of-window',
            ];
        }

        $records = $this->recorder->geometryRows($frameId);
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
                'type' => $this->recorder->typeOf($node),
                'applied' => $record->applied,
                'inner' => $record->inner,
                'size' => $record->size,
                'box' => $record->box,
            ];
            $effective = \max($effective, $record->frame);
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
        $frameId = $frame ?? $this->recorder->lastFrameId();
        $hit = $this->geometryLookup($node, $frameId);
        if ($hit['record'] === null) {
            return $hit['reason'] === 'frame-out-of-window'
                ? $this->frameOutOfWindow($node, $frameId, $hit['window'])
                : $this->geometryMiss($node, $frameId, $hit['window']);
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
        $frameId = $frame ?? $this->recorder->lastFrameId();
        $hit = $this->geometryLookup($node, $frameId);
        if ($hit['record'] === null) {
            return $hit['reason'] === 'frame-out-of-window'
                ? $this->frameOutOfWindow($node, $frameId, $hit['window'])
                : $this->geometryMiss($node, $frameId, $hit['window']);
        }

        $window = $hit['window'];
        $all = [];
        $cursor = $hit['record'];
        // 环保护：父链来自记录，记录一旦坏掉（父指向自己/互指）这里会无限循环。
        // 与 `pathOf()` 的 `$guard` 同一条纪律：报告不该陪着记录一起死。
        $guard = 0;
        while ($cursor !== null && $cursor->parent > 0 && $guard++ < 64) {
            $parent = $this->recorder->geometryAt($frameId, $cursor->parent);
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
            $parentFlex = $this->recorder->isFlex($parent->node);

            $all[] = [
                'from' => $parent->node,
                'fromType' => $this->recorder->typeOf($parent->node),
                'to' => $cursor->node,
                'toType' => $this->recorder->typeOf($cursor->node),
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
            'type' => $this->recorder->typeOf($node),
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
     * 跨域对齐：同一节点、同一显式帧，一次取到因果 / 几何 / 网络三张脸。
     *
     * ## 帧只在一处选
     *
     * `frame` 必填。三域的"缺省帧"语义本来就不同（因果取最近重建帧、几何取
     * 最后一帧、网络没有缺省），让各域自己缺省再拼起来，会给出"三域都在 #N"
     * 的假象。调用方——包括 `brief` 的投影——先选帧，再传进来；本查询只读，
     * 不做"帮你选一个"。
     *
     * ## 每域回显自己实际命中的帧
     *
     * 几何是按变化存的：目标帧没重新采样就往前找最近一条。若它返回 `frame=1`
     * 而请求 `frame=2`，这里标 `stale=true` 并回显 `1`，绝不伪称 `2`。一个
     * "看起来三域都对齐、实际几何是上一帧"的答案比明确说 stale 更危险。
     *
     * ## 三域各自的 miss 形状归一
     *
     * `found: false` + `reason` + `window`，不再有 `reason` / `miss` / 无 found
     * 三种拼法。`reason` 是分类（unknown-node / frame-out-of-window /
     * no-render-box / no-landed-request），不是错误全文。
     *
     * @return array<string, mixed>
     */
    public function align(int $node, int $frame): array
    {
        $known = $this->recorder->typeOf($node) !== '';
        $base = [
            'node' => $node,
            'frame' => $frame,
            'path' => $this->recorder->pathOf($node),
        ];
        $win = [
            'frames' => $this->recorder->stats()['capacity'],
            'geometry' => $this->recorder->geometryCoverage(),
            'network' => $this->recorder->networkCoverage(),
        ];
        if (!$known) {
            return $base + [
                'found' => false,
                'reason' => 'unknown-node',
                'causal' => ['found' => false, 'reason' => 'unknown-node'],
                'geometry' => $this->geometryMiss($node, $frame, $win['geometry']),
                'network' => ['found' => false, 'reason' => 'unknown-node', 'window' => $win['network']],
                'windows' => $win,
            ];
        }

        // ---- 因果：显式帧，不走 why 的"最近重建帧"缺省。
        $frameRecord = $this->recorder->frame($frame);
        if ($frameRecord === null) {
            $causal = ['found' => false, 'reason' => 'frame-out-of-window'];
        } else {
            $causes = [];
            foreach ($frameRecord->causes as $edge) {
                if ($edge->toId === $node) {
                    $causes[] = $edge->toArray();
                }
            }
            $causal = [
                'found' => true,
                'frame' => $frameRecord->id,
                'rebuilt' => $frameRecord->rebuilt($node),
                'causes' => $causes,
            ];
        }

        // ---- 几何：记录自带实际帧号，stale 由它判定，不靠调用方猜。
        // 判窗口与查记录都走 `geometryLookup()`：与另外三个查询同一份语义。
        $hit = $this->geometryLookup($node, $frame, $win['geometry']);
        $geo = $hit['record'];
        if ($geo === null) {
            $geometry = $hit['reason'] === 'frame-out-of-window'
                ? $this->frameOutOfWindow($node, $frame, $win['geometry'])
                : $this->geometryMiss($node, $frame, $win['geometry']);
        } else {
            $geometry = [
                'found' => true,
                'frame' => $geo->frame,
                'stale' => $geo->frame !== $frame,
                'size' => $geo->size,
                'offset' => $geo->offset,
                'applied' => $geo->applied,
            ];
        }

        // ---- 网络：只认帧末回填的 landed[]；扫 500 条窗口反查，不建镜像索引。
        $landed = [];
        foreach ($this->recorder->requestsLandingOn($node) as $requestId) {
            $request = $this->recorder->networkAt($requestId);
            if ($request !== null && $request->settledAtFrame === $frame) {
                $landed[] = [
                    'request' => $request->requestId,
                    'method' => $request->method,
                    'url' => $request->url,
                    'status' => $request->status,
                    'ok' => $request->ok,
                ];
            }
        }
        $network = $landed === []
            ? ['found' => false, 'reason' => 'no-landed-request', 'window' => $win['network']]
            : ['found' => true, 'frame' => $frame, 'requests' => $landed, 'window' => $win['network']];

        $causalFound = ($causal['found'] ?? false) === true;
        $geometryFound = ($geometry['found'] ?? false) === true;
        $networkFound = ($network['found'] ?? false) === true;

        return $base + [
            // `found` = 这个地址在证据里存在（任一域有值）；`complete` = 三域齐全。
            // 分开说：读者常把 `found=true` 误读成"三域都有"，那就又是一次假对齐。
            'found' => $causalFound || $geometryFound || $networkFound,
            'complete' => $causalFound && $geometryFound && $networkFound,
            'causal' => $causal,
            'geometry' => $geometry,
            'network' => $network,
            'windows' => $win,
        ];
    }

    /**
     * 跨帧聚合**根因**：同一调用点写同一 State 的次数。
     *
     * 只算 `kind=state`。`parent` / `unknown` 是传播与兜底，算进来会让排名失真。
     *
     * @return list<array<string, mixed>>
     */
    public function hotspots(int $frames = 300, int $limit = 10): array
    {
        $agg = [];
        foreach ($this->window($frames) as $frame) {
            $shape = $this->shapeOf($frame);
            /** @var array<int, int> $subtree */
            $subtree = $shape['subtree'];
            foreach ($frame->causes as $edge) {
                if ($edge->kind !== 'state') {
                    continue; // 传播与兜底不是根因：算进来会让排名全是下游节点
                }
                $key = $edge->site . "\0" . $edge->detail;
                if (!isset($agg[$key])) {
                    $agg[$key] = [
                        'site' => $edge->site,
                        'detail' => $edge->detail,
                        'writes' => 0,
                        'frames' => [],
                        'nodes' => [],
                        'subtree' => 0,
                    ];
                }
                ++$agg[$key]['writes'];
                $agg[$key]['frames'][$frame->id] = true;
                $agg[$key]['nodes'][$edge->toId] = true;
                // 同一写入点多帧出现时取**最大单帧波及**：与"写了多少次"是两个问题，
                // 合并成合计会把"偶尔一次打到整树"和"每帧动 3 个"算成同一档。
                $agg[$key]['subtree'] = \max((int) $agg[$key]['subtree'], $subtree[$edge->toId] ?? 1);
            }
        }

        return $this->rank(
            \array_values(\array_map(
                static fn(array $row): array => [
                    'site' => $row['site'],
                    'detail' => $row['detail'],
                    'writes' => $row['writes'],
                    'frames' => \count($row['frames']),
                    'nodes' => \count($row['nodes']),
                    'subtree' => $row['subtree'],
                ],
                $agg,
            )),
            'writes',
            'site',
            $limit,
        );
    }

    /**
     * 跨帧聚合**节点**：谁被重建得最多，以及其中多少是自身写入、多少是父传播。
     *
     * @return list<array<string, mixed>>
     */
    public function nodes(int $frames = 300, int $limit = 10): array
    {
        $agg = [];
        foreach ($this->window($frames) as $frame) {
            $shape = $this->shapeOf($frame);
            /** @var array<int, int> $subtree */
            $subtree = $shape['subtree'];
            foreach ($frame->rebuilt as $id) {
                $agg[$id] ??= [
                    'id' => $id,
                    'path' => $this->recorder->pathOf($id),
                    'rebuilds' => 0,
                    'self' => 0,
                    'inherited' => 0,
                    'subtree' => 0,
                    'first' => $frame->id,
                    'last' => $frame->id,
                ];
                ++$agg[$id]['rebuilds'];
                $agg[$id]['last'] = $frame->id;
                $agg[$id]['subtree'] = \max((int) $agg[$id]['subtree'], $subtree[$id] ?? 1);
            }
            foreach ($frame->causes as $edge) {
                $id = $edge->toId;
                if (!isset($agg[$id])) {
                    continue;
                }
                if ($edge->kind === 'state') {
                    ++$agg[$id]['self'];
                } else {
                    ++$agg[$id]['inherited'];
                }
            }
        }

        $rows = \array_values($agg);
        // 排序三级：次数（跨帧才有区分度）→ 覆盖子树（单帧里"谁牵动得多"）→ 路径（稳定）。
        // 只按次数排会在"每节点各 1 次"的动作帧里退化成路径先序，把真正的分叉点埋掉。
        \usort($rows, static fn(array $a, array $b): int => ($b['rebuilds'] <=> $a['rebuilds'])
            ?: ((int) $b['subtree'] <=> (int) $a['subtree'])
            ?: \strcmp((string) $a['path'], (string) $b['path']));

        return $limit > 0 ? \array_slice($rows, 0, $limit) : $rows;
    }

    /**
     * 网络请求骨架：请求 → 帧对齐 → 落地节点，与帧因果共用同一条时间轴。
     *
     * ## 为什么是骨架而不是报文
     *
     * 抓包工具回答"发了什么、回了什么"，这里回答的是它们给不了的第三层：
     * 响应落在第几帧、让谁重建了。记 body / header 既撑爆证据包又碰隐私，而且
     * 抓包做得更好 —— 证据的定位是定位，不是副本。
     *
     * ## 窗口自报是硬义务
     *
     * 网络有独立的 500 条窗口（帧是 600）。查不到时不能让读者以为是"那段时间没
     * 发请求"：要么请求被裁掉了，要么录制器当时没装。所以 `window` 随答案一起给。
     *
     * @return array{rows: list<array<string, mixed>>, window: array<string, mixed>, total: int}
     */
    public function network(?int $request = null, int $limit = 50): array
    {
        $window = $this->recorder->networkCoverage();
        if ($request !== null) {
            $record = $this->recorder->networkAt($request);
            if ($record === null) {
                return [
                    'rows' => [],
                    'window' => $window,
                    'total' => 0,
                    'miss' => $this->networkMissReason($request, $window),
                ];
            }

            return ['rows' => [$record->toArray()], 'window' => $window, 'total' => 1];
        }

        $rows = \array_map(
            static fn(NetworkRecord $r): array => $r->toArray(),
            $this->recorder->networkRows(),
        );
        $total = \count($rows);

        return [
            // 取**最新**的 N 条：查网络面板的人要看刚发的请求。早先 `array_slice($rows, 0, …)`
            // 取的是最旧的 —— 窗口满 500 条时，列表里全是几分钟前的东西。
            'rows' => $limit > 0 ? \array_slice($rows, -$limit) : $rows,
            'window' => $window,
            'total' => $total,
        ];
    }

    /**
     * 按 requestId 取报文（骨架的可选附件）。
     *
     * ## 为什么不并进 network()
     *
     * 报文默认不出现——体积、隐私、协议 8MB 上限三条理由。把它做成独立查询，
     * `network()` 保持默认轻量可导出；要看 body 时显式问一次，还带窗口自报。
     *
     * ## 截断是分两层的
     * 存档时单条最多 `MAX_BYTES`（64KB），查询时再按 `$maxBytes` 截一次——
     * 后者是协议护栏，防止"一条 64KB × 50 条"撑爆单条消息。两层都报截断，
     * 读者知道看到的是片段而不是全部。
     *
     * @return array<string, mixed>
     */
    public function networkBody(int $request, string $part = 'both', int $maxBytes = 8 * 1024): array
    {
        $window = $this->recorder->bodyCoverage();
        $part = \in_array($part, ['both', 'request', 'response'], true) ? $part : 'both';
        $maxBytes = \max(0, $maxBytes);
        $record = $this->recorder->bodyAt($request);
        if ($record === null) {
            $skeleton = $this->recorder->networkAt($request);

            return [
                'request' => $request,
                'found' => false,
                // 骨架在 ⇒ 报文只是没存/被裁（`body-not-retained`）；骨架不在 ⇒
                // 连请求本身都查不到，这时才用**网络**窗口判"窗口外还是没这条"。
                // 用报文窗口判会错：报文窗口(200)比网络窗口(500)小，网络里还在的
                // 请求会被说成"窗口外"。
                'miss' => $skeleton === null
                    ? $this->networkMissReason($request, $this->recorder->networkCoverage())
                    : 'body-not-retained',
                'window' => $window,
            ];
        }

        return ['found' => true] + $record->select($part, $maxBytes) + ['window' => $window];
    }

    // ------------------------------------------------------------------ 内部

    /**
     * 请求查不到时是"窗口外"还是"根本没有这条"。
     *
     * ★ 两个查询各写一遍必然漂：早先 `network()` 只比 `from`（空窗口 `from=0`，
     *   任何 id 都大于它 ⇒ 一律报 `unknown-request`），`networkBody()` 先看
     *   `requests === 0`。同一个请求，两个查询给两种答案。
     *
     * @param array<string, mixed> $window 需含 `requests` 与 `from`
     */
    private function networkMissReason(int $request, array $window): string
    {
        return (int) ($window['requests'] ?? 0) === 0 || $request < (int) ($window['from'] ?? 0)
            ? 'outside-network-window'
            : 'unknown-request';
    }

    /**
     * 几何查证的**唯一入口**：先判帧在不在窗口内，再查记录。
     *
     * ★ 为什么必须收成一处：`geometryAt()` 的"往前找最近一次"只对**窗口内**成立。
     *   四个查询各判一遍窗口，实际上只会修到其中几个 —— 早先只有 `align()` 判了，
     *   `boxModel` / `boxes` / `constraints` 照样把窗口末端的旧几何当成目标帧的，
     *   于是"查一个根本没采样过的帧"返回 `found=true`。这类答案自洽、可读、全错。
     *
     * @param array<string, mixed>|null $window 已知窗口时传进来，省一次遍历
     * @return array{record: ?GeometryRecord, window: array<string, mixed>, reason: ?string}
     *   `reason` 非 null = 帧不在窗口，"连有没有这个盒"都回答不了。
     */
    private function geometryLookup(int $node, int $frame, ?array $window = null): array
    {
        $window ??= $this->recorder->geometryCoverage();
        $from = (int) ($window['from'] ?? 0);
        $to = (int) ($window['to'] ?? 0);
        if ($frame < $from || $frame > $to) {
            return ['record' => null, 'window' => $window, 'reason' => 'frame-out-of-window'];
        }

        return ['record' => $this->recorder->geometryAt($frame, $node), 'window' => $window, 'reason' => null];
    }

    /**
     * 帧号在窗口外：与"窗口内但没有这个盒"是两件事，不能混。
     *
     * @param array<string, mixed> $window
     * @return array<string, mixed>
     */
    private function frameOutOfWindow(int $node, int $frame, array $window): array
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
    private function geometryMiss(int $node, int $frame, array $window): array
    {
        $boxless = $this->recorder->isBoxless($node);
        $out = [
            'found' => false,
            'node' => $node,
            'frame' => $frame,
            'reason' => $boxless
                ? 'no-render-box'
                : ($this->recorder->typeOf($node) === '' ? 'unknown-node' : 'outside-geometry-window'),
            'window' => $window,
        ];

        if ($boxless) {
            $child = $this->recorder->firstBoxedChild($node);
            $out['suggest'] = $child === null
                ? '这个节点是复合节点，没有自己的盒；往下找有盒的后代'
                : \sprintf('查有盒的子节点 %d（%s）', $child, $this->recorder->typeOf($child));
        }

        return $out;
    }

    /**
     * 最近 N 帧（`$frames <= 0` 表示全窗口），按帧号升序。
     *
     * @return array<int, FrameRecord>
     */
    private function window(int $frames): array
    {
        $all = $this->recorder->frames();
        if ($frames <= 0 || \count($all) <= $frames) {
            return $all;
        }

        return \array_slice($all, -$frames, null, true);
    }

    /**
     * 帧的森林骨架，按帧号缓存。
     *
     * `roots` 是"父没有重建"的节点 ⇒ 森林不重叠，子树规模之和等于重建数。
     * `subtree` 由深到浅累加（`arsort($depth)` 保证子先于父落账）。
     *
     * @return array{children: array<int, list<int>>, roots: list<int>, subtree: array<int, int>, depthMax: int, parent: array<int, int>}
     */
    private function shapeOf(FrameRecord $frame): array
    {
        if (isset($this->shapeCache[$frame->id])) {
            /** @var array{children: array<int, list<int>>, roots: list<int>, subtree: array<int, int>, depthMax: int, parent: array<int, int>} */
            return $this->shapeCache[$frame->id];
        }
        // 缓存键是帧号，环形缓冲裁掉的帧不会再被问到；到容量就整体丢弃，
        // 不让它跟着会话无限长（一份骨架比一条帧记录大得多）。
        if (\count($this->shapeCache) >= 600) {
            $this->shapeCache = [];
        }

        $parent = $frame->parentOf();
        $rebuiltSet = [];
        foreach ($frame->rebuilt as $id) {
            $rebuiltSet[$id] = true;
        }

        $children = [];
        $roots = [];
        $depth = [];
        foreach ($frame->rebuilt as $id) {
            $p = $parent[$id] ?? null;
            if ($p !== null && isset($rebuiltSet[$p])) {
                $children[$p][] = $id;
            } else {
                $roots[] = $id;
            }

            $d = 0;
            $cursor = $id;
            $guard = 0;
            while (isset($parent[$cursor]) && $guard++ < 64) {
                $cursor = $parent[$cursor];
                ++$d;
            }
            $depth[$id] = $d;
        }

        $subtree = [];
        foreach ($frame->rebuilt as $id) {
            $subtree[$id] = 1;
        }
        \arsort($depth);
        foreach ($depth as $id => $d) {
            $p = $parent[$id] ?? null;
            if ($p !== null && isset($subtree[$p])) {
                $subtree[$p] += $subtree[$id];
            }
        }

        $shape = [
            'children' => $children,
            'roots' => $roots,
            'subtree' => $subtree,
            'depthMax' => $depth === [] ? 0 : \max($depth),
            'parent' => $parent,
        ];
        $this->shapeCache[$frame->id] = $shape;

        return $shape;
    }

    /**
     * 结构指纹：同形的子树得到同一个整数。
     *
     * 用 interning（键串 => 递增整数）而不是 md5：子节点已经是指纹整数，键串长度只与
     * "这一层有几个孩子"有关，不随子树规模膨胀，也没有碰撞可担心。代价是指纹只在**一帧
     * 内**可比 —— 它的用途是分组，不是跨帧身份。要做"这个形状在 20 帧里有 12 帧重建"，
     * 那时该留的是键串本身（内容寻址），不是这里的整数。
     *
     * 键里必须带因果（kind / site / detail）：两个同形节点，一个自己写了状态、一个只是
     * 被父带着重建，折成一组就再也说不出"是从哪儿写的"。
     *
     * @param array<int, list<int>> $children
     * @param array<int, Edge>      $causeOf
     * @param array<int, int>       $subtree
     * @return array<int, int> 节点 id => 指纹
     */
    private function signatures(FrameRecord $frame, array $children, array $causeOf, array $subtree): array
    {
        $signature = [];
        $intern = [];
        $visit = function (int $id) use (&$visit, &$signature, &$intern, $children, $causeOf, $subtree): void {
            $kids = [];
            foreach ($children[$id] ?? [] as $kid) {
                $visit($kid);
                $kids[] = $signature[$kid];
            }
            // 兄弟次序不进入指纹：同形指的是**无序**同构。重建次序不承载因果，
            // 把它算进去只会让"只差构建次序"的两个子树折不成一组 —— 而它们在报告里
            // 讲的是同一件事。
            \sort($kids);
            $edge = $causeOf[$id] ?? null;
            $key = $this->recorder->typeOf($id)
                . "\x1f" . ($edge?->kind ?? '')
                . "\x1f" . ($edge?->site ?? '')
                . "\x1f" . ($edge?->detail ?? '')
                . "\x1f" . ($subtree[$id] ?? 1)
                . "\x1f" . \implode(',', $kids);
            $signature[$id] ??= $intern[$key] ??= \count($intern);
        };

        // 只走访重建节点：`children` 就是按"父也重建"建出来的，所以每个节点恰好一次。
        foreach ($frame->rebuilt as $id) {
            if (!isset($signature[$id])) {
                $visit($id);
            }
        }

        return $signature;
    }

    /**
     * 把一层的兄弟按结构指纹分组，返回 `[组节点, 未显示的兄弟数, 未显示的节点数]`。
     *
     * `$ids` 必须是**同一个父**的孩子（或森林的根）。后两个返回值是**本层**口径
     * （`maxChildren` 裁掉的那部分），不含子层递归的量 —— 内层节点的规模已经算在
     * 根的 `subtree` 里，累加会超算。内层的裁剪由节点上的 `omitted` 逐层报出，
     * 那是**支**口径，消费者递归读它。
     *
     * @param list<int>             $ids
     * @param array<int, list<int>> $children
     * @param array<int, Edge>      $causeOf
     * @param array<int, int>       $subtree
     * @param array<int, int>       $signature
     * @return array{0: list<array<string, mixed>>, 1: int, 2: int}
     */
    private function foldGroups(array $ids, array $children, array $causeOf, array $subtree, array $signature, int $depth, int $maxDepth, int $maxChildren): array
    {
        /** @var array<int, list<int>> $buckets 指纹 => 同形兄弟 */
        $buckets = [];
        foreach ($ids as $id) {
            $buckets[$signature[$id]][] = $id;
        }

        $groups = [];
        foreach ($buckets as $key => $members) {
            // 成员按 id 升序：组内次序与遍历顺序无关，人和判据看到的都是同一串数字。
            \sort($members);
            $groups[] = [
                'sig' => $key,
                'ids' => $members,
                'subtree' => $subtree[$members[0]] ?? 1,
                'type' => $this->recorder->typeOf($members[0]),
            ];
        }
        // 波及大的先显示：预算有限时留下的必须是牵动最多的那一支（"18 个的根"不能把
        // 真正 342 个的那支挤到截断之后）。同波及时"同形份数多"的优先 —— 重复本身
        // 就是这一帧值得看的事实。最后按类型名与指纹兜底，输出与遍历顺序无关。
        \usort($groups, static fn(array $a, array $b): int => $b['subtree'] <=> $a['subtree']
            ?: \count($b['ids']) <=> \count($a['ids'])
            ?: \strcmp($a['type'], $b['type'])
            ?: $a['sig'] <=> $b['sig']);

        $shown = $maxChildren > 0 ? \array_slice($groups, 0, $maxChildren) : $groups;
        $omitted = 0;
        $hidden = 0;
        foreach (\array_slice($groups, \count($shown)) as $group) {
            $omitted += \count($group['ids']);
            $hidden += \count($group['ids']) * $group['subtree'];
        }

        $nodes = [];
        foreach ($shown as $group) {
            $nodes[] = $this->groupNode($group, $children, $causeOf, $subtree, $signature, $depth, $maxDepth, $maxChildren);
        }

        // ★ 这里**只**返回本层被裁的量，不累加子层递归上来的。
        //   看起来"漏了内层裁剪"，其实是口径不同：`hidden` 是**节点**口径，
        //   而内层节点的规模已经算在根的 `subtree` 里（`Σ(根.subtree × 根.count)`
        //   覆盖整棵子树，不论展开与否）。累加子层会让恒等式
        //   `Σ(根.subtree × 根.count) + hidden == rebuilt` 超算。
        //
        //   "内层被 maxChildren 裁掉了"这件事由节点上的 `omitted` 逐层报出，
        //   消费者（`Brief::coverage()`）递归读它 —— 那是**支**口径，可以累加。
        return [$nodes, $omitted, $hidden];
    }

    /**
     * 一组同形兄弟 → 树里的一行。
     *
     * 只走**代表**（id 最小的那个）的子节点：组内成员同形，意味着它们子节点指纹的多重集
     * 相同，所以代表的孩子分组就是每一份实例的孩子分组 —— 不必把 N 份都走一遍，
     * 也不会有"第 7 份和第 3 份长得不一样"这种自相矛盾（那说明指纹算错了）。
     *
     * @param array{sig: int, ids: list<int>, subtree: int, type: string} $group
     * @param array<int, list<int>> $children
     * @param array<int, Edge>      $causeOf
     * @param array<int, int>       $subtree
     * @param array<int, int>       $signature
     * @return array<string, mixed>
     */
    private function groupNode(array $group, array $children, array $causeOf, array $subtree, array $signature, int $depth, int $maxDepth, int $maxChildren): array
    {
        $rep = $group['ids'][0];
        $edge = $causeOf[$rep] ?? null;
        $count = \count($group['ids']);
        $node = [
            'id' => $rep,
            'type' => $group['type'],
            'kind' => $edge?->kind ?? '',
            'count' => $count,
            'subtree' => $group['subtree'],
            'site' => $edge?->site ?? '',
            'detail' => $edge?->detail ?? '',
            'children' => [],
            'omitted' => 0,
            'hidden' => 0,
            'cut' => false,
        ];
        if ($count > 1) {
            // 折叠无损：展开与 `why(id)` 都要能拿到真实 id。单个节点不给 `ids` ——
            // 那会让同一份信息在一帧里有两种写法，而两种写法迟早不一致。
            $node['ids'] = $group['ids'];
        }

        $kids = $children[$rep] ?? [];
        if ($kids === []) {
            return $node;
        }
        if ($depth >= $maxDepth) {
            // 截断处也要把规模说准：支数与节点数都按每个实例计。
            $node['cut'] = true;
            $node['omitted'] = \count($kids);
            $node['hidden'] = $group['subtree'] - 1;

            return $node;
        }

        [$node['children'], $omitted, $hidden] = $this->foldGroups($kids, $children, $causeOf, $subtree, $signature, $depth + 1, $maxDepth, $maxChildren);
        $node['omitted'] = $omitted;
        $node['hidden'] = $hidden;

        return $node;
    }

    /**
     * 按 Widget 类型聚合（React Profiler 的 "component ×N" 口径）。
     *
     * 截断只影响树，不影响这里：类型构成必须永远覆盖全部重建节点。
     *
     * @return list<array{type: string, nodes: int}>
     */
    private function byType(FrameRecord $frame): array
    {
        $agg = [];
        foreach ($frame->rebuilt as $id) {
            $type = $this->recorder->typeOf($id);
            if ($type === '') {
                $type = '?';
            }
            $agg[$type] = ($agg[$type] ?? 0) + 1;
        }
        \arsort($agg);

        $out = [];
        foreach ($agg as $type => $nodes) {
            $out[] = ['type' => (string) $type, 'nodes' => $nodes];
        }

        return $out;
    }

    /**
     * 按次数字段降序；并列时按键字段升序，保证输出稳定（同名同数不随帧序抖）。
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function rank(array $rows, string $by, string $tie, int $limit): array
    {
        \usort($rows, static fn(array $a, array $b): int => ($b[$by] <=> $a[$by])
            ?: \strcmp((string) $a[$tie], (string) $b[$tie]));

        return $limit > 0 ? \array_slice($rows, 0, $limit) : $rows;
    }
}
