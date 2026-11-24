<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Application;
use Drift\Debug\Session\Query;
use Drift\Debug\Session\Recorder;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Align;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\Padding;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * D1 判据：查询面要能回答"谁在反复重建"，且两个聚合口径不能混。
 *
 * ## 钉什么
 *
 * - `frames()` 的窗口、顺序与过滤；
 * - `explain()` 把根因与传播分开，并给出可读路径；
 * - `hotspots()` 只算 `kind=state`，`nodes()` 才把父传播算进去；
 * - 不存在的帧**诚实报错**，不返回空结构冒充"没变化"。
 *
 * ## 为什么不钉具体路径字符串
 *
 * 路径由 Widget 名拼成，改名是正常演进。这里只钉"路径里包含那个 Widget 名"，
 * 让判据守语义（这是哪个节点），不守拼写。
 */
final class QueryTest extends SmokeTestCase
{
    private function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(200.0, 120.0));
    }

    private function boot(): Application
    {
        $app = new QueryApplication();
        $app->attach(new SurfaceProbe(), $this->constraints());

        return $app;
    }

    public function testFramesAndExplainSplitRootFromPropagation(): void
    {
        $app = $this->boot();
        // ★ 先 attach 再装录制器：`attach()` 自己会跑首帧，装早了就把那一帧也算进来，
        //   和 `DebugSession::open()`（要求已 attach）的起点对不上。
        $recorder = Recorder::install();

        try {
            $app->frame(0.0, $this->constraints());   // 1：挂载，不产生重建
            QueryState::$last?->increment();
            $app->frame(16.0, $this->constraints());  // 2：自己 setState
            QueryState::$last?->increment();
            $app->frame(32.0, $this->constraints());  // 3：同上

            $query = new Query($recorder);

            $rows = $query->frames()['frames'];
            check(
                '帧序升序、三帧都在',
                \array_column($rows, 'id') === [1, 2, 3],
                'ids=' . \json_encode(\array_column($rows, 'id')),
            );
            check(
                '首帧没有重建（挂载走 build，不经 rebuild）',
                $rows[0]['rebuilt'] === 0,
                'rebuilt=' . $rows[0]['rebuilt'],
            );
            check(
                '重建帧里既有自身也有子节点',
                $rows[1]['rebuilt'] === 2,
                'rebuilt=' . $rows[1]['rebuilt'],
            );
            check(
                'rebuiltOnly 过滤掉静止帧',
                \count($query->frames(30, true)['frames']) === 2,
                'rows=' . \count($query->frames(30, true)['frames']),
            );

            $explain = $query->explain(2);
            check(
                '根因只有一条 state 边',
                $explain['byKind']['state'] === 1,
                'byKind=' . \json_encode($explain['byKind']),
            );
            check(
                '子节点是 parent 传播，不算根因',
                $explain['byKind']['parent'] === 1,
                'byKind=' . \json_encode($explain['byKind']),
            );
            check(
                'roots 指向业务调用点',
                \str_contains((string) ($explain['roots'][0]['site'] ?? ''), 'QueryTest.php'),
                'roots=' . \json_encode($explain['roots']),
            );
            check(
                'rebuilt 带可读路径',
                \str_contains((string) ($explain['rebuilt'][0]['path'] ?? ''), 'QueryProbe'),
                'rebuilt=' . \json_encode($explain['rebuilt']),
            );

            $threw = false;
            try {
                $query->explain(999);
            } catch (\InvalidArgumentException) {
                $threw = true;
            }
            check('窗口外的帧诚实报错，不返回空结构', $threw);
        } finally {
            Recorder::uninstall();
        }
    }

    public function testHotspotsRankRootsAndNodesSeparateSelfFromInherited(): void
    {
        $app = $this->boot();
        // ★ 先 attach 再装录制器：`attach()` 自己会跑首帧，装早了就把那一帧也算进来，
        //   和 `DebugSession::open()`（要求已 attach）的起点对不上。
        $recorder = Recorder::install();

        try {
            $app->frame(0.0, $this->constraints());
            for ($i = 0; $i < 3; ++$i) {
                QueryState::$last?->increment();
                $app->frame(16.0 * ($i + 1), $this->constraints());
            }

            $query = new Query($recorder);

            $hotspots = $query->hotspots(0, 5);
            check('只聚出一条根因', \count($hotspots) === 1, 'rows=' . \json_encode($hotspots));
            check(
                '写入次数 = 3、涉及 3 帧、1 个节点',
                ($hotspots[0]['writes'] ?? 0) === 3
                    && ($hotspots[0]['frames'] ?? 0) === 3
                    && ($hotspots[0]['nodes'] ?? 0) === 1,
                \json_encode($hotspots[0] ?? []),
            );
            check(
                '根因带 State 类名',
                ($hotspots[0]['detail'] ?? '') === 'QueryState',
                \json_encode($hotspots[0] ?? []),
            );

            $nodes = $query->nodes(0, 5);
            check('两个节点各重建 3 次', \count($nodes) === 2, 'rows=' . \json_encode($nodes));

            $root = null;
            $child = null;
            foreach ($nodes as $row) {
                if ($row['self'] > 0) {
                    $root = $row;
                } elseif ($row['inherited'] > 0) {
                    $child = $row;
                }
            }
            check(
                '自身写入的节点 self=3 / inherited=0',
                $root !== null && $root['rebuilds'] === 3 && $root['self'] === 3 && $root['inherited'] === 0,
                \json_encode($root),
            );
            check(
                '被传播的节点 self=0 / inherited=3',
                $child !== null && $child['rebuilds'] === 3 && $child['self'] === 0 && $child['inherited'] === 3,
                \json_encode($child),
            );
        } finally {
            Recorder::uninstall();
        }
    }

    public function testWindowLimitsHowFarBackAggregatesLook(): void
    {
        $app = $this->boot();
        // ★ 先 attach 再装录制器：`attach()` 自己会跑首帧，装早了就把那一帧也算进来，
        //   和 `DebugSession::open()`（要求已 attach）的起点对不上。
        $recorder = Recorder::install();

        try {
            $app->frame(0.0, $this->constraints());
            QueryState::$last?->increment();
            $app->frame(16.0, $this->constraints());
            $app->frame(32.0, $this->constraints());
            $app->frame(48.0, $this->constraints());

            $query = new Query($recorder);

            check(
                '窗口只看最近两帧时，聚合里没有那次写入',
                $query->hotspots(2, 5) === [],
                'rows=' . \json_encode($query->hotspots(2, 5)),
            );
            check(
                '看全窗口时那次写入在',
                \count($query->hotspots(0, 5)) === 1,
                'rows=' . \json_encode($query->hotspots(0, 5)),
            );
        } finally {
            Recorder::uninstall();
        }
    }

    /**
     * 增量游标：`since=N` 只要帧号大于 N 的帧。
     *
     * ## 钉两件事，缺一条就变成"两种实现都绿"
     *
     * 1. **筛选**：`since=2` 拿到的必须从第 3 帧开始（不是"最近 N 帧"）。
     * 2. **不合并**：`since=0` 是"从头给"，而"没给 since"是"给最近 N 帧"。
     *    两者用同一个默认值合并，"从头取"会静默退化成"取最近的"，差集正好是
     *    历史帧 —— 报告看起来完整，少的那些帧没人发现。
     */
    public function testSinceCursorIsNotTheSameAsRecentWindow(): void
    {
        $app = $this->boot();
        $recorder = Recorder::install();

        try {
            $app->frame(0.0, $this->constraints());
            QueryState::$last?->increment();
            $app->frame(16.0, $this->constraints());
            $app->frame(32.0, $this->constraints());
            $app->frame(48.0, $this->constraints());

            $query = new Query($recorder);

            $ids = static fn(array $rows): array => \array_map(static fn(array $r): int => (int) $r['id'], $rows);

            check('从头给是全部四帧', $ids($query->frames(0, false, 0)['frames']) === [1, 2, 3, 4], \json_encode($ids($query->frames(0, false, 0)['frames'])));
            check(
                '游标只给更新的帧',
                $ids($query->frames(0, false, 2)['frames']) === [3, 4],
                \json_encode($ids($query->frames(0, false, 2)['frames'])),
            );
            check(
                '游标在最新帧之后 ⇒ 空（不是退回"最近 N 帧"）',
                $query->frames(0, false, 4)['frames'] === [],
                \json_encode($query->frames(0, false, 4)['frames']),
            );
            check(
                '窗口自报：from / to / 容量',
                $query->frames(0, false, 0)['window'] === ['from' => 1, 'to' => 4, 'capacity' => 600, 'frames' => 4],
                \json_encode($query->frames(0, false, 0)['window']),
            );
            check(
                '游标在窗口内时 dropped 为 0',
                $query->frames(0, false, 2)['dropped'] === 0,
                'dropped=' . $query->frames(0, false, 2)['dropped'],
            );
            // 没有 since 时 limit 是"最近 N 帧"；两者在 limit 恰好等于剩余帧数时
            // **看起来一样**，所以真正的区分点是上面"从头给"那一条：
            // `since=0` 给全部，而不给 since 只给最近 N 帧。
            check(
                '无游标时是最近 N 帧',
                $ids($query->frames(2)['frames']) === [3, 4],
                \json_encode($ids($query->frames(2)['frames'])),
            );
            check(
                '游标与过滤叠加：只要重建过的未读帧',
                $ids($query->frames(0, true, 1)['frames']) === [2],
                \json_encode($ids($query->frames(0, true, 1)['frames'])),
            );
        } finally {
            Recorder::uninstall();
        }
    }

    /**
     * S-live 判据 5：游标落在窗口外时，`dropped` 说清裁了多少，**不返回空数组**。
     *
     * 这条防的是"把被裁掉读成那时没有"。两者的表现一样（都是查不到帧），后果相反：
     * 前者该提示"你看太慢了，中间没了"，后者是"这段时间确实没动"。
     */
    public function testCursorBehindTheWindowReportsWhatWasDropped(): void
    {
        $app = $this->boot();
        $recorder = Recorder::install();

        try {
            $capacity = (int) ($recorder->stats()['capacity'] ?? 0);
            $total = $capacity + 100;

            $app->frame(0.0, $this->constraints());
            for ($i = 1; $i < $total; ++$i) {
                $app->frame($i * 16.0, $this->constraints());
            }

            $page = (new Query($recorder))->frames(0, false, 0);
            $from = $total - $capacity + 1;

            check('窗口被撑到容量上限', $page['window']['frames'] === $capacity, (string) $page['window']['frames']);
            check('窗口从最新的那一段开始', $page['window']['from'] === $from, (string) $page['window']['from']);
            check('被裁掉的条数如实给出', $page['dropped'] === $total - $capacity, (string) $page['dropped']);
            check(
                '不返回空数组 —— 空会被读成「那时没有」',
                \count($page['frames']) === $capacity,
                'count=' . \count($page['frames']),
            );
        } finally {
            Recorder::uninstall();
        }
    }

    /**
     * 排序判据：构造"路径序"与"子树序"给出**相反**答案的结构。
     *
     * `FanOutState` 下先挂 `Align`（名字靠前、子树小），再挂内层 `Column`
     * （名字靠后、子树大）。次数全是 1 时，路径先序会把 `Align` 排前面；
     * 按覆盖子树排才会把内层 `Column` 排前面。两种实现因此可区分 ——
     * 没有这一条，改排序就是在写一条两种实现都绿的假判据。
     */
    public function testNodesRankBySubtreeWhenRebuildCountsTie(): void
    {
        [$query, $frame] = $this->driveFanOut();

        $rows = $query->nodes(0, 0);
        $indexOf = static function (string $needle) use ($rows): int {
            foreach ($rows as $i => $row) {
                if (\str_ends_with((string) $row['path'], $needle)) {
                    return (int) $i;
                }
            }

            return -1;
        };

        $col = $indexOf('> Column > Column');
        $align = $indexOf('> Column > Align');
        check('两个分支都被聚合到', $col >= 0 && $align >= 0, "col={$col} align={$align}");
        if ($col < 0 || $align < 0) {
            return;
        }

        check(
            '这一帧次数无法区分（每节点各 1 次）',
            $rows[$col]['rebuilds'] === 1 && $rows[$align]['rebuilds'] === 1,
            'col=' . $rows[$col]['rebuilds'] . ' align=' . $rows[$align]['rebuilds'],
        );
        check(
            '内层 Column 的覆盖子树确实更大',
            $rows[$col]['subtree'] > $rows[$align]['subtree'],
            'col=' . $rows[$col]['subtree'] . ' align=' . $rows[$align]['subtree'],
        );
        check(
            '次数并列时按覆盖子树排，而不是路径先序',
            $col < $align,
            "col={$col} align={$align}（路径序会让 Align 在前）",
        );
        check(
            '结构根的覆盖等于整帧重建数',
            $rows[0]['subtree'] === \count($frame->rebuilt),
            'root=' . $rows[0]['subtree'] . ' rebuilt=' . \count($frame->rebuilt),
        );
    }

    public function testCascadeIsAForestCoveringEveryRebuiltNode(): void
    {
        [$query, $frame] = $this->driveFanOut();

        $cascade = $query->cascade($frame->id);
        check('只有一个结构根', \count($cascade['roots']) === 1, 'roots=' . \count($cascade['roots']));
        check(
            '森林覆盖全部重建节点（每组按份数算，加回被截断的）',
            $this->coverage($cascade) === \count($frame->rebuilt),
            'coverage=' . $this->coverage($cascade) . ' rebuilt=' . \count($frame->rebuilt),
        );
        $broken = $this->brokenInvariants($cascade['roots']);
        check('逐节点不变量成立（没丢子树、没重复计数）', $broken === [], \implode('；', $broken));

        $writes = \array_filter(
            $this->walkNodes($cascade['roots']),
            static fn(array $row): bool => (string) $row['node']['kind'] === 'state',
        );
        check('state 写入点在树里被标出来', \count($writes) === 1, 'writes=' . \count($writes));
        if ($writes !== []) {
            check(
                '写入点带调用点，不用再猜',
                \str_contains((string) \array_values($writes)[0]['node']['site'], 'QueryTest.php'),
                'site=' . (string) \array_values($writes)[0]['node']['site'],
            );
        }

        $types = 0;
        foreach ($cascade['byType'] as $row) {
            $types += (int) $row['nodes'];
        }
        check('按类型聚合覆盖全部重建节点', $types === \count($frame->rebuilt), "types={$types}");

        $shallow = $query->cascade($frame->id, 1, 8);
        $kid = $shallow['roots'][0]['children'][0] ?? null;
        check(
            '超过 maxDepth 标 cut，且把没展开的规模算准',
            $kid !== null && (bool) $kid['cut'] === true
                && (int) $kid['omitted'] > 0
                && (int) $kid['hidden'] === (int) $kid['subtree'] - 1,
            'kid=' . \json_encode($kid === null ? null : ['cut' => $kid['cut'], 'omitted' => $kid['omitted'], 'hidden' => $kid['hidden']]),
        );
        // 截断之后"覆盖量仍然等于 rebuilt"：这正是 hidden 存在的理由 —— 树被截了，
        // 账不能跟着糊。
        check(
            '截断后覆盖量仍然等于重建数',
            $this->coverage($shallow) === \count($frame->rebuilt),
            'coverage=' . $this->coverage($shallow),
        );

        $narrow = $query->cascade($frame->id, 5, 1);
        $col = $narrow['roots'][0]['children'][0] ?? null;
        check(
            '超出 maxChildren 记 omitted，不静默丢分支',
            $col !== null && (int) $col['omitted'] > 0,
            'col=' . \json_encode($col === null ? null : ['omitted' => $col['omitted'], 'hidden' => $col['hidden']]),
        );
        check(
            '窄宽度下覆盖量也还等于重建数',
            $this->coverage($narrow) === \count($frame->rebuilt),
            'coverage=' . $this->coverage($narrow),
        );
    }

    /**
     * 折叠的四条判据，各自钉一个设计决定：
     *
     * 1. 真同形的兄弟折成一组（`Padding ×3`），且只在**同一父**的孩子之间折；
     * 2. 同类型 + 同规模但孩子数不同 ⇒ 不折；
     * 3. 同类型 + 同规模 + **孩子数也相同**、只有结构不同 ⇒ 不折。这条挡的是
     *    "按类型 + 规模 + 孩子数分组"——它看起来完全够用，直到遇到真的递归差异；
     * 4. 同形但**因果不同** ⇒ 不折。挡的是指纹里不带 kind/site 的实现：那种实现
     *    会把一次 state 写入折进一堆父传播里，读者就会以为两处都在写。
     *
     * 四条必须在同一个夹具里：分开测时"折对了"和"折过头了"能各自绿，
     * 而真正的风险是同一棵树里两者同时发生。
     */
    public function testIsomorphicSiblingsFoldButStructureAndCauseKeepThemApart(): void
    {
        $app = new TwinApplication();
        $app->attach(new SurfaceProbe(), $this->constraints());
        $recorder = Recorder::install();

        try {
            $app->frame(0.0, $this->constraints());
            TwinState::$last?->bump();
            ArmedState::$all[0]->bump();
            $app->frame(16.0, $this->constraints());

            $frame = $recorder->frame($recorder->lastFrameId());
            check('拿到重建帧', $frame !== null && $frame->rebuilt !== [], 'frame=' . \json_encode($frame?->rebuilt));
            if ($frame === null) {
                return;
            }

            $query = new Query($recorder);
            $cascade = $query->cascade($frame->id);
            $rows = $this->walkNodes($cascade['roots']);
            $pick = static function (string $type, int $subtree, ?int $depth = null) use ($rows): array {
                return \array_values(\array_filter(
                    $rows,
                    static fn(array $row): bool => (string) $row['node']['type'] === $type
                        && (int) $row['node']['subtree'] === $subtree
                        && ($depth === null || \substr_count($row['path'], '/') === $depth),
                ));
            };

            $padding = \array_values(\array_filter(
                $rows,
                static fn(array $row): bool => (string) $row['node']['type'] === 'Padding',
            ));
            $folded = \array_values(\array_filter(
                $padding,
                static fn(array $row): bool => (int) $row['node']['count'] > 1,
            ));
            $counts = \array_map(static fn(array $row): int => (int) $row['node']['count'], $folded);
            \sort($counts);
            // 两个折叠组，各在自己的父下面：`Padding ×3`（三份同形兄弟）与 `Padding ×2`。
            // 它们是不同的父的孩子 —— 折叠只在同一父的孩子之间发生，不跨父合并。
            check('同形兄弟在同一父下各折一组', $counts === [2, 3], 'counts=' . \json_encode($counts));
            // 全帧 6 份 Padding：3 同形 + 1（嵌在 Column 里）+ 2（另一对同形）。
            check(
                '六份 Padding 一共只占三行',
                \array_sum(\array_map(static fn(array $row): int => (int) $row['node']['count'], $padding)) === 6,
                'instances=' . \array_sum(\array_map(static fn(array $row): int => (int) $row['node']['count'], $padding)),
            );
            $three = \array_values(\array_filter(
                $folded,
                static fn(array $row): bool => (int) $row['node']['count'] === 3,
            ));
            if ($three !== []) {
                $node = $three[0]['node'];
                check(
                    '折叠给出全部真实 id（可展开、可 --why）',
                    \count($node['ids'] ?? []) === 3 && \count(\array_unique($node['ids'])) === 3,
                    'ids=' . \json_encode($node['ids'] ?? null),
                );
            }

            // 两个子树规模一样、类型一样（3 个节点的 Column），但一个是 [Text,Text]、
            // 一个是 [Padding[Text]]。折成一组就是在报告里编造结构。
            // 只看根的直接孩子（第 3 层）：更深处的同型 Column 是另一个父的孩子，
            // 折不到一起，混进来会让"几行"这个数失去意义。
            $columns = $pick('Column', 3, 3);
            check('同类型同规模但结构不同的两个 Column 各自成行', \count($columns) === 2, 'groups=' . \count($columns));
            foreach ($columns as $row) {
                check(
                    '它们都没有被折叠（count=1）',
                    (int) $row['node']['count'] === 1,
                    $row['path'] . ' count=' . $row['node']['count'],
                );
            }

            // 这一对连孩子数都相同（各 2 个），只有孩子的结构不同。少了递归指纹，
            // 它们会被折成 `Column ×2` —— 而"按类型 + 规模 + 孩子数分组"看起来
            // 完全够用，正是这种"看起来够用"要在判据里堵住。
            $deep = $pick('Column', 5, 3);
            check('同类型、同规模、同孩子数但结构不同的两个 Column 也各自成行', \count($deep) === 2, 'groups=' . \count($deep));
            foreach ($deep as $row) {
                check(
                    '它们也没有被折叠',
                    (int) $row['node']['count'] === 1,
                    $row['path'] . ' count=' . $row['node']['count'],
                );
            }

            // 结构一样、因果不同：一个 state 写入，一个只是被父带着重建。
            $armed = $pick('ArmedWidget', 2);
            check('同形但因果不同的两个节点各自成行', \count($armed) === 2, 'groups=' . \count($armed));
            $kinds = \array_map(static fn(array $row): string => (string) $row['node']['kind'], $armed);
            \sort($kinds);
            check('一个标成 state、一个标成 parent', $kinds === ['parent', 'state'], 'kinds=' . \json_encode($kinds));

            $broken = $this->brokenInvariants($cascade['roots']);
            check('折叠夹具上不变量同样成立', $broken === [], \implode('；', $broken));

            // 窄宽度挤掉三组，其中一组本身就是折叠过的 `Padding ×3`。`omitted` 与
            // `hidden` 都按**实例**计（5 支 / 10 个节点），不是按组计（3 组）——
            // 两个数单位不一致的话，"另 N 支（M 个节点）"这句话就没法读。
            $narrow = $query->cascade($frame->id, 6, 4);
            $wide = $narrow['roots'][0]['children'][0] ?? null;
            check(
                'omitted 与 hidden 都按实例计',
                $wide !== null && (int) $wide['omitted'] === 5 && (int) $wide['hidden'] === 10,
                'omitted=' . (int) ($wide['omitted'] ?? -1) . ' hidden=' . (int) ($wide['hidden'] ?? -1),
            );
            check(
                '窄宽度下账还平',
                $this->coverage($narrow) === \count($frame->rebuilt),
                'coverage=' . $this->coverage($narrow) . ' rebuilt=' . \count($frame->rebuilt),
            );
        } finally {
            Recorder::uninstall();
        }
    }

    public function testWhyTrailWalksFromNodeToStateRoot(): void
    {
        [$query, $frame] = $this->driveFanOut();

        $cascade = $query->cascade($frame->id);
        [$flat] = $this->flatten($cascade['roots']);
        // 先序分配 id ⇒ 最深节点的 id 最大（本夹具只有一个分支链，够用）。
        $deep = null;
        foreach ($flat as $node) {
            if ($deep === null || (int) $node['id'] > $deep) {
                $deep = (int) $node['id'];
            }
        }
        check('找到一个深层节点', $deep !== null, 'flat=' . \count($flat));

        $why = $query->why((int) $deep, $frame->id);
        /** @var list<array<string, mixed>> $trail */
        $trail = $why['trail'];
        check('trail 至少两级（自己 + 根）', \count($trail) >= 2, 'trail=' . \json_encode($trail));
        if ($trail === []) {
            return;
        }

        $last = $trail[\count($trail) - 1];
        check('trail 以 state 写入收尾', (string) $last['kind'] === 'state', 'last=' . \json_encode($last));
        check(
            'trail 的根是那层有状态父节点',
            \str_contains((string) $last['path'], 'FanOutProbe'),
            'last=' . (string) $last['path'],
        );
        check(
            'trail 长度 == 路径深度 + 1（中间没跳级）',
            \count($trail) === \substr_count((string) $why['path'], ' > ') + 1,
            'trail=' . \count($trail) . ' path=' . (string) $why['path'],
        );

        $parentOf = $frame->parentOf();
        $linked = true;
        for ($i = 0; $i < \count($trail) - 1; ++$i) {
            if (($parentOf[(int) $trail[$i]['id']] ?? null) !== (int) $trail[$i + 1]['id']) {
                $linked = false;
                break;
            }
        }
        check('trail 每一步都是真实父边', $linked);
    }

    /**
     * `why` 不指定帧时取**最近一次重建它的帧**，不是最后一帧。
     *
     * 多动作场景的最后一帧常是静止帧；按最后一帧回答会得到"它没有重建"，
     * 把用户的问题原样退回来。
     */
    public function testWhyDefaultsToLatestRebuildingFrame(): void
    {
        $app = new FanOutApplication();
        $app->attach(new SurfaceProbe(), $this->constraints());
        $recorder = Recorder::install();

        try {
            $app->frame(0.0, $this->constraints());   // 1：挂载
            FanOutState::$last?->bump();
            $app->frame(16.0, $this->constraints());  // 2：整树重建
            $app->frame(32.0, $this->constraints());  // 3：静止

            $frame = $recorder->frame(2);
            check('第 2 帧记录存在', $frame !== null && $frame->rebuilt !== []);
            $deep = $frame === null ? 0 : (int) $frame->rebuilt[\count($frame->rebuilt) - 1];
            check('拿到一个重建节点', $deep > 0, 'id=' . $deep);

            $why = (new Query($recorder))->why($deep);
            check(
                '默认落在最近一次重建的帧（2），不是最后一帧（3）',
                (int) ($why['frame'] ?? 0) === 2 && (bool) $why['rebuilt'] === true,
                'frame=' . (string) ($why['frame'] ?? '-') . ' rebuilt=' . \var_export($why['rebuilt'], true),
            );
        } finally {
            Recorder::uninstall();
        }
    }

    /**
     * 驱动分叉夹具到"一帧内全树重建"，返回查询面与该帧记录。
     *
     * @return array{0: Query, 1: \Drift\Debug\Session\FrameRecord}
     */
    private function driveFanOut(): array
    {
        $app = new FanOutApplication();
        $app->attach(new SurfaceProbe(), $this->constraints());
        $recorder = Recorder::install();

        try {
            $app->frame(0.0, $this->constraints());
            FanOutState::$last?->bump();
            $app->frame(16.0, $this->constraints());

            $frame = $recorder->frame($recorder->lastFrameId());
            check('驱动出重建帧', $frame !== null && $frame->rebuilt !== [], 'frame=' . $recorder->lastFrameId());

            return [new Query($recorder), $frame];
        } finally {
            Recorder::uninstall();
        }
    }

    /**
     * "自己写了状态的节点"仍然是父的孩子，不另起一棵树。
     *
     * 旧实现只从 `kind=parent` 的边建父子关系，于是写入点在树里没有父，被画成第二个
     * 结构根：表格页看起来是两个并行的根（`PageShell 18` 与 `Table 342`），而 Table
     * 明明就在 PageShell 的子树里。两个数都是真的，错的是"并行"这个读法 ——
     * 而它恰好发生在最该看清嵌套的地方（两次写入谁在谁里面）。
     */
    public function testSelfWritingNodeStaysInsideItsParentsSubtree(): void
    {
        $app = new TwinApplication();
        $app->attach(new SurfaceProbe(), $this->constraints());
        $recorder = Recorder::install();

        try {
            $app->frame(0.0, $this->constraints());
            TwinState::$last?->bump();
            ArmedState::$all[0]->bump();
            $app->frame(16.0, $this->constraints());

            $frame = $recorder->frame($recorder->lastFrameId());
            if ($frame === null) {
                check('拿到重建帧', false, 'frame=null');

                return;
            }

            $cascade = (new Query($recorder))->cascade($frame->id);
            check('只有一个结构根（写入点没有另起一棵）', \count($cascade['roots']) === 1, 'roots=' . \count($cascade['roots']));
            check(
                '根覆盖整帧：写入点被算在父的子树里',
                $this->coverage($cascade) === \count($frame->rebuilt),
                'coverage=' . $this->coverage($cascade) . ' rebuilt=' . \count($frame->rebuilt),
            );

            $state = \array_values(\array_filter(
                $this->walkNodes($cascade['roots']),
                static fn(array $row): bool => (string) $row['node']['type'] === 'ArmedWidget'
                    && (string) $row['node']['kind'] === 'state',
            ));
            check('找到那个自己写了状态的节点', \count($state) === 1, 'found=' . \count($state));
            if ($state !== []) {
                check(
                    '它在树的第 3 层，不是根（`/根/列/它`）',
                    \substr_count($state[0]['path'], '/') === 3,
                    'path=' . $state[0]['path'],
                );
                check(
                    '它的父是重建过的 Column，不是空',
                    \str_contains($state[0]['path'], 'Column'),
                    'path=' . $state[0]['path'],
                );
            }
        } finally {
            Recorder::uninstall();
        }
    }

    /**
     * 把树摊平：`[id => node]` 与访问到的节点数。
     *
     * @param list<array<string, mixed>> $roots
     * @return array{0: array<int, array<string, mixed>>, 1: int}
     */
    private function flatten(array $roots): array
    {
        $flat = [];
        $stack = $roots;
        while ($stack !== []) {
            $node = \array_pop($stack);
            $flat[(int) $node['id']] = $node;
            foreach ($node['children'] as $kid) {
                $stack[] = $kid;
            }
        }

        return [$flat, \count($flat)];
    }

    /**
     * 把折叠树摊平，每个节点带上"从根到它的形状路径"。
     *
     * 不再用 id 作键：折叠之后一行代表多个 id，id 当坐标会误导 —— 这正是折叠要说的事。
     *
     * @param list<array<string, mixed>> $roots
     * @return list<array{node: array<string, mixed>, path: string}>
     */
    private function walkNodes(array $roots, string $path = ''): array
    {
        $out = [];
        foreach ($roots as $node) {
            $at = $path . '/' . (string) $node['type'] . '×' . (int) $node['count'];
            $out[] = ['node' => $node, 'path' => $at];
            foreach ($this->walkNodes($node['children'], $at) as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * 森林的覆盖量：每组按份数计，加上被截断的部分。
     *
     * 等于 `rebuilt` 才说明报告没有漏节点。截断之后这一条仍然必须成立 ——
     * 这是 `hidden` 唯一的用途：树可以少显示，账不能对不上。
     *
     * @param array<string, mixed> $cascade
     */
    private function coverage(array $cascade): int
    {
        $sum = (int) $cascade['hidden'];
        foreach ($cascade['roots'] as $root) {
            $sum += (int) $root['subtree'] * (int) $root['count'];
        }

        return $sum;
    }

    /**
     * `reset()` 之后，旧骨架不许被同号帧命中。
     *
     * 骨架缓存的键是**帧号**，而 `reset()` 把帧号归零 —— 下一轮又从 1 开始。缓存
     * 不清时，新记录会配上旧结构，`cascade` 于是交出一棵**自洽、可读、全错**的树：
     * 每个子树规模都算得通，因为那棵树确实存在过，只是不在这一轮。这种错没有异常、
     * 没有告警，只有"报告和刚才那次运行长得一模一样"这一个线索。
     *
     * 判据用最硬的一对矛盾：第二轮什么都没写，`rebuilt` 必须是 0；若缓存泄漏，
     * `roots` 仍是非空的旧森林 —— 0 个重建却有一棵树，除了缓存没有别的解释。
     */
    public function testSkeletonCacheDoesNotSurviveAReset(): void
    {
        $app = $this->boot();
        $recorder = Recorder::install();

        try {
            $app->frame(0.0, $this->constraints());   // 1：挂载
            QueryState::$last?->increment();
            $app->frame(16.0, $this->constraints());  // 2：一次写入 → 建骨架

            $query = new Query($recorder);
            $before = $query->cascade(2);
            check(
                '第一轮确实建了骨架（否则这条判据没测到东西）',
                $before['rebuilt'] > 0,
                'rebuilt=' . $before['rebuilt'],
            );

            $recorder->reset();

            $app->frame(32.0, $this->constraints());  // 又是 1：挂载帧
            $app->frame(48.0, $this->constraints());  // 又是 2：没有任何写入

            $after = $query->cascade(2);
            check('第二轮同号帧没有重建', $after['rebuilt'] === 0, 'rebuilt=' . $after['rebuilt']);
            check(
                '旧骨架不许被同号帧命中',
                $after['roots'] === [],
                'roots=' . \json_encode(\array_map(
                    static fn(array $r): string => (string) $r['type'] . '×' . (int) $r['count'],
                    $after['roots'],
                )),
            );
        } finally {
            Recorder::uninstall();
        }
    }

    /**
     * 逐节点检查三条不变量，返回不成立的地方（空表示全过）。
     *
     * 1. `subtree == 1 + hidden + Σ(子.subtree × 子.count)`：折叠没丢子树、没重复计数；
     * 2. 组的 `ids` 个数等于 `count`，单节点不带 `ids`（折叠无损且有唯一写法）；
     * 3. 截断处的 `hidden == subtree - 1`：没展开的部分就是除了自己以外的一切。
     *
     * 比"数出来等于 11"强的地方在于：它不依赖具体夹具的规模，换个页面照样成立，
     * 而任何一处漏算都会让等式不成立。
     *
     * @param list<array<string, mixed>> $roots
     * @return list<string>
     */
    private function brokenInvariants(array $roots): array
    {
        $bad = [];
        $walk = function (array $node, string $path) use (&$walk, &$bad): void {
            $at = $path . '/' . (string) $node['type'] . '×' . (int) $node['count'];
            $count = (int) $node['count'];
            if ($count > 1) {
                $ids = $node['ids'] ?? null;
                if (!\is_array($ids) || \count($ids) !== $count) {
                    $bad[] = "{$at}: ids 与 count 不一致";
                }
            } elseif (isset($node['ids'])) {
                $bad[] = "{$at}: 单节点不该带 ids";
            }
            if ((bool) $node['cut']) {
                if ((int) $node['hidden'] !== (int) $node['subtree'] - 1) {
                    $bad[] = "{$at}: cut 处 hidden != subtree-1";
                }

                return;
            }

            $sum = 1 + (int) $node['hidden'];
            foreach ($node['children'] as $kid) {
                $sum += (int) $kid['subtree'] * (int) $kid['count'];
                $walk($kid, $at);
            }
            if ($sum !== (int) $node['subtree']) {
                $bad[] = "{$at}: subtree={$node['subtree']}，但 1+hidden+Σ子={$sum}";
            }
        };

        foreach ($roots as $root) {
            $walk($root, '');
        }

        return $bad;
    }
}

/** @internal 判据夹具：一层有状态父节点 + 一个由父产生的子节点 */
final class QueryApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Column::make()->children([new QueryProbe()]);
    }
}

/** @internal 判据夹具 */
final class QueryProbe extends StatefulWidget
{
    public function createState(): State
    {
        return new QueryState();
    }
}

/** @internal 判据夹具：`build()` 每次产出新的 `Text`，于是子元素走 update → rebuild */
final class QueryState extends State
{
    public static ?self $last = null;

    public int $count = 0;

    public function initState(): void
    {
        self::$last = $this;
    }

    public function increment(): void
    {
        $this->setState(function (): void {
            ++$this->count;
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        return Text::make('q=' . $this->count);
    }
}

/**
 * @internal 判据夹具：同一帧里"名字靠前但子树小"与"名字靠后但子树大"两个分支。
 *
 * 存在的唯一理由是让"按次数/路径排序"和"按覆盖子树排序"给出**不同**答案；
 * 没有这种结构，改排序的判据会在两种实现下都绿。
 */
final class FanOutApplication extends Application
{
    public function buildRoot(): Widget
    {
        return new FanOutProbe();
    }
}

/** @internal 判据夹具 */
final class FanOutProbe extends StatefulWidget
{
    public function createState(): State
    {
        return new FanOutState();
    }
}

/** @internal 判据夹具：整棵子树每帧都重建 */
final class FanOutState extends State
{
    public static ?self $last = null;

    public int $tick = 0;

    public function initState(): void
    {
        self::$last = $this;
    }

    public function bump(): void
    {
        $this->setState(function (): void {
            ++$this->tick;
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->children([
            Align::make(Text::make('a' . $this->tick)),
            Column::make()->children([
                Text::make('b1'),
                Text::make('b2'),
                Text::make('b3'),
                Text::make('b4'),
                Text::make('b5'),
            ]),
            Text::make('c' . $this->tick),
        ]);
    }
}

/**
 * @internal 判据夹具：折叠的三种边界同处一棵树。
 *
 * - 三份 `Padding[Text]` 完全同形 ⇒ 必须折成 `Padding ×3`；
 * - `Column[Text, Text]` 与 `Column[Padding[Text]]` 同类型、同为 3 个节点、结构不同
 *   ⇒ 必须**不**折（按类型和规模分组的实现会把它们折在一起）；
 * - 两个 `ArmedWidget` 结构相同，但只有第一个自己写了状态 ⇒ 必须**不**折
 *   （指纹里少了 kind/site 的实现会把它们折在一起，读者就会以为两处都在写）。
 *
 * 三种情况必须在同一个夹具里：分开测时，"折对了"和"折过头"都能各自绿，
 * 而真正的风险是同一棵树里两者同时发生。
 */
final class TwinApplication extends Application
{
    public function buildRoot(): Widget
    {
        return new TwinProbe();
    }
}

/** @internal 判据夹具 */
final class TwinProbe extends StatefulWidget
{
    public function createState(): State
    {
        return new TwinState();
    }
}

/** @internal 判据夹具 */
final class TwinState extends State
{
    public static ?self $last = null;

    public int $tick = 0;

    public function initState(): void
    {
        self::$last = $this;
        ArmedState::$all = [];
    }

    public function bump(): void
    {
        $this->setState(function (): void {
            ++$this->tick;
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->children([
            Padding::make(Text::make('p1')),
            Padding::make(Text::make('p2')),
            Padding::make(Text::make('p3')),
            // 同类型、同规模（3 个节点），孩子数不同：[Text, Text] 与 [Padding[Text]]。
            Column::make()->children([Text::make('t1'), Text::make('t2')]),
            Column::make()->children([Padding::make(Text::make('n1'))]),
            // 更狠的一对：同类型、同规模（5 个节点）、**孩子数也相同**（都是 2），
            // 只有结构不同。判据要挡住"按类型 + 规模 + 孩子数分组"的实现。
            Column::make()->children([Padding::make(Text::make('q1')), Padding::make(Text::make('q2'))]),
            Column::make()->children([
                Column::make()->children([Text::make('r1'), Text::make('r2')]),
                Text::make('r3'),
            ]),
            new ArmedWidget(),
            new ArmedWidget(),
        ]);
    }
}

/** @internal 判据夹具：结构相同，但可以被单独点着 */
final class ArmedWidget extends StatefulWidget
{
    public function createState(): State
    {
        return new ArmedState();
    }
}

/** @internal 判据夹具 */
final class ArmedState extends State
{
    /** @var list<self> 挂载过的全部实例（判据只点着第一个） */
    public static array $all = [];

    public int $count = 0;

    public function initState(): void
    {
        self::$all[] = $this;
    }

    public function bump(): void
    {
        $this->setState(function (): void {
            ++$this->count;
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        return Text::make('armed=' . $this->count);
    }
}
