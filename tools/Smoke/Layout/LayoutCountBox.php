<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Rendering\Canvas;

/**
 * @internal 冒烟夹具：**数自己被打了几次 layout / paint** 的叶子盒。
 *
 * 存在的理由：`05` §5.4 第三步（纯动画帧跳过 layout）的唯一可观测判据就是
 * "layout 调用次数没涨、paint 调用次数涨了"。用探针数次数，比"看画面有没有变"
 * 精确得多 —— 画面在两种情况下都可能看起来一样。
 */
final class LayoutCountBox extends RenderBox
{
    public int $layoutCalls = 0;

    public int $paintCalls = 0;

    public function __construct(private readonly Size $fixed)
    {
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $this->layoutCalls++;

        return $constraints->constrain($this->fixed);
    }

    protected function paintContent(Canvas $canvas): void
    {
        $this->paintCalls++;
    }

    public function visitChildren(callable $visitor): void
    {
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        return $this->fixed->width;
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        return $this->fixed->height;
    }
}
