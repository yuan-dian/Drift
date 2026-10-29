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

    /**
     * 本控件的盒**自己画底 / 边 / 圆角**吗（画在 `paintOwnChrome()` 里）？
     *
     * `true` ⇒ `Element::syncBox()` 交给盒的 `$mods` 会**剥掉**视觉三字段
     * （`Modifier::withoutBoxVisual()`），否则 `RenderBox::paint()` 的 ① 会跟着画一遍。
     *
     * ★★ **描边不幂等** —— 同色画两遍不等于画一遍（`Paint::stroke` 带抗锯齿，
     *   实测 golden 差 32 / 322 像素，全在边框条带上）。别用"看起来一样"抵赖：
     *   底色幂等、描边不幂等，恰好是最容易漏的那一半。
     *
     * ★ **声明 `true` 的控件要自己把用户设的样式在 `createRenderObject()` 里合并进
     *   `BoxStyle`**（`Button` / `PopupPanel` 读 `$mods->boxStyle()`）。只声明不合并
     *   ⇒ 用户的 `->background()` 变成死字段（静默），比双画更糟。
     *
     * @internal 只给 Element 用；应用不该调
     */
    public function paintsBoxChrome(): bool
    {
        return false;
    }

    /**
     * 本控件**自己画禁用外观**吗？
     *
     * `true` ⇒ `Element::syncBox()` 会给盒的 `$mods` 打 `withoutDim()`：
     * 禁用时**不**再乘 `RenderBox::DISABLED_ALPHA`。
     *
     * ★ 为什么需要这个开关：`disabled()` 的默认外观是"整棵子树乘 0.38"（通用、零成本），
     *   但有些控件有更好的表达 —— `ToggleBase` / `Slider` / `Segment` 把标签色从
     *   `ColorToken::Text` 换成 `Muted`（**灰**而不是**淡**）。两者叠加会二次衰减：
     *   已经变灰的字再乘 0.38，在浅底上几乎看不见。
     * ★ 与 `paintsBoxChrome()` 同一条形状（声明在控件 / 落实在 `syncBox()` / 消费在盒），
     *   而不是在 `RenderBox` 里 instanceof 判类 —— 后者每加一个控件就要改框架。
     *
     * ★ 声明 `true` 的控件有**义务**在 `createRenderObject()` 里真的按禁用换色，
     *   否则禁用会变成"点不动但看起来完全正常"（比不禁用更误导）。
     *
     * @internal 只给 Element 用；应用不该调
     */
    public function paintsDisabledLook(): bool
    {
        return false;
    }
}
