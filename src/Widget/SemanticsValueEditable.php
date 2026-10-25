<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 控件接受"语义层写入一个值"（UIA `IValueProvider::SetValue` /
 * `IRangeValueProvider::SetValue`）。
 *
 * ★ 与 `Activatable` 同构：框架不认具体控件，只认能力 —— 读屏设值与用户输入
 *   走同一条回写路径（实现方内部调自己的 `onChanged` / `emit()`），应用不必为
 *   无障碍多写一份。
 * ★ **受控控件也是这条**：值归应用 State，框架只调回调；应用没接 `onChanged`
 *   ⇒ 返回 `false`，消费方据此回 UIA 错误码，**不假装成功**。
 * ★ 与 `Activatable` 的分工：那个是"点一下/回车"（无载荷），这个是"把值改成 X"
 *   （带载荷）。`Toggle()` 走前者（Checkbox 的 activate 就是换值），
 *   `SetValue()` 走后者。
 *
 * @api
 */
interface SemanticsValueEditable
{
    /**
     * @param string $value UIA 给的值（数值型 Pattern 由壳格式化后传入）
     * @return bool 是否接受（无回调 / 非数字 / 禁用 ⇒ false）
     */
    public function setSemanticsValue(string $value): bool;
}
