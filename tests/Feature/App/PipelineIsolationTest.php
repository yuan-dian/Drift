<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\Core\Pipeline;
use Drift\App\Application;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Column;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * **两个应用的记账必须互不干扰**（技术债 D3 验收②）。
 *
 * ★ 为什么这条必须存在：D3 之前这 20 个字段全是 `private static` —— 判据"一次
 *   自增"在单应用下是对的，代价是两个 `Application` 共用一本账：A 建树会让 B 的
 *   结构版本号跳、A 跑一帧会让 B 的"跳过率"失真。搬进 `Pipeline` 之后，
 *   **"谁的帧记谁的账"必须能被一条测试证伪**，否则这次搬迁只是换了个住处。
 *
 * ★ 判据形态选"**交错**"而不是"各测各的"：单应用测试在两种实现下都绿
 *   （全局静态 vs 每应用一份），只有"建完 A 再建 B、回头看 A 的数字"才分得开。
 */
final class PipelineIsolationTest extends SmokeTestCase
{
    private function makeApp(string $label): Application
    {
        $app = new class ($label) extends Application {
            public function __construct(private string $label)
            {
                parent::__construct();
            }

            public function buildRoot(): Widget
            {
                // 一棵小树就够：建树会推结构版本号、配对计数、盒换代序号
                return Column::make()->children([
                    Text::make($this->label),
                    Text::make($this->label . ' 二'),
                ]);
            }
        };

        $constraints = BoxConstraints::tight(new Size(160.0, 80.0));
        $app->attach(new SurfaceProbe(), $constraints);

        return $app;
    }

    /** 帧量具必须一应用一份：两个应用各 attach 一次 ⇒ 各记 1 帧，不是 2 */
    public function testFrameCountersArePerApplication(): void
    {
        $a = $this->makeApp('A');
        $b = $this->makeApp('B');

        check(
            'D3 · A 的 settle 帧数 = 1（只数自己那一帧）',
            $a->pipeline()->settleRunCount() === 1,
            'A=' . $a->pipeline()->settleRunCount(),
        );
        check(
            'D3 · B 的 settle 帧数 = 1（共享计数会变成 2）',
            $b->pipeline()->settleRunCount() === 1,
            'B=' . $b->pipeline()->settleRunCount(),
        );

        $this->flushChecks();
    }

    /** 建 B 的树不许动 A 的结构版本号（这是"共用一条号"最典型的症状） */
    public function testBuildingAnotherAppDoesNotBumpStructureVersion(): void
    {
        $a = $this->makeApp('A');
        $before = $a->pipeline()->structureVersion();

        check(
            'D3 · A 建完树后结构版本号已推进（前置：这条号确实会被写）',
            $before > 0,
            'A=' . $before,
        );

        $b = $this->makeApp('B');

        check(
            'D3 · 建 B 的树之后 A 的结构版本号没变',
            $a->pipeline()->structureVersion() === $before,
            'A 前=' . $before . ' 后=' . $a->pipeline()->structureVersion(),
        );
        check(
            'D3 · 静态读（`Pipeline::current()`）指向刚进入帧的那个应用',
            Pipeline::current()->structureVersion() === $b->pipeline()->structureVersion(),
            '静态=' . Pipeline::current()->structureVersion()
                . ' B=' . $b->pipeline()->structureVersion(),
        );

        $this->flushChecks();
    }

    /** 跑 A 的帧不许动 B 的量具（交错跑帧是最容易漏的一路） */
    public function testFramesOfOneAppDoNotTouchTheOther(): void
    {
        $a = $this->makeApp('A');
        $b = $this->makeApp('B');

        $snapshot = static fn (Application $app): array => [
            'settleRun' => $app->pipeline()->settleRunCount(),
            'paintRun' => $app->pipeline()->paintRunCount(),
            'layout' => $app->pipeline()->layoutCount(),
            'structure' => $app->pipeline()->structureVersion(),
        ];
        $bBefore = $snapshot($b);

        $constraints = BoxConstraints::tight(new Size(160.0, 80.0));
        for ($i = 1; $i <= 4; $i++) {
            $a->frame((float) $i * 16.0, $constraints);
        }

        check(
            'D3 · 跑 A 的 4 帧之后，B 的四项数字逐位不变',
            $snapshot($b) === $bBefore,
            'B 前=' . \json_encode($bBefore) . ' 后=' . \json_encode($snapshot($b)),
        );
        check(
            'D3 · A 的帧确实跑过（`frameCount` 不受 settle 整段跳过影响）',
            $a->frameCount() > 1,
            'A frameCount=' . $a->frameCount(),
        );

        $this->flushChecks();
    }

    /**
     * 输入入口必须切管线。
     *
     * ★ 测的是 `dispatchInput()` 那行 `Pipeline::enter()`：输入回调里的 `setState()`
     *   是**帧外**写。不切的话它记到"上一次跑帧的应用"的账上 ⇒ 本应用的
     *   `markedCount` 不涨 ⇒ `Frame::needsSettle()` 判"没变" ⇒ **点了没反应**，
     *   且不报错。多应用下这是最贵的一个静默 bug（D3 之前被"全局一份"掩盖着）。
     * ★ 探针用 `markNeedsLayout()`（树节点的静态写点）：写到哪条管线就是哪条。
     */
    public function testInputDispatchSwitchesPipeline(): void
    {
        $a = $this->makeApp('A');
        $b = $this->makeApp('B');

        // 让"当前管线"停在 B 上（这是最不利的起始状态）
        $b->frame(16.0, BoxConstraints::tight(new Size(160.0, 80.0)));

        $aEpoch = $a->pipeline()->layoutEpoch();
        $bEpoch = $b->pipeline()->layoutEpoch();

        $a->dispatchInput(\Drift\Input\PointerEvent::move(5.0, 5.0));
        Pipeline::current()->bumpLayoutEpoch();

        check(
            'D3 · 派发过输入之后，静态写点落到**本应用**的管线（B 不动）',
            $a->pipeline()->layoutEpoch() === $aEpoch + 1
                && $b->pipeline()->layoutEpoch() === $bEpoch,
            'A=' . $a->pipeline()->layoutEpoch() . '/' . ($aEpoch + 1)
                . ' B=' . $b->pipeline()->layoutEpoch() . '/' . $bEpoch,
        );

        $this->flushChecks();
    }

    /**
     * 盒换代序号一应用一份：A 换盒不许让 B 重排。
     *
     * ★ 前置必须是"**A 刚跑过一帧**"：帧外写点的语义是"记到最近一帧的管线上"
     *   （`Pipeline::enter()` 不恢复，见它的注）。不先跑帧就直接写，落到的是
     *   最后 attach 的那个应用 —— 那是已知的记账局限，不是本测试要断言的东西。
     */
    public function testBoxEpochIsPerApplication(): void
    {
        $a = $this->makeApp('A');
        $b = $this->makeApp('B');

        $a->frame(16.0, BoxConstraints::tight(new Size(160.0, 80.0)));

        $bEpoch = $b->pipeline()->layoutEpoch();
        $aEpoch = $a->pipeline()->layoutEpoch();

        check(
            'D3 · 两个应用各有自己的盒换代序号（都 > 0 但彼此独立）',
            $aEpoch > 0 && $bEpoch > 0,
            'A=' . $aEpoch . ' B=' . $bEpoch,
        );

        Pipeline::current()->bumpLayoutEpoch();

        check(
            'D3 · 静态写点落在**当前**管线上（A 涨 1，B 不动）',
            $a->pipeline()->layoutEpoch() === $aEpoch + 1
                && $b->pipeline()->layoutEpoch() === $bEpoch,
            'A=' . $a->pipeline()->layoutEpoch() . '/' . ($aEpoch + 1)
                . ' B=' . $b->pipeline()->layoutEpoch() . '/' . $bEpoch,
        );

        $this->flushChecks();
    }
}
