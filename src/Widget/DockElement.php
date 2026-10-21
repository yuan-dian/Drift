<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderDock;

/**
 * `Dock` 的 Element：五个槽 = 五个子层（§13.2：容器与叶子共用同一套 diff）。
 *
 * ★ 顺序固定为 top → left → center → right → bottom：diff 按位置复用，
 *   `adoptBoxes()` 按同一顺序把子盒映射回槽位 —— 两处必须是同一个数组。
 *
 * @internal
 */
final class DockElement extends StatelessElement
{
    /** 槽位顺序（expand 与 adoptBoxes 共用，改一处必须改另一处） */
    private const ORDER = ['top', 'left', 'center', 'right', 'bottom'];

    #[\Override]
    protected function expand(): array
    {
        $dock = $this->widget;
        if (!$dock instanceof Dock) {
            return [];
        }

        $kids = [];
        foreach (self::ORDER as $slot) {
            $kid = $dock->slotKid($slot);
            if ($kid !== null) {
                $kids[] = $kid;
            }
        }

        return $kids;
    }

    #[\Override]
    protected function adoptBoxes(): void
    {
        $box = $this->renderObject();
        if (!$box instanceof RenderDock) {
            return;
        }

        $index = 0;
        foreach (self::ORDER as $slot) {
            if ($this->widget instanceof Dock && $this->widget->slotKid($slot) !== null) {
                $childBox = $this->childAt($index)?->effectiveBox();
                if ($childBox !== null) {
                    $box->setSlot($slot, $childBox);
                }
                $index++;
            }
        }
    }
}
