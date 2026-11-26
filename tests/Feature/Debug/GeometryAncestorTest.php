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
use Drift\Widget\Padding;
use Drift\Widget\State;
use Drift\Widget\SizedBox;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 稳定的祖先几何不能被窗口裁剪丢掉。
 *
 * Changed 模式只在签名变化时落几何：外层 Padding/Column 首帧采一次就不动了，
 * 内层每帧变。60 帧窗口滚动后祖先那条记录被裁，`absoluteRects` 遇父缺失就
 * "自成原点"，后代 coord 丢掉祖先偏移 —— 线框整体平移，与真机底图错位。
 */
final class GeometryAncestorTest extends SmokeTestCase
{
    private function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(2000.0, 2000.0));
    }

    /** 找动态文本节点：它是每帧重采、父链穿过稳定祖先的那个后代。 */
    private function leafNode(Query $query, int $frame): int
    {
        foreach ($query->boxes($frame)['rows'] as $row) {
            if (($row['type'] ?? '') === 'Text' && \str_contains((string) ($row['node'] ?? ''), '')) {
                return (int) $row['node'];
            }
        }
        self::fail('找不到动态文本节点');
    }

    public function testStableAncestorOffsetSurvivesWindowRotation(): void
    {
        $recorder = Recorder::install();
        try {
            $app = new RotatingApplication();
            $surface = new SurfaceProbe();
            $surface->resize(2000, 2000, 1.0);
            $app->attach($surface, $this->constraints());

            $firstFrame = $recorder->lastFrameId();
            $query = new Query($recorder);
            $early = $query->boxes($firstFrame)['rows'];
            $leaf = null;
            foreach ($early as $row) {
                if (($row['type'] ?? '') === 'Text') {
                    $leaf = (int) $row['node'];
                    break;
                }
            }
            self::assertNotNull($leaf, '前提：首帧采到了动态文本');
            $byNode = [];
            foreach ($early as $row) {
                $byNode[(int) $row['node']] = $row;
            }
            $earlyX = (float) ($byNode[$leaf]['rect']['x'] ?? 0.0);
            check('前提：首帧后代带着祖先的左偏移', $earlyX >= 30.0,
                'leaf=' . $leaf . ' x=' . $earlyX);

            // 每帧让叶子变宽（几何签名变 ⇒ 记一条），祖先不变 ⇒ 只有首帧采过。
            for ($i = 1; $i <= 65; ++$i) {
                RotatingState::$instance?->grow();
                $app->frame($i * 16.0, $this->constraints());
            }

            $coverage = $recorder->geometryCoverage();
            $last = (int) $coverage['to'];
            $frames = $recorder->geometryFrames();
            check('前提：祖先采样帧已被裁出几何窗口', \min($frames) > $firstFrame,
                'first=' . $firstFrame . ' minFrame=' . \min($frames) . ' coverage=' . \json_encode($coverage));
            $lateRows = $query->boxes($last)['rows'];

            $lateByNode = [];
            foreach ($lateRows as $row) {
                $lateByNode[(int) $row['node']] = $row;
            }
            $lateX = (float) ($lateByNode[$leaf]['rect']['x'] ?? 0.0);

            check('祖先滚出窗口后后代绝对坐标仍含祖先偏移', \abs($lateX - $earlyX) < 0.01,
                '早=' . $earlyX . ' 晚=' . $lateX . ' leaf=' . $leaf
                . ' ids=' . \json_encode(\array_column($lateRows, 'node')));

            // geometryAt 的兜底服务于约束链复算：祖先被裁后不能报 parent-outside-window。
            $chain = $query->constraints($leaf, $last, 4);
            $edges = $chain['chain'] ?? $chain['edges'] ?? [];
            $outside = false;
            foreach ($edges as $edge) {
                if (($edge['source'] ?? '') === 'parent-outside-window') {
                    $outside = true;
                }
            }
            check('祖先滚出窗口后约束父链仍可复算', !$outside && $edges !== [],
                'chain=' . \json_encode($chain));
        } finally {
            Recorder::uninstall();
            RotatingState::$instance = null;
        }
        $this->flushChecks();
    }
}

/** @internal 判据夹具：外层稳定 Padding 提供偏移，内层文本每帧变宽。 */
final class RotatingApplication extends Application
{
    public function buildRoot(): Widget
    {
        // SizedBox 尺寸固定 ⇒ 祖先几何签名恒定、只在首帧采一次；内层文本每帧变宽。
        return Padding::make(
            SizedBox::make(Column::make()->children([
                new RotatingText(),
            ]))->size(600.0, 400.0),
        )->all(40.0);
    }
}

/** @internal */
final class RotatingText extends StatefulWidget
{
    public function createState(): State
    {
        return new RotatingState();
    }
}

/** @internal */
final class RotatingState extends State
{
    public static ?RotatingState $instance = null;

    private int $len = 2;

    public function initState(): void
    {
        self::$instance = $this;
    }

    public function grow(): void
    {
        $this->setState(function (): void {
            ++$this->len;
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        return Text::make(\str_repeat('x', $this->len));
    }
}
