<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 查询面：把录制记录读成"能回答问题的形状"。
 *
 * 纯函数：只读 {@see Recorder} 的不可变帧记录，不改状态、不碰元素树。
 * 所以可以重试、可以缓存、可以在任意线程问卷（记录本身在写时拷贝）。
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

    public function __construct(private readonly Recorder $recorder)
    {
    }

    /** @return array<string, mixed> 帧数 / 节点数 / 容量 / 最近帧耗时与重建数 */
    public function stats(): array
    {
        return $this->recorder->stats();
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
     * 森林因此在结构上**不重叠**：`Σ(根.subtree) == rebuilt 数`，这条是可判据的。
     * state 写入点**不另起根**（它常常嵌在另一个写入点的子树里，比如
     * `PageShell ⊃ Table`），只标 `writes` —— 嵌套关系正是"两次写入谁在谁里面"
     * 的答案，另起根会把它抹平。
     *
     * @param int $maxDepth     树往下展开几层（更深标 `cut`）
     * @param int $maxChildren  每层最多留几个孩子（其余记 `omitted`）
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
        /** @var list<int> $roots */
        $roots = $shape['roots'];
        /** @var array<int, int> $subtree */
        $subtree = $shape['subtree'];
        $causeOf = $frame->causeOf();

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

        // 根按波及降序：显示预算有限，先给读者看牵动最多的那一支，
        // 否则"18 个的根"会先占满行数，把真正 342 个的那支挤到截断之后。
        \usort($roots, fn(int $a, int $b): int => ($subtree[$b] ?? 1) <=> ($subtree[$a] ?? 1) ?: $a <=> $b);

        $tree = [];
        foreach ($roots as $id) {
            $tree[] = $this->treeNode($id, $children, $causeOf, $subtree, 0, $maxDepth, $maxChildren);
        }

        return [
            'frame' => $frame->id,
            'rebuilt' => \count($frame->rebuilt),
            'depthMax' => (int) $shape['depthMax'],
            'roots' => $tree,
            'writes' => $writes,
            'byType' => $this->byType($frame),
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

    // ------------------------------------------------------------------ 内部

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
     * 递归造一个树节点；子节点按子树降序 —— 截断时留下的必须是"波及最大"的分支。
     *
     * @param array<int, list<int>> $children
     * @param array<int, Edge>      $causeOf
     * @param array<int, int>       $subtree
     * @return array<string, mixed>
     */
    private function treeNode(int $id, array $children, array $causeOf, array $subtree, int $depth, int $maxDepth, int $maxChildren): array
    {
        $edge = $causeOf[$id] ?? null;
        $kids = $children[$id] ?? [];
        $node = [
            'id' => $id,
            'type' => $this->recorder->typeOf($id),
            'subtree' => $subtree[$id] ?? 1,
            'writes' => $edge !== null && $edge->kind === 'state',
            'site' => $edge?->site ?? '',
            'detail' => $edge?->detail ?? '',
            'children' => [],
            'omitted' => 0,
            'cut' => false,
        ];

        if ($kids === []) {
            return $node;
        }
        if ($depth >= $maxDepth) {
            $node['cut'] = true;
            $node['omitted'] = \count($kids);

            return $node;
        }

        \usort($kids, fn(int $a, int $b): int => ($subtree[$b] ?? 1) <=> ($subtree[$a] ?? 1) ?: $a <=> $b);
        $shown = \array_slice($kids, 0, $maxChildren);
        $node['omitted'] = \count($kids) - \count($shown);
        foreach ($shown as $kid) {
            $node['children'][] = $this->treeNode($kid, $children, $causeOf, $subtree, $depth + 1, $maxDepth, $maxChildren);
        }

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
