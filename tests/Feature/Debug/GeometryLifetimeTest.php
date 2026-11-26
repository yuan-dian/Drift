<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Application;
use Drift\Debug\Session\Recorder;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Routing\PageHost;
use Drift\Routing\RouteTable;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 几何的生命周期跟着**树**走，不跟着"有没有查过"走。
 *
 * 用户切窗后看到两页线框叠在一起：`geometryRows()` 是"每节点最近一次记录"，
 * 旧节点不再被采样后记录仍在，于是新页画面一直套着旧页的盒。判据钉三件事：
 * 当前帧只剩新树；旧帧仍能复算旧树；静止帧不把"这一轮没采样"当成"节点消失了"。
 */
final class GeometryLifetimeTest extends SmokeTestCase
{
    private function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(200.0, 120.0));
    }

    /** 两棵同构但身份不同的树：编号不复用，残留一查就现形。 */
    private function app(string $label): Application
    {
        $app = new SwapApplication($label);
        $surface = new SurfaceProbe();
        $surface->resize(200, 120, 1.0);
        $app->attach($surface, $this->constraints());

        return $app;
    }

    /** 这一帧真正登记过的节点（几何会累积，节点表里的 `s` 不会）。 */
    private function liveIds(Recorder $recorder, int $frame): array
    {
        $ids = [];
        foreach ($recorder->nodeIndex() as $id => $meta) {
            if ((int) ($meta['s'] ?? 0) === $frame) {
                $ids[] = (int) $id;
            }
        }
        \sort($ids);

        return $ids;
    }

    public function testSwitchingTreeDropsOldBoxesButKeepsHistory(): void
    {
        $recorder = Recorder::install();
        try {
            // `attach()` 自己就跑首帧，不需要再 pump —— 恰好是"切窗那一刻"。
            $old = $this->app('old');
            $atOld = \array_keys($recorder->geometryRows($recorder->lastFrameId()));
            check('前提：第一棵树真的采到了盒', $atOld !== [], 'rows=' . \count($atOld));

            $new = $this->app('new');
            $atNew = \array_keys($recorder->geometryRows($recorder->lastFrameId()));
            $newIds = $this->liveIds($recorder, 2);
            \sort($atOld);
            \sort($atNew);

            check('前提：两棵树的节点号不复用', \array_intersect($atOld, $newIds) === [],
                '旧 ' . \json_encode($atOld) . ' 新 ' . \json_encode($newIds));
            check('当前帧不再画出上一棵树的盒', $atNew === $newIds,
                '实得 ' . \json_encode($atNew) . ' 应得 ' . \json_encode($newIds));
            check(
                '历史帧还能查到旧树（不是把旧记录删掉）',
                \array_keys($recorder->geometryRows(1)) === $atOld,
                'old=' . \count($atOld) . ' at1=' . \count($recorder->geometryRows(1)),
            );
        } finally {
            Recorder::uninstall();
        }
        $this->flushChecks();
    }

    public function testSwitchingBackRestoresTheSameNodes(): void
    {
        $recorder = Recorder::install();
        try {
            $old = $this->app('old');
            $new = $this->app('new');
            $old->frame(32.0, $this->constraints()); // 切回：同一批元素、同一个节点号

            $rows = \array_keys($recorder->geometryRows($recorder->lastFrameId()));
            \sort($rows);
            $live = $this->liveIds($recorder, $recorder->lastFrameId());

            check('切回后旧节点重新有几何', $rows === $live,
                '实得 ' . \json_encode($rows) . ' 应得 ' . \json_encode($live));
            check('画面只属于当前这一棵树', !\in_array(4, $rows, true),
                \json_encode($rows));
        } finally {
            Recorder::uninstall();
        }
        $this->flushChecks();
    }

    public function testHiddenPageLeavesNoBoxesAndComesBackWithThem(): void
    {
        $recorder = Recorder::install();
        try {
            $app = new SwitchPagesApplication();
            $surface = new SurfaceProbe();
            $surface->resize(200, 120, 1.0);
            $app->attach($surface, $this->constraints());
            $firstFrame = $recorder->lastFrameId();
            $firstIds = \array_keys($recorder->geometryRows($firstFrame));

            SwitchPagesApplication::$state?->switchTo('b');
            $app->frame(16.0, $this->constraints());
            $secondFrame = $recorder->lastFrameId();
            $secondRows = \array_keys($recorder->geometryRows($secondFrame));
            $hidden = \array_diff($firstIds, $secondRows);

            check('前提：第一页有被隐藏的专属节点', $hidden !== [], 'ids=' . \json_encode($firstIds));
            check('隐藏页的盒不再出现在当前帧', \array_intersect($hidden, $secondRows) === [],
                '残留 ' . \json_encode(\array_intersect($hidden, $secondRows)));

            SwitchPagesApplication::$state?->switchTo('a');
            $app->frame(32.0, $this->constraints());
            $backFrame = $recorder->lastFrameId();
            $backRows = \array_keys($recorder->geometryRows($backFrame));

            check('切回原页的盒全部回来', \array_diff($hidden, $backRows) === [],
                '缺 ' . \json_encode(\array_diff($hidden, $backRows)));
            check(
                '切回后几何窗口跟着推进（底图不会再配到切页前的旧帧）',
                (int) $recorder->geometryCoverage()['to'] === $backFrame,
                'coverage=' . \json_encode($recorder->geometryCoverage()) . ' frame=' . $backFrame,
            );
        } finally {
            Recorder::uninstall();
            SwitchPagesApplication::$state = null;
        }
        $this->flushChecks();
    }

    public function testQuietFrameKeepsTheLastSampledTree(): void
    {
        $recorder = Recorder::install();
        try {
            $app = $this->app('quiet');
            $lastGeometry = (int) $recorder->geometryCoverage()['to'];
            $sampled = \array_keys($recorder->geometryRows($lastGeometry));

            // 静止帧按约定跳过采样：画面没变，盒必须还在，不能因为"这一帧没看见"就清空。
            $recorder->beginFrame(16.0);
            $recorder->endFrame(['settleSkipped' => true, 'paintSkipped' => true], 0.0, $app->root());

            check('静止帧沿用上一棵树的盒',
                \array_keys($recorder->geometryRows((int) $recorder->geometryCoverage()['to'])) === $sampled,
                'before=' . \count($sampled) . ' after=' . \count($recorder->geometryRows($lastGeometry)),
            );
            check('静止帧没有多采几何', \count($recorder->geometryFrames()) === 1);
        } finally {
            Recorder::uninstall();
        }
        $this->flushChecks();
    }
}

/** @internal 判据夹具：真 PageHost 切页，隐藏页由 Offstage 保活。 */
final class SwitchPagesApplication extends Application
{
    public static ?SwitchPagesState $state = null;

    public function buildRoot(): Widget
    {
        return new SwitchPagesWidget();
    }
}

/** @internal 判据夹具 */
final class SwitchPagesWidget extends StatefulWidget
{
    public function createState(): State
    {
        return new SwitchPagesState();
    }
}

/** @internal 判据夹具 */
final class SwitchPagesState extends State
{
    private string $page = 'a';

    public function initState(): void
    {
        SwitchPagesApplication::$state = $this;
    }

    public function switchTo(string $page): void
    {
        $this->setState(function () use ($page): void {
            $this->page = $page;
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        $table = RouteTable::make()
            ->route('a', static fn (): Widget => Column::make()->children([
                Text::make('a-one'), Text::make('a-two'),
            ]))
            ->route('b', static fn (): Widget => Column::make()->children([
                Text::make('a-one'), Text::make('a-two'),
            ]));

        return PageHost::make()->current($this->page)->table($table);
    }
}

/** @internal 判据夹具：换个实例就是换一棵树。 */
final class SwapApplication extends Application
{
    public function __construct(private readonly string $label)
    {
        parent::__construct();
    }

    public function title(): string
    {
        return $this->label;
    }

    public function buildRoot(): Widget
    {
        return Column::make()->children([
            Text::make($this->label . '-a'),
            Text::make($this->label . '-b'),
        ]);
    }
}
