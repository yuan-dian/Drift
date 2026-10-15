<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;

/**
 * 带渲染盒的控件（§13.2 的 Widget → RenderBox 构造契约）。
 *
 * ★ **Widget 自己不持盒**：盒在 Element 挂载时由本方法造，配置更新时**重新造一个
 *   换掉**。于是"Widget 不可变 ⇒ 参数只会整体换新"这条性质保证了不存在字段级半同步
 *   —— GUI 仓"两份手写字段赋值 ⇒ 首次正常、刷新变样且静默"因此结构上不可能。
 *
 * ★ 放在这里而不是 `Widget` 基类上：结构节点（`Column` / `Row` / `ForEach` /
 *   `Spacer`）里有些不产盒（纯组合），基类留一个恒返回 null 的方法只会让
 *   `instanceof` 判断多一层。
 *
 * @api
 */
abstract class RenderBoxWidget extends Widget
{
    /**
     * 造本控件的渲染盒。**只读 Widget 自己的字段**（§13.2：字段对齐由 lint 比对）。
     *
     * 收 `BuildContext` 是因为有些控件的盒要**环境**才有形状（`Text` 要把语义角色
     * 经 `ThemeData` 解析成 `TextStyle`，还要拿到注入的度量）。不需要的控件忽略它 ——
     * 一个用不上的参数，换"所有渲染控件同一个签名"。
     *
     * 子盒不由这里挂 —— 那是 Element 的事（它才知道子 Element 的盒造好了没）。
     */
    abstract public function createRenderObject(BuildContext $context): RenderBox;
}
