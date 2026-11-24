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
