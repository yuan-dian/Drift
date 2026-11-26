<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Application;
use Drift\Debug\Session\GeometryCapture;
use Drift\Debug\Session\History;
use Drift\Debug\Session\Recorder;
use Drift\Debug\Session\RecordOptions;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/** 真窗快照的锚点必须落在几何窗口里，否则底图会套上另一帧的线框。 */
final class LiveShotAnchorTest extends SmokeTestCase
{
    public function testIdleLoopFramesOutrunTheGeometryWindow(): void
    {
        $recorder = Recorder::install(RecordOptions::default()->withGeometry(GeometryCapture::Changed));
        $history = new History($recorder);
        $constraints = BoxConstraints::loose(new Size(200.0, 120.0));

        try {
            $app = new AnchorFixtureApplication();
            $app->attach(new SurfaceProbe(), $constraints);
            $app->frame(0.0, $constraints);

            $first = $history->geometryCoverage();
            check('首帧几何落了账', $first['to'] > 0 && $history->geometryRows($first['to']) !== [], \json_encode($first));

            // 静止应用照常空转：帧号推进，但结构没变 ⇒ 几何不再落行。
            for ($i = 1; $i <= 200; ++$i) {
                $app->frame($i * 16.0, $constraints);
            }

            $coverage = $history->geometryCoverage();
            $frame = $recorder->frameId();
            check(
                '主循环帧号跑出了几何窗口',
                $frame > $coverage['to'],
                "frame={$frame} geometryTo={$coverage['to']}",
            );
            check('拿主循环帧号取几何会落空', $history->geometryRows($frame) === [], 'frame=' . $frame);
            check(
                '拿几何覆盖帧仍取得到几何',
                $history->geometryRows($coverage['to']) !== [],
                'geometryTo=' . $coverage['to'],
            );
        } finally {
            Recorder::uninstall();
        }
    }

    public function testLiveTickerAnchorsTheShotToGeometryCoverage(): void
    {
        $src = (string) \file_get_contents(\dirname(__DIR__, 3) . '/tools/Debug/LiveTicker.php');
        $at = \strpos($src, 'public function onPainted(');
        check('找得到帧出口回调', $at !== false);
        $body = \substr($src, $at === false ? 0 : $at, 900);

        check(
            '锚点取几何覆盖帧',
            \str_contains($body, "geometryCoverage()['to']"),
            $body,
        );
        check(
            '快照用锚点配对而不是主循环帧号',
            \str_contains($body, 'capture($canvas, $anchor,'),
            $body,
        );
    }
}

/** @internal 判据夹具：一棵静止的小树，用来制造"帧号走、几何不走" */
final class AnchorFixtureApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Column::make()->children([Text::make('anchor')]);
    }
}
