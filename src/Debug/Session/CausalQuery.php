<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 因果域查询：这一帧变了什么、因为谁、跨帧看谁最贵。
 *
 * 只读 {@see Recorder} 的帧窗口（600 帧）与节点注册表的类型名，不改状态、不碰活树。
 *
 * ## 两个聚合的口径不同，不要合并
 *
 * - {@see self::hotspots()} 回答"谁在写"：只聚合 `kind=state` 的根因边，按调用点 +
 *   State 类排名。
 * - {@see self::nodes()} 回答"谁在重建"：按节点聚合，含父传播。
 *
 * 一个 `setState` 会让整棵子树逐层产生 `parent` 边。把传播算进根因，排名会全是
 * 下游节点，真正的原因反而沉底。反过来只看根因，又看不出"哪个子树被牵连最重"。
 *
 * ## 森林骨架缓存为什么在这里
 *
 * `shapeCache` 只服务 `cascade` / `hotspots` / `nodes` 三个方法，且以**代际**为键
 * （`reset()` 会把帧号归零，按帧号缓存会命中上一轮同号帧 ⇒ 新因果配旧结构，
 * 一棵自洽、可读、全错的树）。它跟着这三个方法走，不留在门面上 —— 门面没有
 * 状态，状态在哪一层看得见。
 *
 * @internal 门面 {@see Query} 的实现细节，不是应用开发者的 API 面
 */
final class CausalQuery
{
    /** @var array<int, array<string, mixed>> 帧号 => 该帧的森林骨架（复用于 cascade / 聚合） */
    private array $shapeCache = [];

    /** `shapeCache` 属于哪一代；与 `Recorder::generation()` 不一致就整体丢弃。 */
    private int $cacheGeneration = -1;

    public function __construct(private readonly Recorder $recorder)
    {
    }

    /**
     * 最近 N 帧的摘要，按帧号升序。
     *
     * `since` 是**增量游标**：只要帧号大于它的帧。游标由调用方持有（记最后一条的
     * `id`），服务端不记"你看到哪了" —— 无状态的读可以重试、可以并行、可以
     * 两个消费者各拿一份互不干扰。这也正是"订阅"给不了的东西：订阅一旦漏一条，
     * 之后每一条都是错的，而游标只是"再问一次"。
     *
     * @return array{frames: list<array<string, mixed>>, window: array<string, mixed>, dropped: int, since: int|null}
     */
    public function frames(int $limit = 30, bool $rebuiltOnly = false, ?int $since = null): array
    {
        $all = $this->recorder->frames();
        $from = $all === [] ? 0 : (int) \array_key_first($all);
        $to = $all === [] ? 0 : (int) \array_key_last($all);
        $window = [
            'from' => $from,
            'to' => $to,
            'capacity' => (int) ($this->recorder->stats()['capacity'] ?? 0),
            'frames' => \count($all),
            // 帧号跨度比保留条数多出来的那段 = 静止期整段跳过的帧，被窗口让位淘汰了。
            // 不给这个数，`#12–#12573` 配 `600` 会被读成"窗口有一万两千帧"。
            'folded' => \max(0, $to - $from + 1 - \count($all)),
        ];

        $out = [];
        $dropped = 0;
        if ($since !== null) {
            // 游标落后到窗口外：中间那段真的没了，不是"这段没有重建"。
            $dropped = $window['from'] > 0 ? \max(0, $window['from'] - $since - 1) : 0;
            foreach ($all as $id => $frame) {
                if ($id <= $since) {
                    continue;
                }
                if ($rebuiltOnly && $frame->rebuilt === []) {
                    continue;
                }
                $out[] = $this->summary($frame);
            }

            // 游标语义下 `limit` 是"最多给这么多"，从**最老的未读**开始：
            // 丢弃最新的会让读者以为"就这些"，而最老的丢不掉才是真相。
            $out = $limit > 0 ? \array_slice($out, 0, $limit) : $out;
        } else {
            foreach ($this->window($limit) as $frame) {
                if ($rebuiltOnly && $frame->rebuilt === []) {
                    continue;
                }
                $out[] = $this->summary($frame);
            }
        }

        return ['frames' => $out, 'window' => $window, 'dropped' => $dropped, 'since' => $since];
    }

    /** @return array<string, mixed> */
    private function summary(FrameRecord $frame): array
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
     * 参数名与协议键同名（`frame`）：`query('explain', ['frame' => 2])` 里的键、
     * 这里的变量、`explain(2)` 的地址，三处必须是同一个词 —— 否则规格表要靠一张
     * 别名映射才能核对，而别名表本身就会漂。
     *
     * @return array<string, mixed>
     */
    public function explain(int $frame): array
    {
        $record = $this->recorder->frame($frame);
        if ($record === null) {
            throw new \InvalidArgumentException("没有这一帧：{$frame}（缓冲窗口之外或尚未发生）");
        }

        $rebuilt = [];
        foreach ($record->rebuilt as $id) {
            $rebuilt[] = ['id' => $id, 'path' => $this->recorder->pathOf($id)];
        }

        $byKind = ['state' => 0, 'parent' => 0, 'unknown' => 0];
        $byRoot = [];
        foreach ($record->causes as $edge) {
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
            'id' => $record->id,
            't' => $record->t,
            'cost' => $record->costMs,
            'stages' => $record->stages,
            'stageMs' => FrameRecord::toMs($record->stageNs),
            'unattributedMs' => $record->unattributedMs(),
            'settleSkipped' => $record->settleSkipped,
            'paintSkipped' => $record->paintSkipped,
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
    public function why(int $node, ?int $frame = null): array
    {
        return $this->recorder->why($node, $frame);
    }

    /**
     * 一个节点在**显式帧**上的因果事实（`align()` 的因果那一域）。
     *
     * 与 `why()` 的区别是刻意的：`why()` 的帧可以缺省（取"最近一次重建它的帧"），
     * 跨域对齐不能 —— 三域的缺省语义本来就不同，各自缺省会给出"三域都在 #N"的
     * 假象。所以这里帧必填，窗口外明说 `frame-out-of-window`。
     *
     * @return array<string, mixed>
     */
    public function forNode(int $node, int $frame): array
    {
        $record = $this->recorder->frame($frame);
        if ($record === null) {
            return ['found' => false, 'reason' => 'frame-out-of-window'];
        }

        $causes = [];
        foreach ($record->causes as $edge) {
            if ($edge->toId === $node) {
                $causes[] = $edge->toArray();
            }
        }

        return [
            'found' => true,
            'frame' => $record->id,
            'rebuilt' => $record->rebuilt($node),
            'causes' => $causes,
        ];
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
    public function cascade(int $frame, int $maxDepth = 5, int $maxChildren = 8): array
    {
        $record = $this->recorder->frame($frame);
        if ($record === null) {
            throw new \InvalidArgumentException("没有这一帧：{$frame}（缓冲窗口之外或尚未发生）");
        }

        $shape = $this->shapeOf($record);
        /** @var array<int, list<int>> $children */
        $children = $shape['children'];
        /** @var array<int, int> $subtree */
        $subtree = $shape['subtree'];
        $causeOf = $record->causeOf();
        $signature = $this->signatures($record, $children, $causeOf, $subtree);

        // 写入 ≠ 重建：帧跳过 settle、或元素在 settle 前被卸载时，`state` 边落在这一帧
        // 却没有对应的 rebuild。从 `rebuilt` 取会漏掉它们，而 `explain` 的 byKind 数是
        // 全部 state 边 —— 两处差一条、且没有解释。所以从 causes 取，并标明有没有落地。
        $rebuiltSet = [];
        foreach ($record->rebuilt as $id) {
            $rebuiltSet[$id] = true;
        }
        $writes = [];
        foreach ($record->causes as $edge) {
            if ($edge->kind !== 'state') {
                continue;
            }
            $id = $edge->toId;
            $writes[] = [
                'id' => $id,
                'path' => $this->recorder->pathOf($id),
                'type' => $this->recorder->typeOf($id),
                'site' => $edge->site,
                'detail' => $edge->detail,
                'subtree' => $subtree[$id] ?? 1,
                'rebuilt' => isset($rebuiltSet[$id]),
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
            'frame' => $record->id,
            'rebuilt' => \count($record->rebuilt),
            'depthMax' => (int) $shape['depthMax'],
            'roots' => $tree,
            'omitted' => $omitted,
            'hidden' => $hidden,
            'writes' => $writes,
            'byType' => $this->byType($record),
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
        // `reset()` 把帧号归零，下一轮又从 1 开始；旧骨架留在缓存里就会被同号帧命中，
        // 于是新因果配旧结构 —— 一棵自洽、可读、全错的树。代际变了先整体丢弃。
        $generation = $this->recorder->generation();
        if ($generation !== $this->cacheGeneration) {
            $this->shapeCache = [];
            $this->cacheGeneration = $generation;
        }
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
