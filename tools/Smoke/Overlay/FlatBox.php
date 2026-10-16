<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Overlay;

use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Dev\Smoke\Layout\SolidBox;
use Drift\Widget\BuildContext;
use Drift\Widget\RenderBoxWidget;

/**
 * @internal 冒烟夹具：**固定尺寸**的普通控件（用它才能给浮层 / 拖放测试造确定几何）。
 *
 * `SolidBox` 是**盒**（`RenderBox`）—— 挂不上 `draggable()` / `contextMenu()`
 * 那些 **Widget** 层的声明，所以要一个薄壳把它包成控件。
 */
final class FlatBox extends RenderBoxWidget
{
    public function __construct(private readonly float $w, private readonly float $h)
    {
        parent::__construct();
    }

    public static function make(float $width, float $height): self
    {
        return new self($width, $height);
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new SolidBox(new Size($this->w, $this->h), $this->w, $this->h);
    }
}
