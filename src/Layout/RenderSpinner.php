<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;

/**
 * 加载指示器盒：8 个点围成一环，头部最实、向尾部渐隐（§A.2 的 `Loading` 分支）。
 *
 * ★ **点是圆角矩形**（`drawRect` + `radius = 点径/2`）—— 不新增画布原语：
 *   §7.2 的 Canvas 面只有矩形与段落，指示器用矩形拼（`radius` 参数让方点成圆点）。
 *
 * ★ **方向表是字面量不是 `cos()/sin()`**：§14.1 要纯函数 —— libm 的跨平台末位
 *   差异会让 golden 位图在多平台间不可比；8 个方向写死，零成本消除该变量。
 *
 * ★ **图标语义：不随授予盒拉伸**。紧约束下盒被父定死（C6 下界提升，框架
 *   统一行为），但绘制取 `min(盒, 直径)` 并在盒内居中 —— 否则 24px 的指示器
 *   塞进 200px 的槽会被拉成满屏大点。缩小仍成立（槽比直径小 ⇒ 画小的）。
 *
 * @api
 */
final class RenderSpinner extends RenderBox
{
    /** 每老一档掉的 alpha（0..255）：头 = 满实，最老 = 255 − 7×STEP */
    private const ALPHA_STEP = 28;

    public function __construct(
        private readonly float $diameter,
        private readonly int $step,
        private readonly Color $color,
    ) {
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        // 装饰性控件：约束比直径小就缩小（画的是实际盒尺寸），不撞 C6
        $side = min($this->diameter, $constraints->maxWidth, $constraints->maxHeight);

        return $constraints->constrain(new Size($side, $side));
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        return $this->diameter;
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        return $this->diameter;
    }

    /** 叶子：没有子渲染对象（8 个点直接画在本盒上，不组成子树） */
    public function visitChildren(callable $visitor): void
    {
    }

    protected function paintContent(Canvas $canvas): void
    {
        $size = $this->size ?? new Size(0.0, 0.0);
        $side = min($size->width, $size->height, $this->diameter);
        if ($side <= 0.0) {
            return; // 没排过（或排成 0）⇒ 无墨，不画
        }

        $directions = self::directions();
        $dots = count($directions);
        $dot = $side * 0.16;
        $ring = ($side - $dot) / 2.0;
        $cx = $size->width / 2.0;
        $cy = $size->height / 2.0;
        $head = (($this->step % $dots) + $dots) % $dots;

        foreach ($directions as $i => $direction) {
            // age 0 = 头（最实），越大越老（越虚）
            $age = ($head - $i + $dots) % $dots;
            $alpha = 255 - $age * self::ALPHA_STEP;
            if ($alpha <= 0) {
                continue;
            }

            $canvas->drawRect(
                new Rect(
                    $cx + $ring * $direction[0] - $dot / 2.0,
                    $cy + $ring * $direction[1] - $dot / 2.0,
                    $dot,
                    $dot,
                ),
                Paint::fill($this->color->withAlpha($alpha)),
                $dot / 2.0,
            );
        }
    }

    /**
     * @return list<array{float, float}> 8 个方向的 (cos, sin)，从正上方起顺时针
     */
    private static function directions(): array
    {
        $r = 0.7071067811865476; // √2/2，写全精度（字面量见表注释）

        return [
            [0.0, -1.0], [$r, -$r],
            [1.0, 0.0], [$r, $r],
            [0.0, 1.0], [-$r, $r],
            [-1.0, 0.0], [-$r, -$r],
        ];
    }
}
