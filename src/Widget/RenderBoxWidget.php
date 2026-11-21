<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Rendering\BoxStyle;

/**
 * 带渲染盒的控件（Widget → RenderBox 构造契约）。
 *
 * ★ **Widget 自己不持盒**：盒在 Element 挂载时由本方法造；配置更新时先问
 *   `updateRenderObject()` 能不能就地复用，判等为假才重新造一个换掉。于是
 *   "Widget 不可变 ⇒ 参数只会整体换新"这条性质仍然保证不存在字段级半同步
 *   —— GUI 仓"两份手写字段赋值 ⇒ 首次正常、刷新变样且静默"因此结构上不可能：
 *   复用的前提就是"配置逐字段等价"，不等就换盒，中间态不存在。
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
     * 造本控件的渲染盒。**只读 Widget 自己的字段**（字段对齐由 lint 比对）。
     *
     * 收 `BuildContext` 是因为有些控件的盒要**环境**才有形状（`Text` 要把语义角色
     * 经 `ThemeData` 解析成 `TextStyle`，还要拿到注入的度量）。不需要的控件忽略它 ——
     * 一个用不上的参数，换"所有渲染控件同一个签名"。
     *
     * 子盒不由这里挂 —— 那是 Element 的事（它才知道子 Element 的盒造好了没）。
     */
    abstract public function createRenderObject(BuildContext $context): RenderBox;

    /**
     * 把新配置写进**既有盒**，而不是再造一个。
     *
     * 返回 `true` = 已就地更新、盒可复用；`false` = `Element::syncBox()` 照旧走
     * `createRenderObject()` 换新盒。
     *
     * 默认认**零字段盒**（`RenderBox::isConfigurationFree()`）：那类盒的配置全在 `mods`
     * 与子层，复用零风险，且它们占页面节点的大头。有字段的盒必须逐控件覆写。
     *
     * ★ 覆写方的契约（缺一条都是静默错）：
     *   ① 几何相关字段变了 ⇒ 返回 `false`，**不要**自己 `markNeedsLayout()`；
     *   ② 样式 / 主题派生值变了 ⇒ 返回 `false`（就地改会漏重算 ⇒ 换主题后颜色不变）；
     *   ③ 只有"纯绘制量"（如 `Slider` 的 `value`）变化才返回 `true`，且不得动 `$size`。
     *
     * ★ 复用路径上盒的运行时态（悬停 / 按下）不会丢 —— 这也是不再需要
     *   `inheritRuntimeState()` 补丁的原因。
     *
     * @api 控件扩展点
     */
    public function updateRenderObject(RenderBox $box, BuildContext $context): bool
    {
        return $box->isConfigurationFree();
    }

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

    /**
     * 用户**没声明任何盒样式**时用的默认样式（主题派生）；`null` = 没有默认。
     *
     * ★ 默认与用户样式在 `Element::syncBox()` 同一处合成，控件不必自持样式字段；
     *   只靠 `createRenderObject()` 自己合并时，`Modifier` 新增视觉维不会自动流过去
     *   （漏了不报错，只是那个维度静默不生效）。
     * ★ 只对不自绘 chrome 的控件生效；`paintsBoxChrome() === true` 的控件自己合并，
     *   再叠一层默认会打架。
     * ★ 判据是 `BoxStyle::isBlank()`（用户一个字段都没设过），不是 `isNone()`：
     *   `->radius(4)` 也算设过 ⇒ 整套默认让位。见 `Modifier::withBoxStyleIfBlank()`。
     *
     * @internal 只给 Element 用；应用不该调
     */
    public function defaultBoxStyle(BuildContext $context): ?BoxStyle
    {
        return null;
    }
}
