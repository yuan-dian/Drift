<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Application;
use Drift\App\FramePhase;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Rendering\FrameStage;
use Drift\Rendering\SurfaceProfile;
use Drift\Widget\Column;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 帧阶段的**两个记账出口必须配对**（2026-11-03）。
 *
 * ## 为什么要测"记账"这种没人看的数
 *
 * 一帧的九段要往两处记账：`FramePhase` 的 trace（顺序校验 + 真机报告文件）与
 * `SurfaceProfile` 的分段计时（帧成本归因）。两者原先都是手写调用，于是漂了 ——
 * 实测（`build/trace-vs-profile.php` 的同一判据）：
 *
 * ```text
 *   tickLateMount    0    ★ 只有 trace，没有计时        ← 其余八段都是 10 次
 * ```
 *
 * 症状是**没有一个症状**：顺序校验全过（它确实跑了）、帧不崩、数字都"看起来正常"
 * —— 只是那一段的成本被算进「未归属」。而「未归属」正是拿这张表判断"该不该做
 * M5 / 该砍哪一段"的那个桶。量具错一格，结论就错一格，且事后无从发现。
 *
 * ## 判据为什么是"双向"
 *
 * 只测"九段都出现"会让 `stage()` 把段名推进去两次也绿；只测"没有多余段"会让
 * 漏一段全绿。所以两侧都要：`FrameStage::all()` 逐段必须**有**计时，且计时里
 * **没有**名单外的段。
 *
 * ★ 反例在提交历史里验证过：把 `tickLateMount` 的 `stage()` 换回裸 `$trace[]`
 *   ⇒ 本文件第 ① 条当场红。
 */
final class FrameStageAccountingTest extends SmokeTestCase
{
    /**
     * ① 九段：trace 记过 ⇒ 计时也记过（两个桶的差集为空）。
     */
    public function testEveryTracedStageIsAlsoTimed(): void
    {
        $calls = $this->runFramesAndCollectCalls();

        foreach (FrameStage::all() as $stage) {
            $n = $calls[$stage] ?? 0;
            check(
                \sprintf('段 %s 既进 trace 又有计时', $stage),
                $n > 0,
                '计时 0 次 —— 它的成本会被算进「未归属」，且没有任何检查会红',
            );
        }

        $this->flushChecks();
    }

    /**
     * ② 计时里没有名单外的段 —— 防止"多记一份"（`stage()` 被调两次、或段名拼错
     *    后被静默吞掉）。
     *
     * ★ 框架自己会记 `layout` / `paint`（`Frame::run()` 内），它们不在
     *   `FrameStage::all()` 的九段里，是合法的额外项。
     */
    public function testNoUnlistedStagesAreTimed(): void
    {
        $calls = $this->runFramesAndCollectCalls();

        $allowed = [...FrameStage::all(), FrameStage::LAYOUT, FrameStage::PAINT];
        $extra = \array_diff(\array_keys($calls), $allowed);
        check(
            '计时里没有名单外的段',
            $extra === [],
            '多出来的：' . \implode(', ', $extra) . '（段名拼错？`stage()` 被调了两次？）',
        );

        $this->flushChecks();
    }

    /**
     * ③ 同一段在**一帧里只记一次**。
     *
     * ★ 这条挡住"我给这一段包了两层 `stage()`"—— 那样累计值会翻倍，而
     *   `perCall()`（平均）**看不出翻倍**：分子的两倍除以分母的两倍还是原值。
     *   ⇒ 必须判次数，不能判平均。
     */
    public function testEachStageIsRecordedOncePerFrame(): void
    {
        $frames = 3;
        $calls = $this->runFramesAndCollectCalls($frames);

        foreach (FrameStage::all() as $stage) {
            $n = $calls[$stage] ?? 0;
            check(
                \sprintf('段 %s 每帧只记一次（%d 帧 ⇒ %d 次）', $stage, $frames, $frames),
                $n === $frames,
                \sprintf('实得 %d 次（多于帧数 = 同一段被包了两层）', $n),
            );
        }

        $this->flushChecks();
    }

    /**
     * ④ `SurfaceProfile::time()` 的四条契约：透传返回值 / 开时记 / 关时不记 /
     *    **抛异常也记**（`finally` 的存在理由）。
     *
     * ★ 第四条不能省：改成"正常路径才记"时前三条全绿，而异常帧的成本悄无声息地
     *   消失 —— 恰恰是排查"卡住"时最需要的那一帧。
     */
    public function testTimeWrapsBodyAndAlwaysAccounts(): void
    {
        $ns0 = SurfaceProfile::$ns;
        $calls0 = SurfaceProfile::$calls;
        $on0 = SurfaceProfile::$on;

        try {
            // 开着：记一次，且透传返回值
            SurfaceProfile::$on = true;
            SurfaceProfile::reset();
            $got = SurfaceProfile::time('probe', static fn(): int => 42);
            check('time() 透传闭包的返回值', $got === 42, '实得 ' . \var_export($got, true));
            check('time() 开着时记了一次', (SurfaceProfile::$calls['probe'] ?? 0) === 1,
                '实得 ' . \var_export(SurfaceProfile::$calls['probe'] ?? null, true));

            // 关着：一次都不记
            SurfaceProfile::$on = false;
            SurfaceProfile::reset();
            SurfaceProfile::time('probe', static fn(): int => 7);
            check('time() 关着时不记（零开销档）', (SurfaceProfile::$calls['probe'] ?? 0) === 0,
                '实得 ' . \var_export(SurfaceProfile::$calls['probe'] ?? null, true));

            // 抛异常：仍然记一次（finally）
            SurfaceProfile::$on = true;
            SurfaceProfile::reset();
            $threw = false;
            try {
                SurfaceProfile::time('probe', static function (): void {
                    throw new \RuntimeException('boom');
                });
            } catch (\RuntimeException) {
                $threw = true;
            }
            check('time() 把闭包的异常原样抛出', $threw === true);
            check('time() 抛异常的段也记了账（finally）', (SurfaceProfile::$calls['probe'] ?? 0) === 1,
                '实得 ' . \var_export(SurfaceProfile::$calls['probe'] ?? null, true)
                . ' —— 0 说明只走正常路径，异常帧的成本会凭空消失');
        } finally {
            SurfaceProfile::$ns = $ns0;
            SurfaceProfile::$calls = $calls0;
            SurfaceProfile::$on = $on0;
        }

        $this->flushChecks();
    }

    /**
     * 跑若干帧，返回 `SurfaceProfile::$calls`（段名 → 次数）。
     *
     * ★ 每帧都开 trace：不然 `stage()` 里的 trace 分支根本没被走到，"trace 与计时
     *   配对"就只是在测一半（那半个出口压根没执行）。
     *
     * @return array<string, int>
     */
    private function runFramesAndCollectCalls(int $frames = 2): array
    {
        $app = new class () extends Application {
            public function buildRoot(): Widget
            {
                return Column::make()->children([Text::make('a'), Text::make('b')]);
            }

            public function title(): string
            {
                return 'stage-accounting';
            }
        };

        $tight = BoxConstraints::tight(new Size(200.0, 160.0));
        $surface = new SurfaceProbe();
        $surface->resize(200, 160, 1.0);

        $on0 = SurfaceProfile::$on;
        $traceOn0 = FramePhase::$on;
        SurfaceProfile::$on = true;
        FramePhase::$on = true;

        try {
            $app->attach($surface, $tight);
            SurfaceProfile::reset();
            for ($i = 1; $i <= $frames; $i++) {
                $app->frame((float) ($i * 16), $tight);
            }

            return SurfaceProfile::$calls;
        } finally {
            SurfaceProfile::$on = $on0;
            // ★ 只还原 `$on`，**不** `reset()`：`reset()` 会把已跑帧数与违规计数一起
            //   清零，而别的用例正拿它们做"前后差"判据（`FramePhaseTest`）。
            FramePhase::$on = $traceOn0;
        }
    }
}
