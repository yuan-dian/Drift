<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Application;
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
 * D0 判据：一帧要能回答"哪些节点重建、因为谁"。
 *
 * 钉的是三个挂点本身（状态写入 / 重建 / 帧边界），不是面板：
 * - 没写状态的帧必须一条边都没有（否则"变了"这件事不可信）；
 * - 写了的那一帧，`why()` 要给出 kind、state 类名、调用点；
 * - `rebuilt` 是**真的**重建过，不是"标脏了"。
 */
final class RecorderTest extends SmokeTestCase
{
    private function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(200.0, 120.0));
    }

    private function boot(): Application
    {
        $app = new CounterApplication();
        $app->attach(new SurfaceProbe(), $this->constraints());

        return $app;
    }

    public function testWhyExplainsAStateWrite(): void
    {
        $recorder = Recorder::install();

        try {
            $app = $this->boot();
            $app->frame(0.0, $this->constraints());

            $first = $recorder->frame($recorder->lastFrameId());
            check('首帧记录存在', $first !== null, 'lastFrameId=' . $recorder->lastFrameId());
            check(
                '首帧没有状态写入（初始挂载不算"变了"）',
                $first !== null && $first->causes === [],
                'causes=' . \count($first?->causes ?? []),
            );

            $state = CounterState::$last;
            check('拿到了 State 实例', $state !== null);
            $state?->increment();

            $app->frame(16.0, $this->constraints());

            $element = $state?->element();
            check('State 有宿主元素', $element !== null);
            $id = $element?->debugId ?? 0;
            check('元素拿到了稳定节点号', $id > 0, 'id=' . $id);

            $why = $recorder->why($id, $recorder->lastFrameId());
            $cause = $why['causes'][0] ?? [];

            check('重建被记录', $why['rebuilt'] === true);
            check('因果 kind=state', ($cause['kind'] ?? '') === 'state', \json_encode($cause));
            check('记下了 State 类名', ($cause['detail'] ?? '') === 'CounterState', \json_encode($cause));
            check(
                '记下了调用点（业务文件:行）',
                \str_contains((string) ($cause['site'] ?? ''), 'RecorderTest.php'),
                'site=' . ($cause['site'] ?? ''),
            );
            check(
                '路径用 Widget 名（不是 Element 子类名）',
                \str_contains((string) $why['path'], 'CounterProbe'),
                'path=' . $why['path'],
            );
        } finally {
            Recorder::uninstall();
        }
    }

    public function testFramesCarryStagesAndTrimToCapacity(): void
    {
        $recorder = Recorder::install(2);

        try {
            $app = $this->boot();
            $app->frame(0.0, $this->constraints());

            $idle = $recorder->frame($recorder->lastFrameId());
            check(
                '空闲帧：段名在、且 settle 整段跳过',
                $idle !== null
                    && \in_array('pumpIo', $idle->stages, true)
                    && $idle->settleSkipped === true,
                'stages=' . \json_encode($idle?->stages ?? []) . ' skipped=' . \var_export($idle?->settleSkipped, true),
            );

            CounterState::$last?->increment();
            $app->frame(16.0, $this->constraints());

            $dirty = $recorder->frame($recorder->lastFrameId());
            check(
                '有重建的帧：settle 真跑了',
                $dirty !== null && $dirty->settleSkipped === false && $dirty->rebuilt !== [],
                'skipped=' . \var_export($dirty?->settleSkipped, true) . ' rebuilt=' . \json_encode($dirty?->rebuilt ?? []),
            );

            $app->frame(32.0, $this->constraints());
            $app->frame(48.0, $this->constraints());

            $ids = \array_keys($recorder->frames());
            check(
                '超出容量只保留最近两帧',
                \count($ids) === 2 && \end($ids) === $recorder->lastFrameId(),
                'ids=' . \json_encode($ids) . ' last=' . $recorder->lastFrameId(),
            );
        } finally {
            Recorder::uninstall();
        }
    }

    public function testWithoutRecorderNothingIsRecorded(): void
    {
        Recorder::uninstall();

        $app = $this->boot();
        $app->frame(0.0, $this->constraints());

        check('未安装时没有录制器', Recorder::current() === null);
        check(
            '未安装时元素不分配节点号',
            (CounterState::$last?->element()?->debugId ?? -1) === 0,
            'debugId=' . \var_export(CounterState::$last?->element()?->debugId, true),
        );
    }
}

/** @internal 判据夹具：一个只有自增计数的应用 */
final class CounterApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Column::make()->children([new CounterProbe()]);
    }
}

/** @internal 判据夹具 */
final class CounterProbe extends StatefulWidget
{
    public function createState(): State
    {
        return new CounterState();
    }
}

/** @internal 判据夹具：留下最后一个实例，供判据直接 setState */
final class CounterState extends State
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
        return Text::make('count=' . $this->count);
    }
}
