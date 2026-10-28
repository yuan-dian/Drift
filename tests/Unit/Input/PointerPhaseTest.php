<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Input;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Input\PointerEvent;
use Drift\Input\PointerPhase;

/**
 * 指针相位的**协议面**：枚举值与桥的 ABI 编号、`fromBridge()` 的四相位映射。
 *
 * ## 为什么这两条必须是测试
 *
 * `PointerPhase` 的 `value` 就是 C++ 桥递过来的整数（与 `CursorShape` 同纪律：
 * **一个 ABI 编号只该有一处读法**）。桥那侧按 `0/1/2/3` 发，框架这侧按
 * `match` 分派 —— 两边任何一侧改编号，症状都是"某一种指针事件永远不生效"：
 * `move` 认成 `down` ⇒ 悬停与拖动全哑，而**不报错**。
 *
 * ## 一条登记在案的宽松兜底
 *
 * 未知 phase **兜 `Down`**（不是响亮失败）。与 G2 有张力：桥发来一个没见过的
 * 编号本该是"ABI 对不上"，兜成 `Down` 会变成一次幻影点击。今天不改的理由是
 * `fromBridge()` 跑在 AOT 桥的热路径上，在这里 Fatal 等于"平台一升级就崩整个应用"；
 * 折中是**把契约写进断言**（这条测试就是登记），将来要改时先改这里。
 */
final class PointerPhaseTest extends SmokeTestCase
{
    public function testPhaseValuesMatchTheBridgeAbi(): void
    {
        check(
            'Pointer · PointerPhase 值与桥一致（down=0/move=1/up=2/leave=3）',
            PointerPhase::Down->value === 0
                && PointerPhase::Move->value === 1
                && PointerPhase::Up->value === 2
                && PointerPhase::Leave->value === 3,
            \sprintf(
                'down=%d move=%d up=%d leave=%d',
                PointerPhase::Down->value,
                PointerPhase::Move->value,
                PointerPhase::Up->value,
                PointerPhase::Leave->value
            )
        );
    }

    /**
     * 四相位都映射对，且**未知值兜 Down**。
     *
     * ★ 四个 `isXxx()` 各自 true 还不够 —— 必须同时确认"不是别的那三个"，
     *   否则一个恒返 true 的实现也过。所以每个相位都成对断言。
     *
     * ★★ **这条对 `Down` 是半自证的**：`fromBridge` 的兜底也是 `Down`，
     *   所以"枚举值漂移导致 `tryFrom(0)` 失败"时它照样绿（实测变异 M1 只红
     *   ABI 那条）。`Down` 的编号由上面那条 ABI 断言单独兜住 —— 两条是一对，
     *   删任一条都会留下盲区。
     */
    public function testFromBridgeMapsAllFourPhases(): void
    {
        $down = PointerEvent::fromBridge(1.0, 2.0, 2, 0);
        $move = PointerEvent::fromBridge(1.0, 2.0, 0, 1);
        $up = PointerEvent::fromBridge(1.0, 2.0, 0, 2);
        $leave = PointerEvent::fromBridge(1.0, 2.0, 0, 3);
        $unknown = PointerEvent::fromBridge(1.0, 2.0, 0, 9);

        check(
            'Pointer · fromBridge 四相位映射（每个相位只命中自己那一个）',
            $down->isDown() && !$down->isMove() && !$down->isUp() && !$down->isLeave()
                && $move->isMove() && !$move->isDown() && !$move->isUp() && !$move->isLeave()
                && $up->isUp() && !$up->isDown() && !$up->isMove() && !$up->isLeave()
                && $leave->isLeave() && !$leave->isDown() && !$leave->isMove() && !$leave->isUp(),
            'down/move/up/leave = '
                . ($down->isDown() ? '1' : '0') . ($move->isMove() ? '1' : '0')
                . ($up->isUp() ? '1' : '0') . ($leave->isLeave() ? '1' : '0')
        );
        check(
            'Pointer · fromBridge 未知 phase 兜 Down（登记：与 G2 有张力的宽松兜底）',
            $unknown->isDown(),
            $unknown->isDown() ? 'down' : 'not-down'
        );
    }
}
