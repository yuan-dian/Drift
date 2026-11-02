<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\FramePhase;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;
use Drift\Rendering\FrameStage;

/**
 * 帧阶段顺序（系统级不变量）：FramePhase 约束一帧内各子步骤的相对顺序。
 *
 * ★ 与 LayoutSelfCheck 同族：顺序错了会空转（settle 没跑、trace 没记），
 *   **不报任何错**。⇒ 正确序一条不报 + 反例三个越界方向都报，合成 2 条 check。
 *
 * ★ 用"前后条数差"而不是 `Errors::reset()`：后者会把 `$fatalBox` 一起清掉，
 *   而本文件后面还有跑 Fatal 路径的用例（在 smoke 里）。
 *
 * ★ 三个方向**都走 `end()`** 而不是 `assertOrder()`：`end()` 才是
 *   `Application::frame()` 真调的那一个（断言 + 刷报告文件）。
 *
 * 判据与 `tools/smoke.php` ㊹ 段末尾一致。
 */
final class FramePhaseTest extends SmokeTestCase
{
    public function testCorrectOrderReportsNothing(): void
    {
        $nBefore = count(Errors::recentDiagnostics());
        FramePhase::assertOrder(FrameStage::all());
        $nAfter = count(Errors::recentDiagnostics());
        check(
            '帧阶段 · 顺序正确（ALL 本身就是按约束排的）⇒ 一条都不报',
            $nAfter === $nBefore,
            '新增 ' . ($nAfter - $nBefore) . ' 条 Diagnostic',
        );

        $this->flushChecks();
    }

    public function testOutOfOrderDirectionsAllReported(): void
    {
        // 三个越界方向，逐条喂进去：
        //   a) A 组（settle 前）成员跑到 settle **之后** —— pumpTasks 与 settle 对调
        //   b) C 组（settle 后）成员跑到 settle **之前** —— focusCollect 与 pumpIo 对调
        //   c) 整帧**没有 settle 标记** —— `Frame::run()` 没被调用
        $aTrace = ['pumpIo', 'settle', 'pumpTasks', 'tickAnimations', 'placeOverlays', 'tickLateMount', 'focusCollect', 'pumpSemantics'];
        $bTrace = ['focusCollect', 'pumpTasks', 'tickAnimations', 'placeOverlays', 'settle', 'tickLateMount', 'pumpIo', 'pumpSemantics'];
        $cTrace = FrameStage::all();
        unset($cTrace[(int) array_search(FrameStage::SETTLE, $cTrace, true)]);
        $cTrace = array_values($cTrace);

        $base = count(Errors::recentDiagnostics());
        FramePhase::end($aTrace);
        $afterA = count(Errors::recentDiagnostics());
        FramePhase::end($bTrace);
        $afterB = count(Errors::recentDiagnostics());
        FramePhase::end($cTrace);
        $afterC = count(Errors::recentDiagnostics());

        $ring = Errors::recentDiagnostics();
        $lastText = $ring === [] ? '(空)' : (string) $ring[count($ring) - 1];

        // 报告文件：真机上独立进程读不到环形缓冲 ⇒ 违规必须落盘才算"看得见"
        $frameReport = getcwd() . '/build/frame.trace.out';
        $frameReportViolations = -1;
        if (is_file($frameReport)) {
            $text = (string) file_get_contents($frameReport);
            if (preg_match('/^violations (\d+)$/m', $text, $fm) === 1) {
                $frameReportViolations = (int) $fm[1];
            }
        }

        check(
            '帧阶段 · 反例：三个越界方向都报（A 组过后 / C 组提前 / 缺 settle）＋ 报告文件落盘',
            $afterA > $base && $afterB > $afterA && $afterC > $afterB
                && $frameReportViolations > 0,
            sprintf(
                'A +%d / B +%d / C +%d ｜ 末条：%s ｜ 报告文件 violations=%d',
                $afterA - $base,
                $afterB - $afterA,
                $afterC - $afterB,
                \strlen($lastText) > 60 ? \substr($lastText, 0, 60) . '…' : $lastText,
                $frameReportViolations,
            ),
        );

        $this->flushChecks();
    }
}
