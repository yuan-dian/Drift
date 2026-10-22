<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;

/**
 * 纯转发盒：唯一的孩子铺满内容盒，自己**不施加任何几何**（arch-v2 `02` §11，W5）。
 *
 * ```php
 * // `Padding` / `SizedBox` / `Center` 现在都产它 —— 内距、尺寸、居中由 `mods`
 * // 递到基类，`RenderBox::layout()` 的 ① / ② / ③ / ⑤ / ⑦ 五步施加。
 * $box = new RenderProxy();
 * ```
 *
 * ## 为什么它存在
 *
 * W5 的判据是「修饰**是字段**，不是节点」：`padding` / `width` / `height` /
 * `center` 都不是"套一层盒"，而是盒上的字段。于是"只为了施加一个内距而造出来的
 * 盒"没有存在理由 —— 但**代理盒本身**还需要一个具体类（`RenderProxyBox` 是抽象
 * 的，`ProxyElement::adoptBoxes()` 又要求盒能 `setChild()`）。
 *
 * 于是五个修饰盒（`RenderPadding` / `RenderSizedBox` / `RenderCenter` /
 * `RenderFlexible` / `RenderCard`）里，**前三个**收敛成本类 + `mods`：
 *
 * | 原类 | 原来自己做的事 | 现在由谁做 |
 * |---|---|---|
 * | `RenderPadding` | `deflate` / `inflate` / 子偏移 `(padL, padT)` | 基类 ②⑤ + `paint()` 的 translate |
 * | `RenderSizedBox` | 逐轴夹取 + 紧约束 | `Modifier::tighten()`（①，同一套夹取规则） |
 * | `RenderCenter` | `loosen()` + 按上限算偏移 | 基类 ③ + ⑦（按 `size − measured`） |
 *
 * `RenderFlexible` **不**并进来：它要带 flex 份额，而份额此刻仍在构造器里写
 * （`RenderBox::setMods()` 接上 `setFlexIntent()` 的时机是 M2-5）。
 *
 * ## 为什么 `setOffset(0, 0)` 要显式写
 *
 * `performLayout()` 的契约是"**给子节点 `layout()` 并 `setOffset()`**"。省略它
 * 在"盒是新建的"这一路能过（字段初值就是 0），但在**复用旧盒**的重排里，孩子可能
 * 还留着上一轮的位置 —— 那是"只有 resize 之后才错"的一类失败。写出来是一行。
 *
 * @api
 */
final class RenderProxy extends RenderProxyBox
{
    protected function performLayout(BoxConstraints $constraints): Size
    {
        $child = $this->child;
        if ($child === null) {
            return $constraints->constrain(new Size(0.0, 0.0));
        }

        $child->layout($constraints);
        $child->setOffset(0.0, 0.0);

        $size = $child->size();
        if ($size === null) {
            // 不可能：`layout()` 返回前一定写了 `size`。留着是为了 AOT 能推成
            // `Size` 而不是 `?Size`（`constrain()` 收的是非空值）。
            return $constraints->constrain(new Size(0.0, 0.0));
        }

        return $constraints->constrain($size);
    }
}
