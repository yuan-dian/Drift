<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;

/**
 * @internal 冒烟夹具：**墨比盒大**的盒（裁剪有没有生效的唯一装置）。
 *
 * 盒尺寸 = 父约束夹过的 `$ink`（与 `SolidBox` 同路，基类 `layout()` 负责 constrain），
 * 但 `paintContent()` 按 `$ink` 的**原尺寸**画 ⇒ 内容一定越过盒边界。
 *
 * ★ 为什么不用 `SolidBox`：它的墨与盒同尺寸 ⇒ 裁与不裁像素完全相同，
 *   是**恒真断言**。判据必须让"盒"与"墨"尺寸不同，才区分得出裁剪。
 */
final class BleedBox extends RenderBox
{
    public function __construct(private readonly Size $ink)
    {
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        // ★ 必须自己 `constrain()`：基类 ⑥ 步对"返回尺寸超出上界"报 C6 Fatal
        //   （那意味着"我要画到盒外面去"）—— 而本夹具要的正是**盒不超、墨超**：
        //   盒被夹到可用空间，`paintContent()` 仍按 `$ink` 原尺寸画。
        return $constraints->constrain($this->ink);
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        return $this->ink->width + $height * 0.0; // 显式读另一轴，防"顺手删参数"
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        return $this->ink->height;
    }

    public function visitChildren(callable $visitor): void
    {
    }

    protected function paintContent(Canvas $canvas): void
    {
        $canvas->drawRect(
            new Rect(0.0, 0.0, $this->ink->width, $this->ink->height),
            Paint::fill(Color::black()),
        );
    }
}
