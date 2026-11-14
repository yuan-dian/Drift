<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * **可激活**的控件（键盘 Enter/Space、指针按下 的落点）。
 *
 * ★ 存在的理由只有一条：`InputRouter::activateFocusedWidget()` 是键盘与指针
 *   两条路径的**唯一**激活入口，它以前靠一条 `instanceof` 塔认四种控件
 *   （`Menu` / `ToggleBase` / `Segment` / `Button`）—— 框架核心去认具体控件 =
 *   依赖方向反了，`Scrollable` / `EditableText` 的同款理由。
 *
 * ★ **顺序脆弱一并消失**：`Menu extends Button`，旧代码必须把 `Menu` 分支写在
 *   `Button` 之前（注释自己写着"子类会被 `instanceof` 认成父类"）。改成接口后
 *   `Menu` **覆写** `activate()` ⇒ 多态天然优先，不需要顺序、不需要注释提醒。
 *   `MenuItem`（也 extends Button）不覆写 ⇒ 走 `Button` 的语义，正确。
 *
 * ★ **为什么是接口方法，而不是 `activationBehavior(): ?\Closure`**：
 *   本仓已有 `overlayPanelValue(): ?\Closure` 这个"控件自报能力"的先例，但两者
 *   性质不同 ——
 *
 *   - `overlayPanelValue()` 返回的是**数据**（面板工厂），它要被**传递**给浮层栈，
 *     由别人持有并调用 ⇒ 闭包是合适的载体；
 *   - 激活是**行为**，谁来做这件事就是控件自己，没有"传递给别人"的需求。
 *
 *   把行为包成闭包再让框架 `($handler)($element, $host)` 调用，等于用闭包模拟
 *   虚方法：类型系统不知道"这是激活"，接口文档无处安放，实现类也不能只覆写
 *   其中一段。本仓其它能力接口（`EditableText` / `Scrollable` / `Job` /
 *   `NamedJob`）都是同一形状，**同构**。
 *
 * ★ 边界：本接口只管"激活"这一个动作。**不开** `longPressable()` / `focusable()`
 *   之类 —— 没有第二种激活时机，加了就是"未被消费的分支 = 未实现"。
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
