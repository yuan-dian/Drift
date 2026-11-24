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

            $rows = $query->frames();
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
                \count($query->frames(30, true)) === 2,
                'rows=' . \count($query->frames(30, true)),
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

            check('从头给是全部四帧', $ids($query->frames(0, false, 0)) === [1, 2, 3, 4], \json_encode($ids($query->frames(0, false, 0))));
            check(
                '游标只给更新的帧',
                $ids($query->frames(0, false, 2)) === [3, 4],
                \json_encode($ids($query->frames(0, false, 2))),
            );
            check(
                '游标在最新帧之后 ⇒ 空（不是退回"最近 N 帧"）',
                $query->frames(0, false, 4) === [],
                \json_encode($query->frames(0, false, 4)),
            );
            // 没有 since 时 limit 是"最近 N 帧"；两者在 limit 恰好等于剩余帧数时
            // **看起来一样**，所以真正的区分点是上面"从头给"那一条：
            // `since=0` 给全部，而不给 since 只给最近 N 帧。
            check(
                '无游标时是最近 N 帧',
                $ids($query->frames(2)) === [3, 4],
                \json_encode($ids($query->frames(2))),
            );
            check(
                '游标与过滤叠加：只要重建过的未读帧',
                $ids($query->frames(0, true, 1)) === [2],
                \json_encode($ids($query->frames(0, true, 1))),
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
            '根子树 == 重建数（森林不重叠）',
            (int) $cascade['roots'][0]['subtree'] === \count($frame->rebuilt),
            'subtree=' . $cascade['roots'][0]['subtree'] . ' rebuilt=' . \count($frame->rebuilt),
        );

        [$flat, $count] = $this->flatten($cascade['roots']);
        check('未截断时树覆盖全部重建节点', $count === \count($frame->rebuilt), "flat={$count}");
        $writes = \array_filter($flat, static fn(array $n): bool => (bool) $n['writes']);
        check('state 写入点在树里被标出来', \count($writes) === 1, 'writes=' . \count($writes));
        check(
            '写入点带调用点，不用再猜',
            \str_contains((string) \array_values($writes)[0]['site'], 'QueryTest.php'),
            'site=' . (string) \array_values($writes)[0]['site'],
        );

        $types = 0;
        foreach ($cascade['byType'] as $row) {
            $types += (int) $row['nodes'];
        }
        check('按类型聚合覆盖全部重建节点', $types === \count($frame->rebuilt), "types={$types}");

        $shallow = $query->cascade($frame->id, 1, 8);
        $kid = $shallow['roots'][0]['children'][0] ?? null;
        check(
            '超过 maxDepth 标 cut 并记未展开数',
            $kid !== null && (bool) $kid['cut'] === true && (int) $kid['omitted'] > 0,
            'kid=' . \json_encode($kid === null ? null : ['cut' => $kid['cut'], 'omitted' => $kid['omitted']]),
        );

        $narrow = $query->cascade($frame->id, 5, 1);
        $col = $narrow['roots'][0]['children'][0] ?? null;
        check(
            '超出 maxChildren 记 omitted，不静默丢分支',
            $col !== null && (int) $col['omitted'] > 0,
            'col=' . \json_encode($col === null ? null : ['omitted' => $col['omitted']]),
        );
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
