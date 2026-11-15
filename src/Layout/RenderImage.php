<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\CanvasCapabilities;
use Drift\Rendering\ImageHandle;

/**
 * 图片渲染盒：把一张 `ImageHandle` 按 `ImageFit` 铺进自己的盒。
 *
 * ★ **叶子**（没有子渲染对象）：图片没有内容盒，图就是内容。
 *
 * ★ **自然尺寸 = 图像像素尺寸**（`$requestedWidth/Height <= 0` 时）：
 *   只给一边时另一边**按原图比例推**（`->width(120.0)` 是最常见的用法，
 *   这时高度应当跟着变而不是被压扁）。
 *
 * ★★ **不溢出靠裁源矩形**：`Cover` / `None` 会让"盒比图小"，这时裁的是
 *   **源**（传给 `drawImage` 的 `?Rect $src`），不是目标 ⇒ 不需要 `clip`
 *   修饰、不付 `pushClip` 的代价，也不会在父容器边界外留墨。
 *
 * ★ **不透明度 / 圆角不在这里**：要圆角图片请用 `clip` 修饰（`Modifier::clip`
 *   自 2026-10-23 起是真裁），要半透明请用 `Opacity` 包一层 —— 一个只画图的
 *   盒子自己实现这两件事，会和修饰层打架。
 *
 * ★ 纯装饰/内容叶子：**不给语义角色**（§9.2：`Text`/`Icon`/`Image` 这类叶子
 *   一律不给，否则"按角色找控件"会被淹没）。需要可读的名字请放在**外层**
 *   交互控件的 `semName` 上。
 *
 * @api
 */
final class RenderImage extends RenderBox
{
    public function __construct(
        private readonly ImageHandle $image,
        private readonly ImageFit $fit,
        private readonly float $requestedWidth,
        private readonly float $requestedHeight,
    ) {
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $w = $this->naturalWidth();
        $h = $this->naturalHeight();
        if (!is_finite($w) || $w < 0.0) {
            $w = 0.0;
        }
        if (!is_finite($h) || $h < 0.0) {
            $h = 0.0;
        }

        return $constraints->constrain(new Size($w, $h));
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        return $this->naturalWidth();
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        return $this->naturalHeight();
    }

    /** 叶子：没有子渲染对象 */
    public function visitChildren(callable $visitor): void
    {
    }

    private function naturalWidth(): float
    {
        if ($this->requestedWidth > 0.0) {
            return $this->requestedWidth;
        }
        if ($this->requestedHeight > 0.0) {
            return $this->requestedHeight * (float) $this->image->width / (float) $this->image->height;
        }

        return (float) $this->image->width;
    }

    private function naturalHeight(): float
    {
        if ($this->requestedHeight > 0.0) {
            return $this->requestedHeight;
        }
        if ($this->requestedWidth > 0.0) {
            return $this->requestedWidth * (float) $this->image->height / (float) $this->image->width;
        }

        return (float) $this->image->height;
    }

    protected function paintContent(Canvas $canvas): void
    {
        $size = $this->size;
        if ($size === null || $size->width <= 0.0 || $size->height <= 0.0) {
            return; // 没排过 / 零尺寸 ⇒ 无墨
        }

        // ★ 守卫（`optional-op-guard`）：三个后端都有 `CAP_IMAGE` ⇒ 今天恒真；
        //   留着给将来只实现 Core 的后端兜底（缺位就跳过，图片缺席不影响布局）。
        //   与 `CAP_SHADOW` 走降级的差异登记在 `CanvasCapabilityContractTest`。
        $caps = $canvas->capabilities();
        if (($caps & CanvasCapabilities::CAP_IMAGE) === 0) {
            return;
        }

        $iw = (float) $this->image->width;
        $ih = (float) $this->image->height;
        $bw = $size->width;
        $bh = $size->height;

        // 默认：铺满整个盒、取整图（= Fill；其余三种覆盖这两个值）
        $dst = new Rect(0.0, 0.0, $bw, $bh);
        $src = null;

        if ($this->fit === ImageFit::Contain) {
            $scale = $bw / $iw;
            $other = $bh / $ih;
            if ($other < $scale) {
                $scale = $other;
            }
            $dw = $iw * $scale;
            $dh = $ih * $scale;
            $dst = new Rect(($bw - $dw) / 2.0, ($bh - $dh) / 2.0, $dw, $dh);
        } elseif ($this->fit === ImageFit::Cover) {
            // 源矩形按**盒的比例**裁，再铺满盒（等比 ⇒ 不变形）
            $boxAspect = $bw / $bh;
            $imgAspect = $iw / $ih;
            $sw = $iw;
            $sh = $ih;
            if ($boxAspect > $imgAspect) {
                // 盒更宽 ⇒ 保宽、裁高
                $sh = $iw / $boxAspect;
            } else {
                // 盒更高 ⇒ 保高、裁宽
                $sw = $ih * $boxAspect;
            }
            $src = new Rect(($iw - $sw) / 2.0, ($ih - $sh) / 2.0, $sw, $sh);
        } elseif ($this->fit === ImageFit::None) {
            // 1:1 居中；图比盒大就裁源，图比盒小就居中留白
            $sw = $iw;
            if ($bw < $sw) {
                $sw = $bw;
            }
            $sh = $ih;
            if ($bh < $sh) {
                $sh = $bh;
            }
            $src = new Rect(($iw - $sw) / 2.0, ($ih - $sh) / 2.0, $sw, $sh);
            $dst = new Rect(($bw - $sw) / 2.0, ($bh - $sh) / 2.0, $sw, $sh);
        }

        $canvas->drawImage($this->image, $src, $dst);
    }
}
