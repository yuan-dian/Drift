<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;

/**
 * 定尺寸盒：把孩子的某一轴（或两轴）**钉死**成给定值。
 *
 * ## 为什么需要它（2026-10-17 示例台侧栏逼出来的）
 *
 * `Padding` 加内边距、`Center` 居中、`Spacer` 吃剩余、`Expanded` 按 flex 分 ——
 * 但**没有"这个盒子就这么多大"**。于是 `Row` 里非弹性子的宽度只能由内容自然宽
 * 决定：示例台侧栏那 148px 是"最长那个按钮文字"撑出来的，改个文案就变、换语言
 * 更变；而 LayUI 侧栏是固定 200px。
 *
 * ## 语义（`null` = 该轴**透传**，不是哨兵）
 *
 * | `width` | 该轴行为 |
 * |---|---|
 * | 给定 | 孩子拿**紧**约束（`min == max == width`），盒尺寸也是 `width` |
 * | `null` | 原样把孩子收到的约束往下传，盒尺寸随孩子 |
 *
 * ★ `null` 在这里是**真语义**（"这一轴不定尺寸"），不是 §15.2 要禁的那种
 *   "拿 null 当缺省值的哨兵" —— 两个轴各自独立，`SizedBox::make($kid)->width(200.0)`
 *   是"只定宽、高随内容"的正当表达。
 *
 * ## 父约束更紧时听父的
 *
 * `tight(100)` 里塞 `width(200)` 不能让 `BoxConstraints` 抛"min 不得大于 max"
 * （那是 Fatal）。所以先**夹进父的区间**：`min(width, maxWidth)` 再 `max(·, minWidth)`。
 * 这条不是宽容，是 §13.1 C2 的唯一正确解 —— 约束是父给的，子无权突破。
 *
 * @api
 */
final class RenderSizedBox extends RenderProxyBox
{
    public function __construct(
        private readonly ?float $width = null,
        private readonly ?float $height = null,
    ) {
    }

    public function fixedWidth(): ?float
    {
        return $this->width;
    }

    public function fixedHeight(): ?float
    {
        return $this->height;
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $minW = $constraints->minWidth;
        $maxW = $constraints->maxWidth;
        $minH = $constraints->minHeight;
        $maxH = $constraints->maxHeight;

        // ★ 逐轴算"孩子该拿的区间"。属性读取先落局部标量（AOT：三元/?? 分支里
        //   直接读属性会被推成 Variant，与 float 形参不匹配 ⇒ C2440）。
        $childMinW = $minW;
        $childMaxW = $maxW;
        if ($this->width !== null) {
            $w = $this->width;
            if ($w > $maxW) {
                $w = $maxW;      // 父更紧 ⇒ 听父
            }
            if ($w < $minW) {
                $w = $minW;
            }
            $childMinW = $w;
            $childMaxW = $w;
        }

        $childMinH = $minH;
        $childMaxH = $maxH;
        if ($this->height !== null) {
            $h = $this->height;
            if ($h > $maxH) {
                $h = $maxH;
            }
            if ($h < $minH) {
                $h = $minH;
            }
            $childMinH = $h;
            $childMaxH = $h;
        }

        $child = $this->child;
        if ($child !== null) {
            $child->layout(new BoxConstraints($childMinW, $childMaxW, $childMinH, $childMaxH));
            // 定尺寸不产生偏移：盒坐标系原点就是孩子原点
            $child->setOffset(0.0, 0.0);
        }

        // 盒尺寸：定了的轴用定值（已夹进父区间），没定的轴随孩子
        $sizeW = $childMaxW;
        if ($this->width === null) {
            $sizeW = $child?->size()?->width ?? 0.0;
        }
        $sizeH = $childMaxH;
        if ($this->height === null) {
            $sizeH = $child?->size()?->height ?? 0.0;
        }

        return $constraints->constrain(new Size($sizeW, $sizeH));
    }

    /**
     * ★ **必须覆写内禀尺寸**：`Row` / `Dock` 量自然宽时走 `intrinsicWidth()`，
     *   不覆写的话定尺寸在"量"这一路完全看不见 —— 侧栏会被量成内容宽，
     *   等于 `SizedBox` 没生效（静默）。
     */
    #[\Override]
    protected function contentIntrinsicWidth(float $height): float
    {
        if ($this->width !== null) {
            return $this->width;
        }

        return $this->child?->intrinsicWidth($height) ?? 0.0;
    }

    #[\Override]
    protected function contentIntrinsicHeight(float $width): float
    {
        if ($this->height !== null) {
            return $this->height;
        }

        return $this->child?->intrinsicHeight($width) ?? 0.0;
    }
}
