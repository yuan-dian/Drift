<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\State;

use Drift\Dev\Smoke\State\HoverWidget;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\State\Cell;

/**
 * `Cell`：State 内部的高频 UI 态（§11.3.1 / RT-4）。
 *
 * ★ 判据用 `HoverState::$builds` 这类**外部可观测量**才不自证：`build()` 自己不读
 *   Cell 的值，"设了同值不重建"若只查 Cell 自己的值，漏掉 `===` 比较也照样绿。
 * ★ 构造私有 ⇒ 未绑定的 Cell **造不出来**（比"造出来再炸"更早失败）。
 */
final class CellTest extends SmokeTestCase
{
    public function testSetIfChangedMarksDirtyOnlyOnRealChange(): void
    {
        $ctx = smokeContext();

        $hoverElement = (new HoverWidget())->createElement();
        $hoverElement->mount(null, $ctx);
        $hoverState = $hoverElement->state();
        check('State · initState 只跑一次（重复挂载不重复订阅）', $hoverState->inits === 1, '实际 ' . $hoverState->inits);

        $hoverState->onRowHover(2);
        check('State · Cell 换值 ⇒ 标脏', $hoverState->needsBuild(), '没有标脏');

        $hoverElement->rebuild();
        $hoverState->onRowHover(2); // 同一行
        check('State · Cell 设同值不标脏（RT-4：=== 严格比较）', !$hoverState->needsBuild(), '同值也标了脏');

        $hoverState->onRowHover(3);
        check('State · Cell 换新值 ⇒ 再标脏', $hoverState->needsBuild(), '换值没标脏');

        $hoverElement->unmount();

        $this->flushChecks();
    }

    public function testConstructorIsPrivate(): void
    {
        // Cell 构造私有 ⇒ new 不出来（W2/S2）。进程内断言：私有构造抛的是**可捕获的
        // Error**，不需要子进程（原来的"未绑定 ⇒ Fatal"用例随 makeFor 一起退役 ——
        // 未绑定的 Cell 现在**造不出来**，比造出来再炸更早）。
        try {
            new Cell(1);
            check('State · Cell 构造函数私有 ⇒ new 不出来', false, 'new Cell(1) 竟然成功了');
        } catch (\Error $e) {
            check(
                'State · Cell 构造函数私有 ⇒ new 不出来',
                str_contains($e->getMessage(), 'private'),
                $e->getMessage()
            );
        }

        $this->flushChecks();
    }

    public function testStateCellBindsOwner(): void
    {
        $ctx = smokeContext();

        // State::cell() 仍可用，且拿到的 Cell 真的绑在本 State 上（makeFor 的回归面）
        $cellElement = (new HoverWidget())->createElement();
        $cellElement->mount(null, $ctx);
        $cellState = $cellElement->state();
        $madeCell = $cellState->cell(7);
        check('State · State::cell() 仍可用（走 makeFor）', $madeCell->value() === 7);

        $madeCell->setIfChanged(8);
        check('State · makeFor 建的 Cell 绑对了 Owner（标脏到本 State）', $cellState->needsBuild(), '没有标脏');
        $cellElement->unmount();

        $this->flushChecks();
    }
}
