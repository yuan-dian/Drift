<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 结构域查询：第 N 帧那棵树（谁在、谁已消失、谁这一帧重建了）。
 *
 * 只读 {@see Recorder} 的节点注册表（`nodes` / `evicted` / `goneAt`）与帧记录的重建集合，
 * 几何只作为注解从 {@see GeometryQuery} 借一次 —— 不新增逐帧结构证据（A64 / A67）。
 *
 * ## 与几何域的依赖方向是单向的
 *
 * 结构 → 几何（贴盒大小与矩形），几何不知道结构存在。反过来就成了环：几何域的
 * `lookup()` 一旦要问"这个节点那时在不在"，两个域就会互相调用，环保护只能靠深度限制。
 *
 * ## 为什么这类单独一个：它答的是"在不在"，别人答的是"为什么/多大/画了没"
 *
 * 回放的正确性只由**生命周期两端**决定（出生帧 `f`、离开帧 `g`），而"重建"必须
 * 取自该帧的 `FrameRecord->rebuilt`。这两件事都不在任何别的域里。
 *
 * @internal 门面 {@see Query} 的实现细节，不是应用开发者的 API 面
 */
final class StructureQuery
{
    public function __construct(
        private readonly Recorder $recorder,
        private readonly GeometryQuery $geometry,
    ) {
    }

    /**
     * 结构树：**第 N 帧**的树（A64 / A67）。
     *
     * ## 这不是"节点注册表的当前投影"
     *
     * 早先它是。`--frame=40` 给的是「现在所有节点」+「几何对齐到 40 帧」：第 40 帧
     * 之后才出生的节点照样列出来（几何一栏空着），第 40 帧还在、之后被卸载的节点
     * 一个都看不到。于是答案既不是那会儿的也不是现在的 —— 是两者的交集，且没有
     * 任何一栏说自己不可信。这与 A64 禁止的「拿没采到冒充不存在」是同一个错，
     * 只是方向相反：**拿现在的冒充当时的**。
     *
     * 现在节点能否出现，由它自己的生命周期决定（{@see Recorder::nodeIndex()} 的
     * `f` 与 {@see Recorder::goneNodes()}）：
     *
     * - `f > frame` ⇒ 那时还没出生，不列；
     * - `gone <= frame` ⇒ 那时已离开树，不列；
     * - 已回收节点（`evicted`）在 `f <= frame < g` 时**照样列**，标 `evicted` ——
     *   "这个按钮第 40 帧还在、第 60 帧没了"正是回放最想回答的问题，只留活节点
     *   等于把答案删了。
     *
     * 缺 `f`（旧证据包）或关了几何采集（没人写 `gone`）时**不猜**：节点仍然列出
     * （无法证明它那时不存在），但 `coverage.lifecycle.complete` 为 `false`，
     * 并给出条数。读者据此知道"这张图有一部分是保守估计"。
     *
     * ## 谁重建了这一帧
     *
     * `rebuilt` 取自该帧的 `FrameRecord->rebuilt`，不是从"最近见到帧 == 目标帧"
     * 倒推。两者在"这一帧没建、但上一帧刚建过"时正好相撞：`s` 停在上一帧，
     * 倒推会说它没重建（碰巧对），而"`s` 恰好等于目标帧"又会说它建了（其实是
     * 采样到它而已）。该帧记录不在窗口里时给 `null` —— 未知，不是 false。
     *
     * 行按**先序扁平**给：树控件按 `depth` 缩进即可，不必在前端再走一遍父子链。
     * 兄弟顺序取注册表的首次发现顺序（几何采样本来就是先序）；它不是布局顺序的
     * 承诺，只保证同一份证据每次展开顺序相同。
     *
     * 三种"父不可用"必须分开：`unresolved` 是父号还没解析，`orphan` 是父已被回收，
     * `cycle` 是父链成环。它们都挂到根上而不是静默丢 —— 树少一块而覆盖率不说，
     * 读者会以为自己看全了。
     *
     * @return array<string, mixed>
     */
    public function tree(int $root = 0, int $depth = 6, int $limit = 200, ?int $frame = null): array
    {
        $depth = \max(0, \min($depth, 32));
        $limit = \max(0, \min($limit, 2000));
        $window = $this->recorder->geometryCoverage();
        $lastFrame = $this->recorder->frameId();
        // ★ 结构与几何是**两个窗口**：帧 600、几何 60，而且几何按"有变化才落记录"
        //   跳帧。一个默认值不可能同时落在两个窗口里：
        //   · 结构默认取帧窗口最新 —— 取几何最新会让回放停在几十帧前的旧树；
        //   · 几何默认取几何窗口最新 —— 取帧最新会立刻"窗口外"，注解全空。
        //   显式 `--frame=N` 时两者都听它的（那是用户点名要的那一帧，两边必须一致）。
        $frameId = $frame ?? ($lastFrame > 0 ? $lastFrame : (int) ($window['to'] ?? 0));
        if ($frameId <= 0) {
            $frameId = $lastFrame;
        }
        // 几何帧夹到它自己的窗口里。超过 `to` 不是"这一帧没有几何"，是"这一帧之后
        // 几何没再变"——那份几何此刻仍然生效，`boxes()` 会把它标成 `stale` 并回显
        // `effective`。但**不越过 `to` 去查**：`geometryRows()` 的空表还承担着
        // "这一帧真的采过吗"的语义（快照锚点靠它选）。
        $geoTo = (int) ($window['to'] ?? 0);
        $geoFrame = $frame ?? ($geoTo > 0 ? $geoTo : $frameId);
        if ($geoTo > 0 && $geoFrame > $geoTo) {
            $geoFrame = $geoTo;
        }

        // 未来帧不给"空树"：空树看起来像"那时应用还没建"，而真相是"这一帧根本没发生"。
        // 与 `geometryAt()` 拒绝 clamp 到窗口末端是同一个理由。
        // `lastFrame === 0`（这份证据一帧都没记录）时不判：没有时间轴就没有"未来"，
        // 让它退化成纯结构投影，并由 `lifecycle.complete = false` 说明不可回放。
        if ($lastFrame > 0 && $frameId > $lastFrame) {
            return [
                'frame' => $frameId,
                'root' => $root,
                'rows' => [],
                'coverage' => [
                    'active' => \count($this->recorder->nodeIndex()),
                    'evicted' => 0,
                    'evictedTotal' => \count($this->recorder->evictedNodes()),
                    'roots' => 0, 'boxless' => 0, 'unresolved' => 0, 'orphan' => 0,
                    'cycle' => 0, 'shown' => 0, 'omittedDepth' => 0, 'omittedLimit' => 0,
                    'geometryFrame' => 0, 'geometryEffective' => 0,
                    'geometryStale' => false, 'geometryReason' => '',
                    'lifecycle' => ['goneKnown' => false, 'complete' => false, 'unknownBirth' => 0, 'unknownGone' => 0, 'recycled' => 0, 'oldestBirth' => 0],
                ],
                'reason' => 'frame-not-recorded',
            ];
        }

        // 几何只查一次，按节点贴到结构上。逐行调 boxModel() 会把一次查询变成 N 次，
        // 远端会话尤其明显；而树要的只是"有没有盒、多大"，不需要完整盒模型。
        $boxes = $this->geometry->boxes($geoFrame);
        $boxByNode = [];
        foreach ($boxes['rows'] ?? [] as $row) {
            $boxByNode[(int) $row['node']] = $row;
        }

        [$nodes, $lifecycle] = $this->replayNodes($lastFrame > 0 ? $frameId : null);
        $boxless = [];
        foreach ($this->recorder->boxlessNodes() as $id) {
            $boxless[(int) $id] = true;
        }

        // 该帧重建了谁：只有帧记录在窗口里才给结论。
        $record = $this->recorder->frame($frameId);
        $rebuiltSet = null;
        if ($record !== null) {
            $rebuiltSet = [];
            foreach ($record->rebuilt as $one) {
                $rebuiltSet[(int) $one] = true;
            }
        }

        $active = \count($nodes);
        $evicted = $this->recorder->evictedNodes();
        $coverage = static fn(): array => [
            // `active` 是**这一帧的节点数**（含当时还活着的回收节点），`evicted` 是
            // 其中已回收的条数，`evictedTotal` 才是回收表总量。三个数分开，读者才能
            // 把"树里 361 个"与"回收表 12 条"对上；混成一个就永远差一截且说不清。
            'active' => $active,
            'evicted' => (int) ($lifecycle['recycled'] ?? 0),
            'evictedTotal' => \count($evicted),
            'roots' => 0,
            'boxless' => \count($boxless),
            'unresolved' => 0,
            'orphan' => 0,
            'cycle' => 0,
            'shown' => 0,
            'omittedDepth' => 0,
            'omittedLimit' => 0,
            'geometryFrame' => (int) ($boxes['frame'] ?? 0),
            'geometryEffective' => (int) ($boxes['effective'] ?? 0),
            'geometryStale' => ($boxes['stale'] ?? false) === true,
            'geometryReason' => (string) ($boxes['reason'] ?? ''),
            'lifecycle' => $lifecycle,
        ];

        if ($root > 0 && !isset($nodes[$root])) {
            // 三件不同的事，答案必须分开：那时还没出生 / 那时已经不在 / 从来没有这个号。
            // 合成一个"查不到"会让回放最常问的"它什么时候没的"无从回答。
            $reason = 'unknown-node';
            if (isset($evicted[$root])) {
                $born = (int) ($evicted[$root]['f'] ?? 0);
                $died = (int) ($evicted[$root]['g'] ?? 0);
                $reason = $born > $frameId ? 'node-not-born' : 'node-evicted';
                if ($died > 0 && $died <= $frameId) {
                    $reason = 'node-gone';
                }
            } else {
                $known = $this->recorder->nodeIndex()[$root] ?? null;
                if ($known !== null && (int) ($known['f'] ?? 0) > $frameId) {
                    $reason = 'node-not-born';
                }
            }

            return [
                'frame' => $frameId,
                'root' => $root,
                'rows' => [],
                'coverage' => $coverage(),
                'reason' => $reason,
            ];
        }

        /** @var array<int, list<int>> $children */
        $children = [];
        /** @var list<int> $roots */
        $roots = [];
        /** @var array<int, string> $flags */
        $flags = [];
        $unresolved = 0;
        $orphan = 0;
        foreach ($nodes as $key => $meta) {
            $id = (int) $key;
            $parent = isset($meta['p']) ? (int) $meta['p'] : -2;
            if ($parent === -2) {
                ++$unresolved;
                $roots[] = $id;
                $flags[$id] = 'unresolved';
                continue;
            }
            if ($parent === -1 || !isset($nodes[$parent])) {
                if ($parent !== -1) {
                    ++$orphan;
                }
                $roots[] = $id;
                $flags[$id] = $parent === -1 ? 'root' : 'orphan';
                continue;
            }
            $children[$parent][] = $id;
            $flags[$id] = 'normal';
        }

        if ($root > 0) {
            $roots = [$root];
            $flags[$root] = 'root';
        }

        // 先算可达，再把不可达的环单独挂到根。只从 invalid-parent 根出发会让整个环
        // 消失：每个节点都有"活跃父"，谁都不是根，读者却只看到一棵看着完整的树。
        $reachable = [];
        $stack = $roots;
        while ($stack !== []) {
            $id = (int) \array_pop($stack);
            if (isset($reachable[$id])) {
                continue;
            }
            $reachable[$id] = true;
            foreach ($children[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }
        $cycles = [];
        if ($root === 0) {
            foreach ($nodes as $key => $_) {
                if (!isset($reachable[(int) $key])) {
                    $cycles[] = (int) $key;
                    $flags[(int) $key] = 'cycle';
                }
            }
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = [];
        $emitted = 0;
        $omittedDepth = 0;
        $omittedLimit = 0;
        $seen = [];
        $stack = [];
        foreach (\array_reverse(\array_merge($roots, $cycles)) as $id) {
            $stack[] = [(int) $id, 0];
        }
        while ($stack !== []) {
            /** @var array{0: int, 1: int} $task */
            $task = (array) \array_pop($stack);
            $id = $task[0];
            $level = $task[1];
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;

            $kids = $children[$id] ?? [];
            for ($i = \count($kids) - 1; $i >= 0; --$i) {
                $stack[] = [$kids[$i], $level + 1];
            }
            if ($level > $depth) {
                ++$omittedDepth;
                continue;
            }
            if ($limit > 0 && $emitted >= $limit) {
                ++$omittedLimit;
                continue;
            }

            $meta = $nodes[$id];
            $lastSeen = (int) ($meta['s'] ?? 0);
            $geo = $boxByNode[$id] ?? null;
            $rows[] = [
                'id' => $id,
                'parent' => isset($meta['p']) ? (int) $meta['p'] : -2,
                'type' => (string) ($meta['c'] ?? ''),
                'path' => $this->recorder->pathOf($id),
                'depth' => $level,
                'children' => \count($kids),
                'boxed' => !isset($boxless[$id]),
                'flex' => ($meta['x'] ?? false) === true,
                'lastSeen' => $lastSeen,
                // `age` 是"距它上一次被重建过了几帧"。回放时 `lastSeen` 可能晚于目标帧
                // （它在这一帧之后又重建过），相减为负 —— 夹到 0 而不是给个负数帧龄。
                'age' => $lastSeen > 0 ? \max(0, $frameId - $lastSeen) : 0,
                'stale' => $lastSeen > 0 && $frameId > $lastSeen,
                'flag' => $flags[$id] ?? 'normal',
                // 生命周期三件：出生帧、离开帧、这一帧有没有重建。
                // `bornAt` 为 `null` = 记录里没有（旧包），不是"第 0 帧出生"。
                'bornAt' => (int) ($meta['f'] ?? 0) > 0 ? (int) $meta['f'] : null,
                'goneAt' => (int) ($meta['g'] ?? 0) > 0 ? (int) $meta['g'] : null,
                'rebuilt' => $rebuiltSet === null ? null : isset($rebuiltSet[$id]),
                // 现在不在注册表里、但回放到这一帧时还活着 —— 画出来并标明。
                'evicted' => ($meta['e'] ?? false) === true,
                'geometry' => $geo === null
                    ? null
                    : ['size' => $geo['size'] ?? [], 'rect' => $geo['rect'] ?? []],
            ];
            ++$emitted;
        }

        $cov = $coverage();
        $cov['roots'] = \count($roots);
        $cov['unresolved'] = $unresolved;
        $cov['orphan'] = $orphan;
        $cov['cycle'] = \count($cycles);
        $cov['shown'] = \count($rows);
        $cov['omittedDepth'] = $omittedDepth;
        $cov['omittedLimit'] = $omittedLimit;

        return [
            'frame' => $frameId,
            'root' => $root,
            'rows' => $rows,
            'coverage' => $cov,
        ];
    }

    /**
     * 第 `$at` 帧的节点表：那时活着的，加上"那时还在、现在已回收"的。
     *
     * `$at === null` 表示**这份证据没有时间轴**（一帧记录都没有）—— 不过滤、原样给
     * 注册表，并让 `complete = false`。不拒绝查询（否则只带节点表的夹具与旧工具全废），
     * 也不假装这是历史树。
     *
     * 两个方向都要过滤，缺一个就会造出既不是当时也不是现在的树：
     * `f > $at` 是"还没出生"，`gone <= $at` 是"已经离开"。判据各有一条。
     *
     * 回收节点的父与类型在 {@see Recorder::evictedNodes()} 里：它们在注册表里已被
     * 删掉，但回放的主角恰恰是"后来消失了的东西"，没有父与类型就画不出它在哪儿。
     *
     * ## 不猜的两处
     *
     * - 缺 `f`（旧证据包）：无法证明"那时它不存在"，所以**保留**，计入 `unknownBirth`。
     * - 缺 `g` 且几何采集关着：没人写"离开帧"，所以**保留到帧末**，计入 `unknownGone`。
     *
     * 两处都让 `complete = false`。宁可交一张标着"部分靠估计"的图，也不交一张
     * 看起来完整、实际删掉了半个应用的图。
     *
     * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
     */
    private function replayNodes(?int $at): array
    {
        $gone = $this->recorder->goneNodes();
        $evicted = $this->recorder->evictedNodes();

        /** @var array<int, array<string, mixed>> $nodes */
        $nodes = [];
        $unknownBirth = 0;
        $oldestBirth = 0;
        foreach ($this->recorder->nodeIndex() as $id => $meta) {
            $id = (int) $id;
            $born = (int) ($meta['f'] ?? 0);
            if ($born === 0) {
                ++$unknownBirth;
            } else {
                $oldestBirth = $oldestBirth === 0 ? $born : \min($oldestBirth, $born);
                if ($at !== null && $born > $at) {
                    continue;
                }
            }
            $died = (int) ($gone[$id] ?? 0);
            if ($at !== null && $died > 0 && $died <= $at) {
                continue;
            }
            $nodes[$id] = [
                'p' => (int) ($meta['p'] ?? -2),
                'c' => (string) ($meta['c'] ?? ''),
                'x' => ($meta['x'] ?? false) === true,
                's' => (int) ($meta['s'] ?? 0),
                'f' => $born,
                'g' => $died,
                'e' => false,
            ];
        }

        $recycled = 0;
        $unknownGone = 0;
        foreach ($evicted as $id => $meta) {
            $id = (int) $id;
            $born = (int) ($meta['f'] ?? 0);
            $died = (int) ($meta['g'] ?? 0);
            if ($born === 0) {
                ++$unknownBirth;
            } elseif ($at !== null && $born > $at) {
                continue;
            }
            if ($at !== null && $died > 0 && $died <= $at) {
                continue;
            }
            if ($died === 0) {
                ++$unknownGone;
            }
            // 活节点那条路径已经写过它就别覆盖：两边都有时以注册表为准（它更新）。
            if (isset($nodes[$id])) {
                continue;
            }
            $nodes[$id] = [
                'p' => (int) ($meta['p'] ?? -2),
                'c' => (string) ($meta['c'] ?? ''),
                'x' => ($meta['x'] ?? false) === true,
                's' => (int) ($meta['s'] ?? 0),
                'f' => $born,
                'g' => $died,
                'e' => true,
            ];
            ++$recycled;
        }

        return [$nodes, [
            // `goneKnown` 单列：几何采集关着时 `goneAt` 从来没人写，"没有节点离开过"
            // 与"根本不知道谁离开过"在空表上看不出来 —— 而后者会让回放把整棵已经
            // 卸载的树一直画到帧末。
            'goneKnown' => $gone !== [] || $this->recorder->options()->geometry() !== GeometryCapture::Off,
            // 没有时间轴（一帧都没记录）时整张表都不可判：不是"完整"，是"无帧可回放"。
            'complete' => $at !== null
                && $unknownBirth === 0
                && $unknownGone === 0
                && ($gone !== [] || $this->recorder->options()->geometry() !== GeometryCapture::Off),
            'unknownBirth' => $unknownBirth,
            'unknownGone' => $unknownGone,
            'recycled' => $recycled,
            'oldestBirth' => $oldestBirth,
        ]];
    }
}
