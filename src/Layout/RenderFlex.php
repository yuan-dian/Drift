<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;

/**
 * 弹性盒（§13.1 的 `Column` / `Row` 渲染侧）。
 *
 * 三阶段：
 * 1. **问自然尺寸** —— 非弹性子拿 `0..主轴可用`，弹性子拿**无界主轴**（C4）；
 * 2. **分剩余空间** —— 主轴有界且有弹性子时，按 flex 份额重排弹性子；
 * 3. **定位** —— 主轴顺序排、交叉轴按 `FlexAlign`（C1：父定位子）。
 *
 * ★ **C4 的"无界高"不是实现细节而是契约**：量自然尺寸那遍给弹性子无界主轴，
 *   否则弹性子按"现在剩多少"量，flex 分配就建立在错误分母上（图形比例会随
 *   兄弟数量漂移）。`0` 是合法主轴尺寸，所以不能用"0 表示无界"—— 那是 GUI 仓
 *   三次踩坑的那一条（C2）。
 *
 * @api
 */
final class RenderFlex extends RenderBox
{
    /** @var list<RenderBox> */
    private array $kids = [];

    /**
     * @param bool $vertical true = `Column`（主轴竖）；false = `Row`（主轴横）
     * @param float $spacing 主轴相邻子的间距（不含 padding）
     * @param FlexAlign $crossAlign 交叉轴对齐
     * @param Overflow $overflow **必填，无默认值** —— C5 原话："会超的容器必须
     *        显式声明"。写成构造参数而不是字段默认，是为了让漏声明编译不过（G1）。
     */
    public function __construct(
        private readonly bool $vertical,
        private readonly float $spacing = 0.0,
        private readonly EdgeInsets $padding = new EdgeInsets(),
        private readonly FlexAlign $crossAlign = FlexAlign::Start,
        private readonly Overflow $overflow = Overflow::Report,
    ) {
    }

    public function addChild(RenderBox $child): void
    {
        $this->kids[] = $child;
        $this->adoptChild($child);
    }

    /**
     * C5 契约查询：返回本盒**实际生效**的溢出策略。
     *
     * ★ 必须覆写：`RenderBox::overflow()` 的默认值是 Clip，而本盒真正用的策略
     *   是构造参数 `$this->overflow`（默认 Report）—— 不覆写 ⇒ "查询说 Clip、
     *   行为是 Report"，W4（会超的容器声明溢出策略）的判定面整体说谎。
     *   2026-10-16 由 M2 conformance 夹具抓到（`tools/M2Conformance.php` W4）。
     */
    public function overflow(): Overflow
    {
        return $this->overflow;
    }

    /** @internal 重建前清空 —— 否则同一次 rebuild 会把子盒追加两遍 */
    public function clearChildren(): void
    {
        $this->kids = [];
    }

    /** @return list<RenderBox> */
    public function children(): array
    {
        return $this->kids;
    }

    public function visitChildren(callable $visitor): void
    {
        foreach ($this->kids as $kid) {
            $visitor($kid);
        }
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $inner = $constraints->deflate($this->padding);

        $vertical = $this->vertical;
        $maxMain = $vertical ? $inner->maxHeight : $inner->maxWidth;
        $maxCross = $vertical ? $inner->maxWidth : $inner->maxHeight;
        $mainBounded = $vertical ? $inner->hasBoundedHeight() : $inner->hasBoundedWidth();

        $hasFlex = false;
        foreach ($this->kids as $kid) {
            if ($kid->flex() > 0) {
                $hasFlex = true;
                break;
            }
        }

        // ── 第一遍：自然尺寸 ────────────────────────────────────────
        /** @var list<float> $mains 每个子的主轴尺寸 */
        $mains = [];
        $totalFlex = 0;

        foreach ($this->kids as $index => $kid) {
            $flex = $kid->flex();
            $totalFlex += $flex;

            // C4：有弹性子 ⇒ 弹性子拿无界主轴（分母是自然尺寸，不是剩余空间）。
            // 主轴本身就无界时同样给无界 ⇒ 无空间可分，flex 退化成自然尺寸。
            $childMainMax = ($hasFlex && $flex > 0) || !$mainBounded ? INF : max(0.0, $maxMain);
            $kid->layout($this->childConstraints($kid, $childMainMax, $maxCross));
            $mains[$index] = $vertical ? $kid->size()->height : $kid->size()->width;
        }

        $gap = $this->spacing * max(0, count($this->kids) - 1);

        // ── 第二遍：flex 有界时分剩余空间 ────────────────────────────
        if ($hasFlex && $mainBounded && $totalFlex > 0) {
            $free = $maxMain - array_sum($mains) - $gap;
            // 溢出 ⇒ 无空间可分。此时按 C5 的策略处理（下面统一判），flex 项先拿 0。
            $space = max(0.0, $free);

            foreach ($this->kids as $index => $kid) {
                $flex = $kid->flex();
                if ($flex === 0) {
                    continue;
                }
                $share = $space * $flex / $totalFlex;
                $tight = $this->tightMain($inner, $share);
                $kid->layout($tight);
                $mains[$index] = $vertical ? $kid->size()->height : $kid->size()->width;
            }
        }

        $mainSize = array_sum($mains) + $gap;

        // ── C5：溢出策略是布局期行为，不是绘制期补丁 ────────────────
        // Report / 未实现的策略都在 handleOverflow 里中止了；能走到这里的只有
        // Clip ⇒ 盒子自己夹到可用主轴，子项仍按自然位置排（超出部分交给裁剪）。
        $reported = $mainSize;
        if ($mainBounded && $mainSize > $maxMain) {
            $this->handleOverflow($mainSize, $maxMain);
            $reported = $maxMain;
        }

        $crossSize = 0.0;
        foreach ($this->kids as $kid) {
            $cross = $vertical ? $kid->size()->width : $kid->size()->height;
            $crossSize = max($crossSize, $cross);
        }

        $this->placeChildren($mains, $vertical ? $inner->maxWidth : $inner->maxHeight);

        return new Size(
            $vertical
                ? min($crossSize + $this->padding->horizontal(), $constraints->maxWidth)
                : $reported + $this->padding->horizontal(),
            $vertical
                ? $reported + $this->padding->vertical()
                : min($crossSize + $this->padding->vertical(), $constraints->maxHeight),
        );
    }

    public function intrinsicWidth(float $height): float
    {
        return $this->vertical ? $this->intrinsicCross($height) : $this->intrinsicMain($height);
    }

    public function intrinsicHeight(float $width): float
    {
        return $this->vertical ? $this->intrinsicMain($width) : $this->intrinsicCross($width);
    }

    public function paint(Canvas $canvas): void
    {
        $canvas->save();
        $canvas->translate($this->padding->left, $this->padding->top);

        foreach ($this->kids as $kid) {
            $canvas->save();
            $canvas->translate($kid->offsetX(), $kid->offsetY());
            $kid->paint($canvas);
            $canvas->restore();
        }

        $canvas->restore();
    }

    // ── 内部 ────────────────────────────────────────────────────────

    /**
     * 单个子的约束：主轴 `0..$mainMax`（弹性且有界时由第二遍收紧），
     * 交叉轴 `Stretch` ⇒ 紧约束（拉满），否则上限界。
     */
    private function childConstraints(RenderBox $kid, float $mainMax, float $crossMax): BoxConstraints
    {
        $crossBounded = !is_infinite($crossMax);
        $stretch = $this->crossAlign === FlexAlign::Stretch && $crossBounded;

        if ($this->vertical) {
            return $stretch
                ? new BoxConstraints(0.0, $crossMax, $mainMax, $mainMax)
                : new BoxConstraints(0.0, $crossMax, 0.0, $mainMax);
        }

        return $stretch
            ? new BoxConstraints($mainMax, $mainMax, 0.0, $crossMax)
            : new BoxConstraints(0.0, $mainMax, 0.0, $crossMax);
    }

    /** 主轴紧约束（flex 分配结果） */
    private function tightMain(BoxConstraints $inner, float $main): BoxConstraints
    {
        $share = max(0.0, $main);

        return $this->vertical
            ? new BoxConstraints(
                $this->crossAlign === FlexAlign::Stretch && $inner->hasBoundedWidth() ? $inner->maxWidth : 0.0,
                $inner->maxWidth,
                $share,
                $share,
            )
            : new BoxConstraints(
                $share,
                $share,
                0.0,
                $inner->maxHeight,
            );
    }

    /** @param list<float> $mains */
    private function placeChildren(array $mains, float $crossMax): void
    {
        $pen = 0.0;

        foreach ($this->kids as $index => $kid) {
            $cross = $this->vertical ? $kid->size()->width : $kid->size()->height;
            $offset = match ($this->crossAlign) {
                FlexAlign::Center  => max(0.0, ($crossMax - $cross) / 2.0),
                FlexAlign::End     => max(0.0, $crossMax - $cross),
                FlexAlign::Stretch => 0.0,
                FlexAlign::Start   => 0.0,
            };

            if ($this->vertical) {
                $kid->setOffset($offset, $pen);
            } else {
                $kid->setOffset($pen, $offset);
            }

            $pen += $mains[$index] + $this->spacing;
        }
    }

    private function handleOverflow(float $mainSize, float $maxMain): void
    {
        match ($this->overflow) {
            Overflow::Clip => null, // 自己夹住（见 performLayout 的 min），子项照自然位置排
            Overflow::Report => Errors::report(sprintf(
                '%s 溢出：自然主轴 %.6g 超过可用 %.6g。C5 要求声明策略 —— Report 即响亮失败；'
                . '若要允许裁剪请改用 Clip',
                static::class,
                $mainSize,
                $maxMain,
            )),
            Overflow::Scroll, Overflow::Wrap => Errors::report(sprintf(
                '%s 的溢出策略 %s 尚未实现（M2 只有 Clip / Report）',
                static::class,
                $this->overflow->name,
            )),
        };
    }

    /** 主轴内禀值：所有子同轴相加 + 间距 */
    private function intrinsicMain(float $otherAxis): float
    {
        $total = 0.0;
        foreach ($this->kids as $kid) {
            $total += $this->vertical ? $kid->intrinsicHeight($otherAxis) : $kid->intrinsicWidth($otherAxis);
        }

        return $total + $this->spacing * max(0, count($this->kids) - 1);
    }

    /** 交叉轴内禀值：所有子的最大者 */
    private function intrinsicCross(float $otherAxis): float
    {
        $max = 0.0;
        foreach ($this->kids as $kid) {
            $value = $this->vertical ? $kid->intrinsicWidth($otherAxis) : $kid->intrinsicHeight($otherAxis);
            $max = max($max, $value);
        }

        return $max;
    }
}
