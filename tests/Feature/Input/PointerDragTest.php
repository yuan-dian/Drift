<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

use Drift\Dev\Smoke\App\PointerApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;

/**
 * 应用内拖放的状态机（§A.3.5）：**按下 ≠ 拖动**。
 *
 * ## 为什么"阈值"值得单独钉
 *
 * 没有阈值的话，任何一次点击都会先起一次拖动再放下 —— 用户点一下按钮，界面上
 * 闪一下拖影、日志里多一条 drop，而**功能看起来是对的**。阈值把"点"与"拖"
 * 分开，而这个分界点在实现里只是一个 `<` 号，改错不报错。
 *
 * ## 为什么"拖出窗口"必须有一条
 *
 * 指针手势的收口信号**全部来自指针自己**（`up` / `leave`）。拖到一半把指针甩出
 * 客户区，`up` 永远不会来 —— 状态机若只认 `up`，就会永久停在"拖动中"：之后
 * 任何一次点击都被当成拖动的延续，那个控件永久只收它自己的事件。
 *
 * ★ 与 `DragDropTest` 分工：那边判**声明缝**（`draggable` / `contextMenu` /
 *   `dragTarget` 三个位能不能读到、目标不变时不重复通知），宿主是 `MenuApplication`；
 *   这边判**状态机的相位**（阈值 / 起拖 / 放下 / 取消），宿主是 `PointerApplication`
 *   的确定性几何（三块 40×40 竖排，不赌字模）。
 */
final class PointerDragTest extends SmokeTestCase
{
    private static function open(): PointerApplication
    {
        $app = new PointerApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(300.0, 200.0)));

        return $app;
    }

    /**
     * 阈值两侧 + 放下交 payload + 状态清空。
     *
     * 坐标：(20,60) 是拖放源，(20,100) 是拖放目标。
     * `move(22,62)` 的位移 ≈ 2.83 < 6 阈值；`move(20,100)` 的位移 40 > 阈值。
     */
    public function testThresholdGatesDragStart(): void
    {
        $app = self::open();
        $app->dispatchInput(PointerEvent::down(20.0, 60.0));   // 落在拖放源上
        $app->dispatchInput(PointerEvent::move(22.0, 62.0));   // 位移 ~2.83 < 6
        check(
            'Pointer · 拖放：小位移（< 阈值）⇒ 不开始拖动（按下 ≠ 拖动）',
            $app->drag()->isDragging() === false,
            'dragging=' . \var_export($app->drag()->isDragging(), true)
        );

        $app->dispatchInput(PointerEvent::move(20.0, 100.0));  // 位移 40 > 阈值
        check(
            'Pointer · 拖放：大位移 ⇒ 开始拖动且目标 enter 派发',
            $app->drag()->isDragging() === true && $app->targetEnters === 1,
            'dragging=' . \var_export($app->drag()->isDragging(), true)
                . ' targetEnter=' . $app->targetEnters
        );

        $app->dispatchInput(PointerEvent::up(20.0, 100.0));
        check(
            'Pointer · 拖放：up ⇒ 放下，payload 原样交出',
            $app->dropped === true && $app->droppedPayload === 'payload-1',
            'dropped=' . \var_export($app->dropped, true)
                . ' payload=' . \var_export($app->droppedPayload, true)
        );
        check(
            'Pointer · 拖放：放下后状态清空（可再次起拖）',
            $app->drag()->isDragging() === false,
            'dragging=' . \var_export($app->drag()->isDragging(), true)
        );
    }

    /**
     * 拖出客户区 ⇒ 取消（不卡死、不放下）。
     *
     * ★ 第一条是**前置条件**，不是凑数：`leave` 只在"拖动中"才有意义，
     *   若起拖那步已经坏了，后面那条"取消了"会因为"本来就没在拖"而假绿。
     */
    public function testLeaveCancelsDrag(): void
    {
        $app = self::open();
        $app->dispatchInput(PointerEvent::down(20.0, 60.0));
        $app->dispatchInput(PointerEvent::move(20.0, 100.0));
        check(
            'Pointer · 拖放：拖动中（leave 取消的前置条件）',
            $app->drag()->isDragging() === true,
            'dragging=' . \var_export($app->drag()->isDragging(), true)
        );

        $app->dispatchInput(PointerEvent::leave(20.0, 100.0));
        check(
            'Pointer · 拖放：指针离开客户区 ⇒ 取消（不卡死、不放下）',
            $app->drag()->isDragging() === false
                && $app->dropped === false
                && $app->targetLeaves === 1,
            'dragging=' . \var_export($app->drag()->isDragging(), true)
                . ' dropped=' . \var_export($app->dropped, true)
                . ' targetLeave=' . $app->targetLeaves
        );
    }
}
