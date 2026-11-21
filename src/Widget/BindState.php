<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;

/**
 * `Bind` 的 State：**只做一件事** —— 调 builder。
 *
 * 依赖采集与订阅在基座 `State` / `StatefulElement` 里（所有 State 共用一套），
 * 所以这里没有任何订阅代码。这也是本机制与 `SubjectBuilder` 的结构差别：
 * 那边是"每个控件自己实现一次订阅"，这边是"框架在 build 前后统一采集"。
 *
 * @api
 */
final class BindState extends State
{
    public function build(BuildContext $ctx): Widget
    {
        $widget = $this->widget();
        if (!$widget instanceof Bind) {
            // 框架不变量：StatefulElement 按 get_class 配对，配错不可能发生
            Errors::report('BindState 只能配 Bind（框架内部不变量被破坏）');

            return SizedBox::make();
        }

        return ($widget->builderClosure())();
    }
}
