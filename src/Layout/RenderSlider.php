<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Paint;
use Drift\Rendering\SliderStyle;

/**
 * 滑杆渲染盒（W8-2）：轨道 + 已选段 + 拇指，**全部画在自己身上**。
 *
 * ★ **叶子盒**（没有子渲染对象）：滑杆没有内容 —— 数值标签要显示就由调用方
 *   在旁边放 `Text`。做成"带标签的盒子"会把标签位置策略（右侧 / 拇指上方 /
 *   拖动时浮出）提前引进来，而今天没有消费者。
 *
 * ## 几何（唯一出处：这个类）
 *
 * ```text
 * padX   = thumbRadius                 ← 值 0 / 1 时拇指**贴边但不出界**
 * boxH   = 2*thumbRadius + 2*thumbGap
 * cy     = boxH / 2
 * trackX = padX .. w - padX             ← 轨道只在"拇指中心可达区间"内
 * thumbX = padX + t * (w - 2*padX),  t = (value - min) / (max - min)
 * ```
 *
 * ★ **拇指中心与轨道端点重合**，不是"拇指内切于轨道"：前者让"拖到最右"时拇指
 *   仍然完整可见（半圆落在盒内），后者会让拇指有一半在盒外 —— 而 Drift 的盒
 *   **不裁**（`Overflow::Clip` 默认关）⇒ 那一半会画到邻居身上。
 *
 * ## 值 ↔ 像素只有这一处
 *
 * `valueAt()` / `thumbX()` 是本类的方法，Widget 侧**不重复实现换算**。
 * 指针回调从 `$element->effectiveBox()` 现取盒再调它们 —— 盒每帧重建（§13.2），
 * 缓存盒等于拿上一帧的几何算这一帧的位置。
 *
 * @api
 */
final class RenderSlider extends RenderBox
{
    public function __construct(
        private readonly float $value,
        private readonly float $min,
        private readonly float $max,
        private readonly float $step,
        private readonly float $width,
        private readonly SliderStyle $style,
    ) {
    }

    /** 盒高：拇指直径 + 上下留白（与 `contentIntrinsicHeight` 同式） */
    public static function boxHeight(SliderStyle $style): float
    {
        return 2.0 * $style->thumbRadius + 2.0 * SliderStyle::DEFAULT_THUMB_GAP;
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $w = $this->width;
        if ($w <= 0.0) {
            // 没显式给宽 ⇒ 吃满授予（与 `RenderDivider` 同一条"宽度语义"）；
            // 无界时兜底 160 —— 绝不把 INF 放进坐标链。
            $w = $constraints->maxWidth;
            if (!\is_finite($w)) {
                $w = 160.0;
            }
        }

        return $constraints->constrain(new Size($w, self::boxHeight($this->style)));
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        return $this->width;
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        return self::boxHeight($this->style);
    }

    /** 叶子：没有子渲染对象（`RenderDivider` 同形态） */
    public function visitChildren(callable $visitor): void
    {
    }

    /**
     * 归一化进度 `t ∈ [0,1]`。
     *
     * ★ `max <= min`（退化区间）⇒ 恒 0，**不除零**（PHP 的 float 除零得 `INF`，
     *   会让拇指飞到盒外；且 tpc 下是未定义行为）。
     */
    public function fraction(): float
    {
        $span = $this->max - $this->min;
        if ($span <= 0.0) {
            return 0.0;
        }

        $t = ($this->value - $this->min) / $span;

        return \min(\max($t, 0.0), 1.0);
    }

    /** 拇指圆心 x（盒内坐标） */
    public function thumbX(): float
    {
        $size = $this->size;
        $w = $size === null ? 0.0 : $size->width;
        $pad = $this->style->thumbRadius;

        return $pad + $this->fraction() * ($w - 2.0 * $pad);
    }

    /**
     * 盒内 x ⇒ 值（指针用）。**唯一的反函数**，Widget 侧不要再写一份。
     *
     * 顺序要紧：先按 `[min,max]` 夹、再按 `step` 量化、最后再夹一次 ——
     * 量化可能把边界值推出去（`step=3`、`max=10` ⇒ 9.99 量化成 12）。
     */
    public function valueAt(float $localX): float
    {
        $size = $this->size;
        $w = $size === null ? 0.0 : $size->width;
        $pad = $this->style->thumbRadius;
        $span = $w - 2.0 * $pad;

        if ($span <= 0.0) {
            return $this->min;
        }

        $t = ($localX - $pad) / $span;
        $t = \min(\max($t, 0.0), 1.0);

        $value = $this->min + $t * ($this->max - $this->min);
        $value = $this->quantize($value);

        return \min(\max($value, $this->min), $this->max);
    }

    /** 按 `step` 量化；`step <= 0` ⇒ 连续（原样返回） */
    private function quantize(float $value): float
    {
        if ($this->step <= 0.0) {
            return $value;
        }

        return $this->min + \round(($value - $this->min) / $this->step) * $this->step;
    }

    protected function paintContent(Canvas $canvas): void
    {
        $size = $this->size;
        if ($size === null || $size->width <= 0.0 || $size->height <= 0.0) {
            return; // 没排过 ⇒ 无墨（与 `RenderDivider` 同判据）
        }

        $w = $size->width;
        $h = $size->height;
        $pad = $this->style->thumbRadius;
        $trackH = $this->style->trackHeight;
        $cy = $h / 2.0;
        $top = $cy - $trackH / 2.0;

        // ★ 圆角一律夹 `min(w,h)/2`：软光栅的 `insideRoundRect` 内部会夹、
        //   Skia 桥不夹 ⇒ 不自己夹就双端不一致（conformance 直接红）。
        $trackRadius = $trackH / 2.0;

        // ① 轨道（全程）
        $canvas->drawRect(
            new Rect($pad, $top, $w - 2.0 * $pad, $trackH),
            Paint::fill($this->style->track),
            $trackRadius,
        );

        // ② 已选段（pad → thumbX）：宽度为 0 时不画（零宽矩形在 Skia 上是
        //    "一条抗锯齿的竖线"，会白亮一像素）
        $thumbX = $this->thumbX();
        $fillW = $thumbX - $pad;
        if ($fillW > 0.0) {
            $canvas->drawRect(
                new Rect($pad, $top, $fillW, $trackH),
                Paint::fill($this->style->accent),
                $trackRadius,
            );
        }

        // ③ 拇指：正方形 Rect + `drawEllipse` = 圆（Core 层，双端都支持）
        $r = $this->style->thumbRadius;
        $thumbRect = new Rect($thumbX - $r, $cy - $r, 2.0 * $r, 2.0 * $r);
        $canvas->drawEllipse($thumbRect, Paint::fill($this->style->accent));

        if ($this->style->thumbBorderWidth > 0.0) {
            $canvas->drawEllipse($thumbRect, Paint::stroke($this->style->thumbBorder, $this->style->thumbBorderWidth));
        }
    }
}
