<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Paint;
use Drift\Rendering\ProgressStyle;

/**
 * 进度条渲染盒：一根**轨道** + 一段**填充**（确定性进度，`value ∈ [0, 1]`）。
 *
 * ★ **`int $value` 是 0..1000 而不是 0..1 的 float**：进度要做"完成 37%"
 *   这种离散读数，float 的 `0.37 * w` 会因末位差异在 golden 位图里抖。改用
 *   千分比整数（Widget 层把 float 夹取并 `round(*1000)`），填充宽 =
 *   `track * value / 1000` 是纯整数运算 ⇒ **跨平台逐字节可比**（§14.1）。
 *
 * ★ **不确定进度不在这里**：`Spinner` 已经承担"不知道要多久"的那个形态
 *   （§11.5 三态 `Loading`）。进度条只做"知道总量"的确定性读数 —— 否则要
 *   引入动画相位机（`SpinnerState::tickFrame()` 那套）来喂滑轨，那是另一件事。
 *
 * ★ **宽度语义与 `RenderDivider` 一致**（`<= 0` 吃满授予）；无界时回落
 *   `MIN_WIDTH`，不回退到 INF。
 *
 * ★ 只画 `drawRect`（Core 层）：preview / golden / smoke 走软光栅。
 *
 * @api
 */
final class RenderProgress extends RenderBox
{
    /** 无界宽度时的兜底条长（同 `RenderDivider`） */
    private const float MIN_WIDTH = 24.0;

    /** 千分比分母 */
    public const int SCALE = 1000;

    public function __construct(
        private readonly ProgressStyle $style,
        private readonly int $value,
        private readonly float $width,
    ) {
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $w = $this->width > 0.0 ? min($this->width, $constraints->maxWidth) : $constraints->maxWidth;
        if (!is_finite($w)) {
            $w = self::MIN_WIDTH;
        }

        return $constraints->constrain(new Size($w, $this->style->height));
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        return $this->width;
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        return $this->style->height;
    }

    /** 装饰性叶子：不给语义角色 —— 语义由 `Progress` Widget 直接给 */
    public function visitChildren(callable $visitor): void
    {
    }

    protected function paintContent(Canvas $canvas): void
    {
        $size = $this->size;
        if ($size === null || $size->width <= 0.0 || $size->height <= 0.0) {
            return; // 没排过 ⇒ 无墨
        }

        $radius = $size->height / 2.0;
        $rect = new Rect(0.0, 0.0, $size->width, $size->height);
        $canvas->drawRect($rect, Paint::fill($this->style->track), $radius);

        if ($this->value <= 0) {
            return; // 0% 只画轨道 —— 不画零宽矩形（有些后端发虚）
        }

        $fill = $size->width * $this->value / self::SCALE;
        if ($fill < 1.0) {
            $fill = 1.0; // 非零但极窄 ⇒ 至少一个像素的可见墨（否则"看不出在走"）
        }

        $canvas->drawRect(new Rect(0.0, 0.0, $fill, $size->height), Paint::fill($this->style->fill), $radius);
    }
}
