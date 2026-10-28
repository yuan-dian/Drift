<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\App\Frame;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Smoke\Tree\CounterWidget;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Offstage;

/**
 * Offstage：三档里的"保留"档 —— 切走再切回，State 还是同一个（§12.1 第 4/7 条）。
 *
 * ## "隐藏"与"丢弃"是两件事，判据必须分开
 *
 * - **隐藏**：子层从树里摘掉（不 layout / 不 paint / flush 走不到），但 State **不 dispose**。
 * - **丢弃**：整个 Offstage 被 unmount ⇒ State 必须 dispose（否则泄漏）。
 *
 * 两者混起来看会漏：只验 `childCount() === 0` 分不出"摘掉"与"销毁"。
 *
 * ## 为什么"切回来立即兑现脏标记"要单独钉
 *
 * 隐藏期间攒下的 `setState` 若留到下一帧兑现，帧还没跑时画面就是**旧的** ——
 * 而且是静默的（没有任何断言指向它）。`update()` 内部 settle 才是"切回不是旧画面"的机制。
 */
final class OffstageTest extends SmokeTestCase
{
    public function testHiddenPageKeepsState(): void
    {
        $context = smokeContext();
        $surface = new SurfaceProbe();
        $surface->resize(320, 240, 1.0);
        $frame = new Frame($surface);

        $offstage = Offstage::make(new CounterWidget());
        $offElement = $offstage->createElement();
        $offElement->mount(null, $context);
        $hidden = $offElement->childAt(0)?->state();
        check('Routing · 可见 ⇒ 子层在树里', $offElement->childCount() === 1);
        check('Routing · 挂载即建出 State', $hidden !== null);

        $hidden?->bump(); // 标脏，先不重建
        $updated = Offstage::make($offstage->child(), false);
        $offElement->update($updated);
        check('Routing · 隐藏 ⇒ 子层不在树里（不 layout / 不 paint / flush 走不到）', $offElement->childCount() === 0);
        check('Routing · 隐藏**不卸载**（State 没被 dispose）', $hidden !== null && !$hidden->disposed);

        // 隐藏期间 Frame 不该重建它 —— 否则"跳过 layout"是假的
        $frame->run($offElement, BoxConstraints::tight(new Size(320.0, 240.0)), 64.0);
        check('Routing · 隐藏期间的 Frame 走不到它（脏标记留着）', $hidden !== null && $hidden->needsBuild());

        // 切回来 ⇒ 同一个 State（§12.3"保留"档的全部意义）
        $backElement = Offstage::make($offstage->child(), true);
        $offElement->update($backElement);
        $restored = $offElement->childAt(0)?->state();
        check('Routing · 切回来 ⇒ State 是同一个对象（状态没丢）', $restored === $hidden);
        // ★ 隐藏期间攒下的脏标记在**切回来那一刻就兑现**（update 内部 settle），
        //   不等下一帧、也不用谁手动 rebuild。这正是"切回来不是旧画面"的机制保证：
        //   若改成"留着等 Frame"，帧还没跑时画面就是旧的 —— 而且是静默的。
        check(
            'Routing · 切回来 ⇒ 隐藏期间攒的 setState 立即兑现',
            $hidden !== null && !$hidden->needsBuild() && $hidden->builds === 2 && $hidden->bumps === 1,
            'builds=' . (string) ($hidden?->builds ?? -1) . ' bumps=' . (string) ($hidden?->bumps ?? -1)
        );

        $this->flushChecks();
    }

    /**
     * ② 另一种"消失"是丢弃：整个 Offstage 被卸载 ⇒ State 必须 dispose（不许泄漏）。
     *
     * ★ 先可见再隐藏：隐藏子层拿不到引用（那正是"树里没有它"的证明），
     *   所以要在它还看得见时把 State 抓在手里。
     */
    public function testUnmountingHiddenPageDisposesState(): void
    {
        $context = smokeContext();

        $offstage = Offstage::make(new CounterWidget());
        $offElement = $offstage->createElement();
        $offElement->mount(null, $context);

        $doomed = Offstage::make(new CounterWidget());
        $doomElement = $doomed->createElement();
        $doomElement->mount(null, $context);
        $doomState = $doomElement->childAt(0)?->state();
        $offElement->update(Offstage::make($doomed->child(), false));
        $doomElement->update(Offstage::make($doomed->child(), false));
        $doomElement->unmount();
        check('Routing · 隐藏页被卸载 ⇒ State 仍走 dispose（不漏）', $doomState !== null && $doomState->disposed);

        $this->flushChecks();
    }
}
