<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Rendering;

use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;
use Drift\Widget\CustomPainter;

/**
 * @internal 冒烟夹具：CustomPaint 的"画家"探针。
 *
 * 记录 `calls` / 画家实际收到的 `Size`；`draw = true` 时在左上角画一个
 * `min(40, 盒尺寸)` 的实心椭圆（墨迹断言用）。`version()` 可给非 0 值 ——
 * 验证"内容戳"从 Widget 一路直通渲染盒的 `painterVersion()`。
 */
final class ProbePainter extends CustomPainter
{
    public int $calls = 0;

    public ?Size $seen = null;

    public bool $draw = false;

    public function __construct(private readonly int $ver = 0)
    {
    }

    public function paint(Canvas $canvas, Size $size): void
    {
        $this->calls++;
        $this->seen = $size;

        if (!$this->draw) {
            return;
        }

        $w = $size->width < 40.0 ? $size->width : 40.0;
        $h = $size->height < 40.0 ? $size->height : 40.0;
        $canvas->drawEllipse(new Rect(0.0, 0.0, $w, $h), Paint::fill(Color::black()));
    }

    public function version(): int
    {
        return $this->ver;
    }
}
