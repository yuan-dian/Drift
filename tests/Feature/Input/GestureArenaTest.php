<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

use Drift\Dev\Smoke\App\GestureApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;
use Drift\Widget\Button;
use Drift\Widget\Element;

/**
 * 手势消歧（`GestureArena`，§9.1）：可拖 + 可点击的控件上，**tap 与 drag 谁赢**。
 *
 * ## 修的是个真缺陷
 *
 * 此前 `down` 即激活：可拖控件被拖一下，命令也照发一次（"拖完松手，文件移动了
 * 一遍、还顺手打开了它"）。竞技场把激活从 `down` **推迟到裁决** —— 过阈值
 * ⇒ `Drag` claim ⇒ `Tap` 让位；没过阈值就 `up` ⇒ `Tap` 胜 ⇒ 激活。
 *
 * ## 三条判据缺一不可
 *
 * ① 过阈值拖动 ⇒ 不误激活（修的那个缺陷）；
 * ② 拖动后 up ⇒ 仍不激活（"松手才激活"的实现也满足 ①，只有 ② 能钉住）；
 * ③ **未过阈值的 up ⇒ 激活**（防"为了修 ① 干脆把激活也砍了"的过度修正）。
 *
 * ★ 只有 ①② 会奖励"从不激活"的实现；只有 ③ 会奖励"down 即激活"的老实现。
 *   三条一起才把"裁决"这件事钉死。
 * ★ 与 `PointerCaptureTest` 分工：那边判捕获的**收口**（何时放手），
 *   这边判**裁决**（谁赢）。
 */
final class GestureArenaTest extends SmokeTestCase
{
    private const W = 300.0;

    private const H = 200.0;

    private static function open(): GestureApplication
    {
        $app = new GestureApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(self::W, self::H)));

        return $app;
    }

    /** 元素中心点（根坐标） */
    private static function centerOf(?Element $el): array
    {
        $box = $el?->effectiveBox();
        if ($box === null || $box->size() === null) {
            return [0.0, 0.0];
        }
        [$x, $y] = boxOrigin($box);

        return [$x + $box->size()->width / 2.0, $y + $box->size()->height / 2.0];
    }

    private static function buttonWith(GestureApplication $app, bool $draggable): ?Element
    {
        return findElement(
            $app->root(),
            static fn(Element $e): bool => $e->widget() instanceof Button
                && ($e->widget()->draggableValue() !== null) === $draggable
        );
    }

    /**
     * 可拖控件：过阈值拖动 ⇒ 拖动真的起来了，且命令**一次都没发**；松手也不发。
     *
     * ★ 第二条（"拖动后 up ⇒ 仍不激活"）不是重复：若实现把激活改到 `up` 上，
     *   第一条照样绿（拖动中确实没激活），第二条才红。
     */
    public function testDragClaimSuppressesActivation(): void
    {
        $app = self::open();
        [$cx, $cy] = self::centerOf(self::buttonWith($app, true));

        $app->dispatchInput(PointerEvent::down($cx, $cy));
        $app->dispatchInput(PointerEvent::move($cx + 40.0, $cy));   // 位移 40 > 6 阈值
        check(
            'Gesture · 可拖控件：过阈值拖动 ⇒ 开始拖动且不误激活命令（拖动中 tap 让位）',
            $app->drag()->isDragging() === true && $app->dragPresses === 0,
            'dragging=' . \var_export($app->drag()->isDragging(), true)
                . ' dragPresses=' . $app->dragPresses
        );

        $app->dispatchInput(PointerEvent::up($cx + 40.0, $cy));   // 无 DragTarget ⇒ 退出拖动态
        check(
            'Gesture · 可拖控件：拖动后 up ⇒ 仍不激活（命令只在 tap 胜时）',
            $app->dragPresses === 0,
            'dragPresses=' . $app->dragPresses
        );
    }

    /** 未过阈值的 up ⇒ Tap 胜 ⇒ 激活（可拖控件上的点击仍然可用） */
    public function testTapWinsBelowThreshold(): void
    {
        $app = self::open();
        [$cx, $cy] = self::centerOf(self::buttonWith($app, true));

        $app->dispatchInput(PointerEvent::down($cx, $cy));
        $app->dispatchInput(PointerEvent::move($cx + 2.0, $cy + 2.0));   // 位移 ~2.83 < 6
        $app->dispatchInput(PointerEvent::up($cx + 2.0, $cy + 2.0));
        check(
            'Gesture · 可拖控件：未过阈值的 up ⇒ tap 胜 ⇒ 激活',
            $app->dragPresses === 1,
            'dragPresses=' . $app->dragPresses
        );
    }

    /**
     * 非可拖控件 ⇒ `down` 即激活（A15 不动，防回归）。
     *
     * ★ 这条是**反向守卫**：把激活统一推迟到 `up` 会让所有普通按钮都变成
     *   "松手才响应"，手感变差且这条会红 —— 竞技场只对"命中链上有 `Draggable`"
     *   的场合开。
     */
    public function testPlainButtonStillActivatesOnDown(): void
    {
        $app = self::open();
        [$cx, $cy] = self::centerOf(self::buttonWith($app, false));

        $app->dispatchInput(PointerEvent::down($cx, $cy));
        check(
            'Gesture · 非可拖控件：down 即激活（防回归）',
            $app->plainPresses === 1,
            'plainPresses=' . $app->plainPresses
        );
    }
}
