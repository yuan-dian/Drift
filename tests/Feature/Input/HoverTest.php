<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

use Drift\Dev\Smoke\App\PointerApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\CursorShape;
use Drift\Input\PointerEvent;

/**
 * 悬停派发（§9.1 语义 #5）：**目标变化才通知**。
 *
 * ## 判据为什么成对
 *
 * "同目标再 move ⇒ 不重复派发"单独看是恒真的（一个**从不派发**的实现也满足）。
 * 必须前面真的产生过 `enter === 1`，这一条才有意义 —— 所以本文件按
 * `enter → 不重复 → leave → 不重复 leave` 的顺序走同一条状态机，
 * 每一步都在前一步的计数上断言增量。
 *
 * ## 光标那一方（§9.1「一次命中，三方消费」的第 ③ 方）
 *
 * `dispatchInput(move)` 的**返回值**是解析出的光标形状 —— 这是应用层出口。
 * ★ 与 `CursorShapeTest` 分工：那边判**解析**（`FocusManager::cursorOf()`：
 *   形状表 / 声明落到盒 / 命中链取最近者），这边判**出口**（一次真 move 之后
 *   应用拿到什么）。"系统光标真的变了"只能真机判（`GetCursorInfo`），两边都不假装。
 */
final class HoverTest extends SmokeTestCase
{
    private static function open(): PointerApplication
    {
        $app = new PointerApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(300.0, 200.0)));

        return $app;
    }

    /**
     * 一条连续的指针轨迹走完四个状态，每步只断言**增量**。
     *
     * 坐标来自 `PointerApplication` 的确定性几何（三块 40×40 竖排）：
     * (20,20) 悬停目标 / (20,60) 拖放源 / (20,100) 拖放目标。
     */
    public function testHoverNotifiesOnlyOnTargetChange(): void
    {
        $app = self::open();

        $shape = $app->dispatchInput(PointerEvent::move(20.0, 20.0));
        check(
            'Pointer · move 命中悬停目标 ⇒ enter 派发一次',
            $app->hoverEnters === 1 && $app->hoverLeaves === 0,
            'enter=' . $app->hoverEnters . ' leave=' . $app->hoverLeaves
        );
        check(
            'Pointer · move 刷新光标（命中声明 PointingHand 的盒）',
            $shape === CursorShape::PointingHand,
            $shape === null ? 'null' : $shape->name
        );

        $app->dispatchInput(PointerEvent::move(21.0, 22.0));   // 同目标内移动
        check(
            'Pointer · 同目标再 move ⇒ 不重复派发（§9.1 语义 #5）',
            $app->hoverEnters === 1,
            'enter=' . $app->hoverEnters
        );

        $app->dispatchInput(PointerEvent::move(20.0, 60.0));   // 移到无悬停声明的源盒
        check(
            'Pointer · 移出悬停目标 ⇒ leave 派发一次',
            $app->hoverLeaves === 1 && $app->hoverEnters === 1,
            'enter=' . $app->hoverEnters . ' leave=' . $app->hoverLeaves
        );

        $app->dispatchInput(PointerEvent::leave(20.0, 60.0));
        check(
            'Pointer · leave（已在目标外）⇒ 不重复 leave',
            $app->hoverLeaves === 1,
            'leave=' . $app->hoverLeaves
        );
    }
}
