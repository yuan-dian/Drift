<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

use Drift\Dev\Smoke\App\MenuApplication;
use Drift\Dev\Smoke\Overlay\FlatBox;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Layout\RenderFlex;
use Drift\Widget\Drag\Draggable;
use Drift\Widget\Drag\DragTarget;
use Drift\Widget\Menu\ContextMenu;

/**
 * 应用内拖放（§A.3.5）：状态机能被指针序列驱动，且"没动就不通知"。
 *
 * ★ 判据里最容易写假绿的是"目标不变 ⇒ 不重复通知"：只验 `hover === [true]`
 *   在**从不通知**的实现下也成立 ⇒ 必须前一步真的产生过 `true`。
 * ★ 三条声明位（`draggable` / `contextMenu` / `dragTarget`）同在一处断言：它们
 *   是同一批加到 `Widget` 基类的声明缝，缺一条另一条也会跟着读不到。
 */
final class DragDropTest extends SmokeTestCase
{
    /** @return array{0: MenuApplication, 1: array{0: float, 1: float}, 2: array{0: float, 1: float}} */
    private static function boot(): array
    {
        $app = new MenuApplication();
        $app->attach(
            new \Drift\Dev\Smoke\Overlay\RecordingSurface(),
            BoxConstraints::tight(new Size(320.0, 240.0)),
        );
        $app->frame(16.0, BoxConstraints::tight(new Size(320.0, 240.0)));

        // 根 Column = [菜单栏 · 可右键+可拖的行 · 放置目标行]
        $colBox = $app->rootBox();
        $rows = $colBox instanceof RenderFlex ? $colBox->children() : [];
        $rowBox = $rows[1] ?? null;
        $targetBox = $rows[2] ?? null;

        return [
            $app,
            $rowBox === null ? [0.0, 0.0] : boxOrigin($rowBox),
            $targetBox === null ? [0.0, 0.0] : boxOrigin($targetBox),
        ];
    }

    public function testDragSequenceNotifiesEnterLeaveAndDrop(): void
    {
        [$app, [$rx, $ry], [$sx, $sy]] = self::boot();

        $app->beginDrag(Draggable::make('user-7'));
        check('拖放 · beginDrag ⇒ 进入拖动态', $app->drag()->isDragging());

        $app->updateDrag($sx + 5.0, $sy + 5.0);
        check('拖放 · 进入 DragTarget ⇒ onHover(true)', $app->hover === [true]);

        $app->updateDrag($sx + 9.0, $sy + 9.0);
        check('拖放 · 目标不变 ⇒ 不重复通知（§9.1 语义 #5 同源）', $app->hover === [true]);

        $app->updateDrag($rx + 5.0, $ry + 5.0);
        check('拖放 · 离开目标 ⇒ onHover(false)', $app->hover === [true, false]);

        $app->updateDrag($sx + 5.0, $sy + 5.0);
        check(
            '拖放 · drop 把负载交给命中的 DragTarget',
            $app->dropDrag() === true && $app->drop === ['user-7'],
        );
        check('拖放 · drop 后退出拖动态', !$app->drag()->isDragging());
    }

    public function testDropOnEmptySpaceReturnsFalseAndEscCancels(): void
    {
        [$app, [$rx, $ry]] = self::boot();
        $before = count($app->drop);

        $app->beginDrag(Draggable::make('user-9'));
        $app->updateDrag($rx + 5.0, $ry + 5.0); // 拖到没有目标的地方
        check(
            '拖放 · 拖空 ⇒ drop 返回 false（不派发 onAccept）',
            $app->dropDrag() === false && count($app->drop) === $before,
            'drop=' . count($app->drop),
        );

        $app->beginDrag(Draggable::make('user-9'));
        $app->dispatchInput(KeyEvent::down(KeyChord::escape()));
        check(
            '拖放 · ESC 取消（不派发 onAccept）',
            !$app->drag()->isDragging() && count($app->drop) === $before,
            'drop=' . count($app->drop),
        );
    }

    public function testThreeDeclarationSlotsLiveOnWidget(): void
    {
        check(
            '声明 · draggable / contextMenu / dragTarget 落在 widget 上（可被宿主读回）',
            FlatBox::make(10.0, 10.0)->draggable(Draggable::make(1))->draggableValue()?->payload() === 1
                && FlatBox::make(10.0, 10.0)->dragTarget(DragTarget::make())->dragTargetValue() instanceof DragTarget
                && FlatBox::make(10.0, 10.0)->contextMenu(ContextMenu::make())->contextMenuValue() instanceof ContextMenu,
        );
    }
}
