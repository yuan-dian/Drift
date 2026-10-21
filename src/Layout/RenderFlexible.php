<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;

/**
 * 弹性项（`Expanded` / `Spacer` 的渲染侧）。
 *
 * ★ **自己不决定尺寸** —— 它是纯粹的 flex 份额载体：`RenderFlex` 在第二遍按
 *   `flex()` 把剩余空间收成紧约束递下来，这里只转发。所以量自然尺寸那遍
 *   它拿到的是无界主轴（C4），返回的是孩子的自然尺寸。
 *
 * ★ `flex = 0` 的实例等价于纯转发盒（`RenderProxyBox` 的形态）—— 不特判，
 *   少一条分支。
 *
 * @api
 */
final class RenderFlexible extends RenderProxyBox
{
    public function __construct(int $flexValue = 1)
    {
        if ($flexValue < 1) {
            // flex 份额无意义 ⇒ 响亮失败（C6：违反 ⇒ 报）
            \Drift\Errors::report(sprintf('flex 份额必须 ≥ 1，收到 %d', $flexValue));
        }
        // ★ 写进 `RenderBox` 的意图字段，不再自己存一份 ——
        //   `RenderFlex` 读的是 `$kid->flex()`（= `grow()`），两个住处
        //   迟早不一致（`04` §2.2：改的是数据的住处，不是算法的写法）。
        $this->setFlexIntent($flexValue, 0, \NAN, self::ALIGN_INHERIT);
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        if ($this->child === null) {
            return $constraints->constrain(new Size(0.0, 0.0));
        }

        $this->child->layout($constraints);

        return $constraints->constrain($this->child->size());
    }
}
