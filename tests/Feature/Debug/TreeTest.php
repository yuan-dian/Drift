<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Application;
use Drift\Debug\Session\EvidenceBag;
use Drift\Debug\Session\FrameRecord;
use Drift\Debug\Session\Query;
use Drift\Debug\Session\Recorder;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Column;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * A64 判据：结构树是节点注册表的只读投影，不是另一份"看起来像树"的证据。
 *
 * 钉三件事：① 断父链不能被静默丢掉；② 截断必须自报；③ 几何注解来自 boxes()，
 * 查询层已经对齐，消费者不再走一遍 parent 指针。
 */
final class TreeTest extends SmokeTestCase
{
    public function testBrokenParentLinksAreSurfacedInsteadOfDropped(): void
    {
        $recorder = Recorder::hydrate(new EvidenceBag(
            nodes: [
                1 => ['p' => -1, 'c' => 'Page', 'x' => false, 's' => 3],
                2 => ['p' => 1, 'c' => 'Column', 'x' => true, 's' => 3],
                3 => ['p' => 2, 'c' => 'Text', 'x' => false, 's' => 2],
                4 => ['p' => 99, 'c' => 'Orphan', 'x' => false, 's' => 3],
                5 => ['p' => -2, 'c' => 'Pending', 'x' => false, 's' => 3],
                6 => ['p' => 7, 'c' => 'CycleA', 'x' => false, 's' => 3],
                7 => ['p' => 6, 'c' => 'CycleB', 'x' => false, 's' => 3],
            ],
            // 99 号在第 2 帧离开树（`g=2`）：回放第 3 帧时它已经不在了，于是它那个
            // 还活着的孩子 4 号必须是 `orphan`。旧夹具只写了"它被回收了"——回收是
            // **现在**的状态，而"父链在那一帧断没断"要看它**那时**走没走。
            evicted: [99 => ['p' => -1, 'c' => 'Page', 'x' => false, 'f' => 1, 's' => 2, 'g' => 2]],
            // 三帧空记录：没有时间轴就谈不上"回放"，`tree(frame:)` 会退化成纯结构投影
            // （那时"父已回收"和"父当时还在"分不开）。判据要钉的是回放，所以先给轴。
            frames: [
                new FrameRecord(1, 16.0, 1.0, [], [], [], [], false, false),
                new FrameRecord(2, 32.0, 1.0, [], [], [], [], false, false),
                new FrameRecord(3, 48.0, 1.0, [], [], [], [], false, false),
            ],
        ));
        $query = new Query($recorder);

        $answer = $query->tree(frame: 3);
        $byId = [];
        foreach ($answer['rows'] as $row) {
            $byId[(int) $row['id']] = $row;
        }

        check('活动节点都在树里', \count($byId) === 7, 'rows=' . \json_encode(\array_keys($byId)));
        check('父已回收标 orphan 并挂根', ($byId[4]['flag'] ?? '') === 'orphan' && ($byId[4]['depth'] ?? -1) === 0);
        check('未解析父标 unresolved', ($byId[5]['flag'] ?? '') === 'unresolved');
        check('成环节点也挂根，不整体消失', ($byId[6]['flag'] ?? '') === 'cycle' && ($byId[7]['flag'] ?? '') === 'cycle');
        check('旧节点标 stale 并给出帧龄', ($byId[3]['stale'] ?? false) === true && ($byId[3]['age'] ?? 0) === 1);
        check(
            '覆盖率自报三种断链',
            ($answer['coverage']['orphan'] ?? 0) === 1
                && ($answer['coverage']['unresolved'] ?? 0) === 1
                && ($answer['coverage']['cycle'] ?? 0) === 2,
            'coverage=' . \json_encode($answer['coverage']),
        );

        $sub = $query->tree(root: 2);
        check(
            '按根看子树只剩自己与后代',
            \array_column($sub['rows'], 'id') === [2, 3],
            'ids=' . \json_encode(\array_column($sub['rows'], 'id')),
        );
        check('子树里不把外面的节点冒充成环', ($sub['coverage']['cycle'] ?? -1) === 0);

        $evicted = $query->tree(root: 99);
        check('回放已消失的节点是明确 miss 且说清"那时已经不在"', ($evicted['reason'] ?? '') === 'node-gone', 'reason=' . \json_encode($evicted['reason'] ?? null));
        $unknown = $query->tree(root: 12345);
        check('从未发号的节点不是空树而是明确 miss', ($unknown['reason'] ?? '') === 'unknown-node');

        $this->flushChecks();
    }

    public function testDepthAndLimitAreCountedNotSilentlyForgotten(): void
    {
        $recorder = Recorder::hydrate(new EvidenceBag(
            nodes: [
                1 => ['p' => -1, 'c' => 'Root', 'x' => false, 's' => 1],
                2 => ['p' => 1, 'c' => 'Mid', 'x' => false, 's' => 1],
                3 => ['p' => 2, 'c' => 'Leaf', 'x' => false, 's' => 1],
                4 => ['p' => -1, 'c' => 'Other', 'x' => false, 's' => 1],
            ],
        ));
        $query = new Query($recorder);

        $cut = $query->tree(depth: 1);
        check(
            '深度截断后只列根与第一层',
            \array_column($cut['rows'], 'id') === [1, 2, 4],
            'ids=' . \json_encode(\array_column($cut['rows'], 'id')),
        );
        check('深度截断有账', ($cut['coverage']['omittedDepth'] ?? 0) === 1);

        $limited = $query->tree(limit: 2);
        check('条数截断只列两条', \count($limited['rows']) === 2);
        check('条数截断有账', ($limited['coverage']['omittedLimit'] ?? 0) === 2);

        $this->flushChecks();
    }

    public function testTreeGeometryIsJoinedOnceFromBoxes(): void
    {
        $app = new TreeApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(240.0, 160.0)));
        $recorder = Recorder::install();
        try {
            $app->frame(0.0, BoxConstraints::loose(new Size(240.0, 160.0)));
            $query = new Query($recorder);
            $boxes = $query->boxes();
            $frame = (int) $boxes['frame'];

            $rows = $query->tree(frame: $frame)['rows'];
            $boxByNode = [];
            foreach ($boxes['rows'] as $row) {
                $boxByNode[(int) $row['node']] = $row;
            }

            check('树里有节点', \count($rows) > 0);
            $joined = 0;
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                if (!isset($boxByNode[$id])) {
                    check('无盒节点不伪造几何', ($row['geometry'] ?? null) === null);
                    continue;
                }
                ++$joined;
                check(
                    "节点 #{$id} 的几何逐值来自 boxes()",
                    ($row['geometry']['size'] ?? null) === ($boxByNode[$id]['size'] ?? null)
                        && ($row['geometry']['rect'] ?? null) === ($boxByNode[$id]['rect'] ?? null),
                    'tree=' . \json_encode($row['geometry'] ?? null) . ' boxes=' . \json_encode($boxByNode[$id]['size'] ?? null),
                );
            }
            check('确实有盒节点被对齐', $joined > 0, 'joined=' . $joined);
        } finally {
            Recorder::uninstall();
        }
        $this->flushChecks();
    }
}

/** @internal 判据夹具：两层结构足以让树的父子与几何对齐都可观察。 */
final class TreeApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Column::make()->children([
            Text::make('one'),
            Text::make('two'),
        ]);
    }
}
