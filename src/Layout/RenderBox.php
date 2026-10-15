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
        $clamped = $constraints->constrain($measured);

        if (!$clamped->equals($measured)) {
            // C6：违反 ⇒ 响亮失败（Fatal 级，release 也中止）
            Errors::report(sprintf(
                '%s 的布局结果违反约束：报了 %.6g × %.6g，约束只允许 %.6g..%.6g × %.6g..%.6g',
                static::class,
                $measured->width,
                $measured->height,
                $constraints->minWidth,
                $constraints->maxWidth,
                $constraints->minHeight,
                $constraints->maxHeight
            ));
        }

        $this->size = $measured;

        return $measured;
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
