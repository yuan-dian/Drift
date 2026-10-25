<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;
use Drift\Rendering\Path;

/**
 * 图标盒（`08` §4.2）：把 `IconSet` 生成的 `Path` 按已缩放坐标描出来。
 *
 * ★ **只吃已缩放的值**：`Path` 与线宽都由 `Icon::createRenderObject()` 备好 ——
 *   Layout 层不认识 `IconName` / `IconSet`（`Canvas` 没有 `scale`，缩放只能在
 *   几何层一次做完）。
 * ★ 尺寸是**固定正方形**：`performLayout()` 返回 `size × size` 夹进约束
 *   （不撑满、不塌缩）—— 图标在一行里就该是它自己那么大。
 * ★ 用 `drawPath`（`CAP_PATH`，两后端都有）：Raster 侧是硬边无抗锯齿，Skia 侧
 *   真矢量。这是 `CAP_PATH` 的已知差异（见 `CanvasCapabilities` 注释），不是缺陷。
 *
 * @api
 */
final class RenderIcon extends RenderBox
{
    public function __construct(
        private readonly Path $path,
        private readonly Color $color,
        private readonly float $strokeWidth,
        /**
         * 图标边长。★ **不能叫 `$size`** —— `RenderBox` 已有非 readonly 的 `$size`
         * （盒尺寸，布局期写），同名字段会 Fatal（"Cannot redeclare non-readonly
         * property as readonly"）。
         */
        private readonly float $iconSize,
    ) {
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        return $constraints->constrain(new Size($this->iconSize, $this->iconSize));
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        return $this->iconSize;
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        return $this->iconSize;
    }

    /** 装饰性叶子：没有子节点 */
    public function visitChildren(callable $visitor): void
    {
    }

    protected function paintContent(Canvas $canvas): void
    {
        if ($this->path->isEmpty()) {
            return; // 空路径 ⇒ 无墨（不该发生：枚举与数据表一一对应）
        }

        $canvas->drawPath($this->path, Paint::stroke($this->color, $this->strokeWidth));
    }
}
