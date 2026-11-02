<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Frame;

use Drift\Dev\Smoke\App\RevealApplication;
use Drift\Dev\Tests\Support\Frame;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Element;
use Drift\Widget\Spinner;
use Drift\Widget\SpinnerState;
use Drift\Widget\State;
use Drift\Widget\StatefulElement;

/**
 * 挂载登记表（W7 L5-4）。
 *
 * `06` §4.2：tickFrame() 必须在 settle 之前跑，挂载必然发生在 settle 期间
 * ⇒ 这一帧刚挂上的 State 第一次 tick 时还不在树里。改动前靠再遍历全树补上，
 * 现在只遍历登记表。下面用一个不在任何应用树里的 Spinner 当探针：它只能被登记表照顾到
 * ⇒ "它的 step 前进了"就证明登记表真的在被消费（而不是遍历全树时顺手带到）。
 */
final class MountRegistryTest extends SmokeTestCase
{
    public function testMountRegistryConsumesOutOfTreeState(): void
    {
        // ★ D3（2026-11-02）：登记表**一应用一份**了，所以顺序必须先建应用再挂树外
        //   节点 —— 反过来的话登记落到"上一个用例的管线"上，本应用的帧永远看不到它
        //   （这正是 D3 要的行为：树外节点不该被随便哪个应用的帧推进）。
        $mountApp = Frame::open(new RevealApplication(), 320.0, 200.0);
        State::takeMountedThisFrame();   // 清掉建树期登记的（已经被首帧消费过）

        // ⑳ mount() 之后 ⇒ 登记表里有 1 条（消费方还没跑）
        $mountSpin = Spinner::make()->createElement();
        $mountSpin->mount(null, smokeContext());
        $mountSpin->effectiveBox()?->layout(BoxConstraints::loose(new Size(64.0, 64.0)));
        $mountSpinState = findElement(
            $mountSpin,
            static fn(Element $e): bool => $e instanceof StatefulElement && $e->state() instanceof SpinnerState,
        )?->state();
        check(
            '挂载登记 · mount() 之后登记表里有 1 条（等帧来消费）',
            State::mountedCount() === 1 && $mountSpinState instanceof SpinnerState,
            'mounted=' . State::mountedCount(),
        );

        // ㉑ 一帧之后 ⇒ 登记表清空，且那个不在应用树里的 State 真的被 tick 了
        $mountApp->tick();
        check(
            '挂载登记 · 一帧之后被消费干净（不会无限累积）',
            State::mountedCount() === 0,
            'mounted=' . State::mountedCount(),
        );
        $mountStep = $mountSpinState instanceof SpinnerState ? $mountSpinState->step() : -1;
        check(
            '挂载登记 · 本帧新挂载的 State 当帧被 tick（不在应用树里也照 tick）',
            $mountStep === 1,
            'step=' . $mountStep,
        );

        // ㉒ 已卸载的登记项被跳过
        $deadApp = Frame::open(new RevealApplication(), 320.0, 200.0);
        State::takeMountedThisFrame();
        $deadSpin = Spinner::make()->createElement();
        $deadSpin->mount(null, smokeContext());
        $deadState = findElement(
            $deadSpin,
            static fn(Element $e): bool => $e instanceof StatefulElement && $e->state() instanceof SpinnerState,
        )?->state();
        $deadSpin->unmount();
        $deadApp->tick();
        check(
            '挂载登记 · 已卸载的登记项被跳过（不 tick 死节点）',
            $deadState instanceof SpinnerState && $deadState->step() === 0 && State::mountedCount() === 0,
            'step=' . ($deadState instanceof SpinnerState ? $deadState->step() : -1)
                . ' mounted=' . State::mountedCount(),
        );

        $this->flushChecks();
    }
}
