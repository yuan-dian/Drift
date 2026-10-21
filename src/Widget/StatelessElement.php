<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * `StatelessWidget` 的 Element（§3.2）。
 *
 * 每次重建都重跑 `build()` 拿一棵新子树，然后交给同一套 diff —— 所以
 * `StatelessWidget` 里**不该放 UI 态**：没入口通知它重跑（要记状态请用
 * `StatefulWidget`，§11.5）。
 *
 * ★ **不是 `final`**：容器控件（`Column` / `Row`）的 Element 正是要覆写
 *   `expand()` 给多子层，diff 逻辑一行都不重写。
 *
 * @api
 */
class StatelessElement extends Element
{
    public function rebuild(): void
    {
        $this->updateChildren($this->expand());
        $this->adoptBoxes();
    }

    #[\Override]
    protected function expand(): array
    {
        $widget = $this->widget;
        if ($widget instanceof StatelessWidget) {
            return [$widget->build($this->context)];
        }

        // 结构节点没有 build() —— 由它自己的 Element 覆写 expand() 给子层
        return [];
    }
}
