<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Application;
use Drift\Debug\Session\FrameRecord;
use Drift\Debug\Session\Recorder;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Rendering\SurfaceProfile;
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

    public function testStageCostsConvertAndLeaveNoGapHidden(): void
    {
        $frame = new FrameRecord(
            1,
            0.0,
            10.0,
            [],
            [],
            ['pumpIo', 'settle', 'layout'],
            ['pumpIo' => 0, 'settle' => 3000000, 'layout' => 2000000],
            false,
            false,
        );

        $out = $frame->toArray();
        check(
            '纳秒转毫秒且零耗时段不列',
            $out['stageMs'] === ['settle' => 3.0, 'layout' => 2.0],
            'stageMs=' . \json_encode($out['stageMs']),
        );
        check(
            '段账没覆盖的 5ms 要报出来，而不是假装帧内 10ms 全在段里',
            \abs($frame->unattributedMs() - 5.0) < 0.0001,
            'unattributed=' . $frame->unattributedMs(),
        );

        $over = new FrameRecord(2, 16.0, 1.0, [], [], [], ['settle' => 2000000], false, false);
        check('段账比帧内还多时不报负数', $over->unattributedMs() === 0.0);
    }

    public function testStageBudgetIsClearedBetweenFrames(): void
    {
        $recorder = Recorder::install();

        try {
            $recorder->beginFrame(0.0);
            SurfaceProfile::add('probe', 1000000);
            $recorder->endFrame([], 1.0);

            $recorder->beginFrame(16.0);
            $recorder->endFrame([], 1.0);

            $next = $recorder->frame(2);
            check(
                '帧首清账：上一帧的段耗时不会滚到下一帧',
                $next !== null && ($next->stageNs['probe'] ?? 0) === 0,
                'stageNs=' . \json_encode($next?->stageNs ?? []),
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

    /**
     * 同一棵**活树**先后被两个会话录 —— 这是"重开调试会话"的最小复现。
     *
     * 旧实现靠 `debugId === 0` 判"要不要分配"：会话重开时元素还带着上个会话的号、
     * 登记表却是空的，于是直接读 `$nodes[旧号]` 拿到 `Undefined array key`，该节点
     * 从此没有类名也没有父（`pathOf()` 空串、`cascade` 把它画成根，全都看起来正常）。
     *
     * 三个判据缺一不可：① 重开后每个节点都登记完整；② 编号每会话从 1 开始
     * （`Digest` 的"两次重放逐值相同"靠它）；③ 旧会话的证据不被新会话改写。
     */
    /**
     * `aborted` 标记要落到记录上，且不污染下一帧。
     *
     * ★ 为什么不用"真抛异常"来测：帧内异常目前会让进程直接崩（`AppShell::frame()`
     *   在 `build()` 抛异常后的状态不安全 —— 这是既有缺陷，与 `try/finally` 无关，
     *   去掉 `try/finally` 同样崩）。所以这里测的是标记语义本身：
     *   `endFrame()` 收 `$aborted` ⇒ 记录带 `aborted` ⇒ 下一帧干净。
     *
     *   `Application::frame()` 的 `try/finally` 仍然必要：它保证"异常不崩"的那天，
     *   recorder 不会停在 `open` 状态把这一帧的残余吞进下一帧。
     */
    public function testAbortedFrameIsMarkedAndDoesNotLeakIntoTheNextOne(): void
    {
        $recorder = Recorder::install();

        try {
            $recorder->beginFrame(0.0);
            $recorder->endFrame([], 1.0, null, true);

            $bad = $recorder->frame($recorder->lastFrameId());
            check(
                '异常帧标 aborted',
                ($bad?->incomplete ?? []) === [FrameRecord::ABORTED],
                \json_encode($bad?->incomplete ?? []),
            );
            check(
                'aborted 进 toArray（跨进程也看得见）',
                ($bad?->toArray()['incomplete'] ?? null) === [FrameRecord::ABORTED],
                \json_encode($bad?->toArray()['incomplete'] ?? null),
            );

            $recorder->beginFrame(16.0);
            $recorder->endFrame([], 1.0);
            $next = $recorder->frame($recorder->lastFrameId());
            check(
                '下一帧不带 aborted（标记不跨帧）',
                ($next?->incomplete ?? []) === [],
                \json_encode($next?->incomplete ?? []),
            );
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }

    /**
     * 因果边撞上限时这一帧要标 `causes-truncated`。
     *
     * 早先只有 `noteStateWrite()` 查上限、`noteRebuild()` 不查，而且拒收不留痕 ——
     * 一帧里重建的节点远多于写状态的节点，于是"上限"只关住了一半，树缺了半棵
     * 而报告看不出任何异常。
     */
    public function testCauseCapIsMarkedOnTheFrame(): void
    {
        $recorder = Recorder::install();

        try {
            $app = new WideApplication();
            $app->attach(new SurfaceProbe(), $this->constraints());
            $app->frame(0.0, $this->constraints());

            WideState::$last?->bump();
            $app->frame(16.0, $this->constraints());

            $frame = $recorder->frame($recorder->lastFrameId());
            $causes = $frame === null ? -1 : \count($frame->causes);
            check('一次写入波及 600 个节点，边数被上限挡住', $causes >= 0 && $causes <= 512, 'causes=' . $causes);
            check(
                '撞上限要在帧上留痕，不能静默少半棵树',
                \in_array(FrameRecord::CAUSES_TRUNCATED, $frame?->incomplete ?? [], true),
                \json_encode($frame?->incomplete ?? []),
            );
        } finally {
            Recorder::uninstall();
        }

        $this->flushChecks();
    }

    public function testReopenSessionRebuildsNodeIndexWithoutLosingIdentity(): void
    {
        $app = $this->boot();

        $first = Recorder::install();
        $app->frame(0.0, $this->constraints());
        $ids1 = \array_keys($first->nodeIndex());
        $path1 = $first->pathOf((int) ($ids1[0] ?? 0));
        Recorder::uninstall();

        $second = Recorder::install();
        try {
            $app->frame(16.0, $this->constraints());
            $index = $second->nodeIndex();
            $ids2 = \array_keys($index);

            $broken = [];
            foreach ($index as $id => $meta) {
                if ($meta['c'] === '' || $meta['p'] === -2) {
                    $broken[] = $id;
                }
            }
            // `$index === []` 时下面的 foreach 一次都不跑，`$broken === []` 恒真 ——
            // 那是"空集合恒真"的假绿，必须先把非空作为前提。
            check('会话重开后登记表非空', $index !== [], 'nodes=0');
            check('会话重开后每个节点都有类型与父链', $broken === [], 'broken=' . \json_encode($broken));
            check(
                '会话重开后登记的节点数不变',
                \count($ids2) === \count($ids1),
                'first=' . \count($ids1) . ' second=' . \count($ids2),
            );
            check(
                '编号每会话从 1 开始（Digest 靠它比对两次重放）',
                $ids1 !== [] && $ids2 !== [] && \min($ids1) === 1 && \min($ids2) === 1,
                'min1=' . ($ids1 === [] ? 0 : \min($ids1)) . ' min2=' . ($ids2 === [] ? 0 : \min($ids2)),
            );
            $last2 = (int) ($ids2[\count($ids2) - 1] ?? 0);
            check('重开后 pathOf 仍给得出类名路径', $second->pathOf($last2) !== '', 'path=' . $second->pathOf($last2));
            check('首个会话的证据不被第二个会话改写', $first->pathOf((int) ($ids1[0] ?? 0)) === $path1, $path1);
        } finally {
            Recorder::uninstall();
        }
    }
}

/** @internal 判据夹具：一次 setState 波及 600 个节点，用来撞因果边上限 */
final class WideApplication extends Application
{
    public function buildRoot(): Widget
    {
        return Column::make()->children([new WideProbe()]);
    }
}

/** @internal 判据夹具 */
final class WideProbe extends StatefulWidget
{
    public function createState(): State
    {
        return new WideState();
    }
}

/** @internal 判据夹具：build 出 600 个兄弟，重建一次就是 600 条边 */
final class WideState extends State
{
    public static ?self $last = null;

    public function initState(): void
    {
        self::$last = $this;
    }

    public function bump(): void
    {
        $this->setState(static function (): void {
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->children(\array_map(
            static fn(int $i): Widget => Text::make('r' . $i),
            \range(1, 600),
        ));
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
