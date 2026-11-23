<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 可激活控件：键盘 Enter/Space 与指针按下的共同落点。
 *
 * `InputRouter::activateFocusedWidget()` 是两条路径的唯一激活入口，靠本接口
 * 多态分发，不认具体控件（避免 instanceof 塔与子类顺序陷阱）。
 * 激活是行为、不传给第三方持有 ⇒ 用接口方法而非闭包；与返回数据的
 * `overlayPanelValue()` 性质不同。边界：只管激活，不开 `longPressable()` 等。
 *
 * @api
 */
interface Activatable
{
    /**
     * 激活自己。
     *
     * 契约：
     * - **幂等性归实现**（`ToggleBase` 在 disabled 时什么都不做，`Segment` 在已选中时
     *   什么都不做）—— 框架不预判"该不该激活"。
     * - **不抛异常**：这是输入环的同步路径，抛出去会中断整批事件（同 `pointer()` 缝）。
     * - `$context` 可以什么都不提供（`ActivationContext::none()`）⇒ 实现要能容忍。
     */
    public function activate(ActivationContext $context): void;
}
