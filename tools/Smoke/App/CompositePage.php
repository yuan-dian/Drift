<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：**组合根** —— 自己没有渲染盒，底下的 Column 才有。
 *
 * §A.2 的 `UsersRoot` 正是这个形态（应用根 = 一个 StatelessWidget）。
 * 它是 `Frame::run` 那条"整屏空白且不报错"缺陷的最小复现。
 */
final class CompositePage extends StatelessWidget
{
    public function build(BuildContext $context): Widget
    {
        return Column::make()->children([
            Text::make('组合根 A'),
            Text::make('组合根 B'),
        ]);
    }
}
