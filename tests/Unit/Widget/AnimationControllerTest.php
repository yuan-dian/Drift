<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Widget\AnimationController;
use Drift\Widget\Curve;

/**
 * 动画纯逻辑：Curve（缓动曲线）与 AnimationController（时钟）。
 *
 * 判据（与 `tools/smoke.php` ㊹ 段 ① 一致）：
 *   ① Curve：四根曲线端点不动（0→0、1→1）；入参夹取；中点手感（easeOut=0.75、easeIn=0.25）。
 *   ② Controller：初值 0 / forward 钉起点 / 半程 t=0.5 / 终点精确 1 仍返回 true /
 *      收口帧返回 false / value 是缓动后的值 / onCompleted 一次性 / jumpTo 打断不回调 /
 *      jumpTo 夹取 / reverse 按剩余距离缩时长 / 已在终点 forward 幂等 / duration<=0 立即完成。
 *
 * ★ 这是纯算术，不依赖帧驱动，故放 Unit 层（与特征级的"动画链"测试分开）。
 */
final class AnimationControllerTest extends SmokeTestCase
{
    public function testCurveEndpointsStayFixed(): void
    {
        $curveEnds = Curve::linear()->map(0.0) === 0.0 && Curve::linear()->map(1.0) === 1.0
            && Curve::easeIn()->map(0.0) === 0.0 && Curve::easeIn()->map(1.0) === 1.0
            && Curve::easeOut()->map(0.0) === 0.0 && Curve::easeOut()->map(1.0) === 1.0
            && Curve::easeInOut()->map(0.0) === 0.0 && Curve::easeInOut()->map(1.0) === 1.0;
        check('Curve · 四根曲线端点不动（0→0、1→1）', $curveEnds);

        $this->flushChecks();
    }

    public function testCurveClampsInput(): void
    {
        check(
            'Curve · 入参夹取（负数→0、超 1→1，不报错）',
            Curve::easeOut()->map(-0.5) === 0.0 && Curve::easeOut()->map(1.5) === 1.0,
        );

        $this->flushChecks();
    }

    public function testCurveMidpointFeel(): void
    {
        check(
            'Curve · 中点手感：easeOut = 0.75 快、easeIn = 0.25 慢',
            Curve::easeOut()->map(0.5) === 0.75 && Curve::easeIn()->map(0.5) === 0.25,
            'easeOut=' . Curve::easeOut()->map(0.5) . ' easeIn=' . Curve::easeIn()->map(0.5),
        );

        $this->flushChecks();
    }

    public function testControllerStartsIdle(): void
    {
        $ctl = AnimationController::make(100.0);
        check(
            '控制器 · 初值 0、不在跑、tick() 返回 false',
            $ctl->value() === 0.0 && !$ctl->isAnimating() && $ctl->tick(0.0) === false,
        );

        $this->flushChecks();
    }

    public function testControllerPinsStartOnFirstFrame(): void
    {
        $ctl = AnimationController::make(100.0);
        $ctl->forward();
        check(
            '控制器 · forward() 后第一帧钉起点（值仍是 0，返回 true）',
            $ctl->tick(1000.0) === true && $ctl->progress() === 0.0,
        );

        $this->flushChecks();
    }

    public function testControllerHalfFrame(): void
    {
        $ctl = AnimationController::make(100.0);
        $ctl->forward();
        $ctl->tick(1000.0);
        check(
            '控制器 · 半程帧：50ms / 100ms ⇒ t = 0.5',
            $ctl->tick(1050.0) === true && abs($ctl->progress() - 0.5) < 1.0e-9,
            't=' . $ctl->progress(),
        );

        $this->flushChecks();
    }

    public function testControllerEndFrameDrawsFinal(): void
    {
        $ctl = AnimationController::make(100.0);
        $ctl->forward();
        $ctl->tick(1000.0);
        $ctl->tick(1050.0);
        check(
            '控制器 · 终点帧：t 精确 1、isAnimating=false、仍返回 true（最后一帧要画）',
            $ctl->tick(1100.0) === true && $ctl->progress() === 1.0 && !$ctl->isAnimating(),
            't=' . $ctl->progress(),
        );
        check(
            '控制器 · 收口帧：返回 false ⇒ 帧时钟可以停了',
            $ctl->tick(1116.0) === false,
        );

        $this->flushChecks();
    }

    public function testControllerValueIsEased(): void
    {
        $easeCtl = AnimationController::make(100.0)->curve(Curve::easeOut());
        $easeCtl->forward();
        $easeCtl->tick(0.0);
        $easeCtl->tick(50.0);
        check(
            '控制器 · value() 是缓动后的值（easeOut 半程 = 0.75，不是 0.5）',
            abs($easeCtl->value() - 0.75) < 1.0e-9 && abs($easeCtl->progress() - 0.5) < 1.0e-9,
            'v=' . $easeCtl->value() . ' t=' . $easeCtl->progress(),
        );

        $this->flushChecks();
    }

    public function testControllerOnCompletedFiresOnce(): void
    {
        $fires = 0;
        $doneCtl = AnimationController::make(100.0);
        $doneCtl->onCompleted(static function () use (&$fires): void {
            $fires++;
        });
        $doneCtl->forward();
        $doneCtl->tick(0.0);
        $doneCtl->tick(400.0);   // 一次性跑完（终点帧）
        $doneCtl->tick(416.0);   // 收口帧 ⇒ 放回调
        $doneCtl->tick(900.0);   // 之后继续 tick ⇒ 不该再放
        check('控制器 · onCompleted 一次性（只放一次）', $fires === 1, 'fires=' . $fires);

        $this->flushChecks();
    }

    public function testControllerJumpToInterrupts(): void
    {
        $jumpFires = 0;
        $jumpCtl = AnimationController::make(100.0);
        $jumpCtl->onCompleted(static function () use (&$jumpFires): void {
            $jumpFires++;
        });
        $jumpCtl->forward();
        $jumpCtl->tick(0.0);
        $jumpCtl->jumpTo(0.42);
        check(
            '控制器 · jumpTo 打断：值精确、停住、不触发完成回调',
            $jumpCtl->value() === 0.42 && !$jumpCtl->isAnimating() && $jumpFires === 0,
            'v=' . $jumpCtl->value() . ' fires=' . $jumpFires,
        );

        $this->flushChecks();
    }

    public function testControllerJumpToClamps(): void
    {
        $clampCtl = AnimationController::make(100.0);
        $clampCtl->jumpTo(-3.0);
        $low = $clampCtl->progress();
        $clampCtl->jumpTo(9.0);
        check(
            '控制器 · jumpTo 夹取（负数→0、超 1→1）',
            $low === 0.0 && $clampCtl->progress() === 1.0,
            'low=' . $low . ' high=' . $clampCtl->progress(),
        );

        $this->flushChecks();
    }

    public function testControllerReverseScalesByRemaining(): void
    {
        $revCtl = AnimationController::make(100.0);
        $revCtl->jumpTo(0.5);
        $revCtl->reverse();
        $revCtl->tick(1000.0);
        $revCtl->tick(1050.0);
        check(
            '控制器 · reverse 按剩余距离缩时长（0.5→0 只跑 50ms）',
            $revCtl->progress() === 0.0 && $revCtl->tick(1066.0) === false,
            't=' . $revCtl->progress(),
        );

        $this->flushChecks();
    }

    public function testControllerForwardAtEndIsIdempotent(): void
    {
        $idemCtl = AnimationController::make(100.0);
        $idemCtl->jumpTo(1.0);
        $idemCtl->forward();
        check(
            '控制器 · 已在终点再 forward() ⇒ 不跑、不回调（幂等）',
            !$idemCtl->isAnimating() && $idemCtl->tick(0.0) === false,
        );

        $this->flushChecks();
    }

    public function testControllerZeroDurationCompletesImmediately(): void
    {
        $zeroCtl = AnimationController::make(0.0);
        $zeroCtl->forward();
        $zeroCtl->tick(0.0);
        check(
            '控制器 · duration <= 0 ⇒ 立即完成（不除零、不卡死）',
            $zeroCtl->tick(1.0) === true && $zeroCtl->progress() === 1.0 && $zeroCtl->tick(2.0) === false,
            't=' . $zeroCtl->progress(),
        );

        $this->flushChecks();
    }
}
