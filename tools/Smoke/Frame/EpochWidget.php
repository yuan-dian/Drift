<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Frame;

use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：**有状态根 + 产物有渲染盒**。
 *
 * ★ 为什么不用 `TickerWidget`：它的产物是 `LeafProbe`（纯数据探针，不产盒）⇒
 *   `Frame::run()` 拿 `effectiveBox()` 得到 null 直接返回，排不排版无从观测。
 *   这个夹具的产物是 `Text`（`TextBox`）⇒ "排过没有"可以用 `hasSize()` 直接判。
 */
final class EpochWidget extends StatefulWidget
{
    public function createState(): State
    {
        return new EpochState();
    }
}

/** @internal 见 `EpochWidget` */
final class EpochState extends State
{
    public function build(BuildContext $context): Widget
    {
        return Text::make('epoch');
    }
}
