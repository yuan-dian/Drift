<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;

/**
 * 参与盒布局的渲染对象（§13.1）。
 *
 * ```
 * layout(BoxConstraints): Size   // 纯函数：约束 → 尺寸 + 子节点相对位置
 * paint(Canvas): void            // 父级已 translate 到本盒原点
 * ```
 *
 * ★ **`layout()` 是 `final`**：约束检查（C6）只该有一处。子类实现
 *   `performLayout()`，返回约束内的尺寸 —— 违反的话 `layout()` 立刻响亮失败，
 *   不会安静地画到盒外去。
 *
 * @api
 */
abstract class RenderBox extends RenderObject
{
    protected ?Size $size = null;
    protected float $offsetX = 0.0;
    protected float $offsetY = 0.0;

    public function size(): ?Size
    {
        return $this->size;
    }

    public function hasSize(): bool
    {
        return $this->size !== null;
    }

    /** 父定位子（C1）：子不自定位，所以这两个只有父会写 */
    public function offsetX(): float
    {
        return $this->offsetX;
    }

    public function offsetY(): float
    {
        return $this->offsetY;
    }

    protected function setOffset(float $x, float $y): void
    {
        $this->offsetX = $x;
        $this->offsetY = $y;
    }

    /**
     * 两阶段之一（§13.1）：约束 → 尺寸，**同时定位子节点**。
     *
     * 纯函数（§14.1）：同样输入 ⇒ 同样输出。所以它**不参与**脏判定 —— 由调用方
     * 决定何时重排（§13.3：Render 层全树重排）。
     */
    final public function layout(BoxConstraints $constraints): Size
    {
        $measured = $this->performLayout($constraints);

        // C6：**只有超出上界才算违反**——那意味着"我要画到盒外面去"。
        // 下界提升不是违反：`tight()` 的语义就是父把尺寸定死了，`constrain()`
        // 提下界是它的本职工作（Drift 的 Column 在紧约束下报"最宽子"而不是
        // 硬宽，是正确的——父只是给了上限）。
        if ($measured->width > $constraints->maxWidth || $measured->height > $constraints->maxHeight) {
            Errors::report(sprintf(
                '%s 的布局结果违反约束：报了 %.6g × %.6g，约束只允许最多 %.6g × %.6g',
                static::class,
                $measured->width,
                $measured->height,
                $constraints->maxWidth,
                $constraints->maxHeight
            ));
        }

        $this->size = $constraints->constrain($measured);

        return $this->size;
    }

    /**
     * 子类只实现这一层。**必须返回约束内的尺寸**，否则 C6 立刻报。
     *
     * 若是容器：在这里给子节点 `layout()` 并 `setOffset()`（C1 单向数据流）。
     */
    abstract protected function performLayout(BoxConstraints $constraints): Size;

    /**
     * C3 内禀测量：本盒在**给定另一轴**时的自然尺寸。
     *
     * `$height = INF` 表示那一轴不限。为什么必须带另一轴：比例盒（图、
     * 视频）要靠本轴约束定另一轴尺寸，单轴内禀值给不出答案。
     */
    abstract public function intrinsicWidth(float $height): float;

    abstract public function intrinsicHeight(float $width): float;

    /**
     * 主轴弹性份额（`RenderFlex` 读它；§13.1 C4）。
     *
     * `0` = 不参与弹性分配 ⇒ 拿自然尺寸。默认 0，只有弹性项的盒子覆写。
     */
    public function flex(): int
    {
        return 0;
    }

    /**
     * C5 溢出策略。**默认 `Clip` 只给不会超的容器**（尺寸由内容或子决定的容器）；
     * 会超的容器（`Row` / `AppBar` / `Dock` 槽 / 自绘容器）**必须覆写**。
     *
     * @api
     */
    public function overflow(): Overflow
    {
        return Overflow::Clip;
    }

    abstract public function paint(Canvas $canvas): void;
}
