<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

use Drift\App\FocusManager;
use Drift\Dev\Smoke\App\PointerApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\CursorShape;
use Drift\Input\PointerEvent;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Element;

/**
 * 悬停派发（§9.1 语义 #5）：**目标变化才通知**。
 *
 * ## 两条独立判据（`FocusManager::hoverTo()` 里各走各的）
 *
 * | 判据 | 认什么 | 本文件的方法 |
 * |---|---|---|
 * | 悬停**回调** | 祖先链上声明了 `hover()` 的 Element | `testHoverNotifiesOnlyOnTargetChange` |
 * | 悬停**视觉态** | 祖先链上**第一个** `acceptsHover()` 的盒 | `testHoverStateGoesToTheAcceptingBox` |
 *
 * 两条都命中同一个 Button 是常态 ⇒ 只测一条会让人以为另一条也被覆盖了。
 * 视觉态那条的失效形态是"按钮悬停毫无反馈"（不报错、不写日志）。
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

    /**
     * 悬停**视觉态**：状态推给祖先链上第一个 `acceptsHover()` 的盒。
     *
     * ★ 判据落在**盒的状态**上（`hovered()`），不是"方法被调用"：画不画由盒的
     *   `paintContent()` 决定 —— 状态没推对，视觉一定不对。
     * ★ `acceptsHover()` 必须**成对**验：只验"按钮接受了"会奖励"容器也接受"的实现
     *   （悬停在列上整块变亮）。`applyHoverState()` 走的是"第一个接受的盒"，
     *   容器一旦声明，按钮就永远收不到。
     * ★ 起点那条（`hovered() === false`）不是废话：没有它，后面两条"复位"在
     *   "`setHovered()` 从未被调用过"的实现上照样绿。
     */
    public function testHoverStateGoesToTheAcceptingBox(): void
    {
        $root = Column::make()->children([Button::make('悬停我')])->createElement();
        $root->mount(null, smokeContext());
        $root->effectiveBox()?->layout(BoxConstraints::loose(new Size(320.0, 200.0)));

        $btnEl = findElement($root, static fn(Element $e): bool => $e->widget() instanceof Button);
        $btnBox = $btnEl?->effectiveBox();
        $colBox = $root->effectiveBox();

        check(
            '悬停 · RenderButton 声明了状态色 ⇒ acceptsHover=true',
            $btnBox !== null && $btnBox->acceptsHover() === true,
            $btnBox === null ? '按钮没盒' : 'acceptsHover=false'
        );
        check(
            '悬停 · 布局容器不声明状态色 ⇒ acceptsHover=false（成对：否则停在列上也整块变亮）',
            $colBox !== null && $colBox->acceptsHover() === false,
            $colBox === null ? '根没盒' : 'acceptsHover=true'
        );
        check(
            '悬停 · 起点：按钮还没被悬停（否则下面两条"复位"恒真）',
            $btnBox?->hovered() === false,
            $btnBox === null ? '按钮没盒' : 'hovered=true'
        );

        $fm = new FocusManager();
        $fm->hoverTo($btnEl);
        check(
            '悬停 · hoverTo(Button) ⇒ 它的盒 hovered=true',
            $btnBox?->hovered() === true,
            $btnBox === null ? '按钮没盒' : 'hovered=false'
        );

        $fm->hoverTo($root);
        check(
            '悬停 · 悬停容器 ⇒ 按钮复位（容器没有盒接受，列不会整块变色）',
            $btnBox?->hovered() === false,
            $btnBox === null ? '按钮没盒' : 'hovered=true'
        );

        $fm->hoverTo($btnEl);
        $fm->hoverTo(null);
        check(
            '悬停 · hoverTo(null) ⇒ 复位（离开窗口不留"常亮"）',
            $btnBox?->hovered() === false,
            $btnBox === null ? '按钮没盒' : 'hovered=true'
        );
    }
}
