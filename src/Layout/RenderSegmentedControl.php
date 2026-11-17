<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Paint;
use Drift\Rendering\SegmentedStyle;

/**
 * 分段控件的**轨道盒**：胶囊外轨 + N 个段子盒（`RenderSegment`）。
 *
 * ```php
 * SegmentedControl::make(['日', '周', '月'])->selected(1)->onChanged(fn(int $i) => ...);
 * ```
 *
 * ## 四件事（与 `RenderStack` 同构，多一个"等宽"阶段）
 *
 * | # | 做什么 | 口径 |
 * |---|---|---|
 * | ① | 给子约束 | 松约束（段对自己的标签 hugs） |
 * | ② | 量每个子 | **每个子只自然量一遍** |
 * | ③ | 等宽 | `equal = max(自然宽)`，再按**紧宽**重排一次（同一遍里的第二阶段，不是第二遍布局） |
 * | ④ | 本盒尺寸 | `equal * n + gap * (n-1)`，高 = `max(段高)` |
 *
 * ★ **为什么等宽**：分段控件是"一组平级的视图切换"，段宽不齐会让选中块在
 *   换选项时左右横跳（标签长度不同 ⇒ 盒宽不同 ⇒ 选中块宽度跟着变）。等宽后
 *   选中块尺寸恒定，视觉只表达"哪一段被选中"这一件事。iOS / WinUI 的
 *   Segmented 同此口径；要内容比例宽度是另一种控件，不在这里加开关。
 *
 * ★ **等宽不是两遍布局**：`RenderStack` 类头那条纪律（量完 → 求 max → 定位是
 *   同一遍的两个阶段）在这里同样成立 —— ③ 的"紧宽重排"与 ② 的自然量**没有
 *   循环依赖**（equal 只来自子的自然宽，不来自本盒尺寸）。文本盒本来就接受
 *   "约束变了重排一次"（`RenderText::performLayout` 文档明说会跑两次）。
 *
 * ★ **命中测试不在这里写**：`RenderBox::hitTest()` 已经 `array_reverse` 问子
 *   ⇒ 段级精度天然成立（点哪一段，最深命中盒就是哪一段的 `RenderSegment`），
 *   焦点链因此能落到**段控件自己**（`Segment::activate()` 才有"选了哪段"的
 *   完整上下文 —— 整个控件一个焦点是答不出这个问题的）。
 *
 * ★ **轨道 chrome 走 `paintOwnChrome()`**（不写进 `paintContent()`）：描边跨在
 *   盒边界上，`clipsOverflow()` 为真时会被裁掉半边（`02` §11.11 那条真机教训，
 *   见 `RenderAppBar`）。本盒今天不声明裁剪，但 chrome 位置按纪律一次做对。
 *
 * @api
 */
final class RenderSegmentedControl extends RenderBox implements ChildContainer
{
    use MultiChildBox;

    /** @var list<RenderBox> */
    private array $kids = [];

    public function __construct(
        private readonly SegmentedStyle $style,
    ) {
    }

    public function style(): SegmentedStyle
    {
        return $this->style;
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $count = count($this->kids);
        if ($count === 0) {
            return $constraints->constrain(new Size(0.0, 0.0));
        }

        $maxW = $constraints->maxWidth;
        $maxH = $constraints->maxHeight;

        // ② 自然量一遍（松约束 ⇒ 段盒 = 自己的标签尺寸）
        $equal = 0.0;
        $height = 0.0;
        foreach ($this->kids as $kid) {
            $kid->layout(new BoxConstraints(0.0, $maxW, 0.0, $maxH));
            $kidSize = $kid->size();
            if ($kidSize === null) {
                continue;
            }
            if ($kidSize->width > $equal) {
                $equal = $kidSize->width;
            }
            if ($kidSize->height > $height) {
                $height = $kidSize->height;
            }
        }

        // ③ 等宽重排 + ④ 定位。偏移住**内容盒**坐标（padding 由基类平移补，
        //    这里绝不自己加 —— `02` §4.1.1）。
        $gap = $this->style->gap;
        $x = 0.0;
        foreach ($this->kids as $kid) {
            $kid->layout(new BoxConstraints($equal, $equal, 0.0, $maxH));
            $kidSize = $kid->size();
            $kidH = 0.0;
            if ($kidSize !== null) {
                $kidH = $kidSize->height;
            }
            $top = 0.0;
            if ($kidH < $height) {
                $top = ($height - $kidH) / 2.0;
            }
            $kid->setOffset($x, $top);
            $x += $equal + $gap;
        }

        $width = $equal * $count + $gap * ($count - 1);

        return $constraints->constrain(new Size($width, $height));
    }

    #[\Override]
    protected function contentIntrinsicWidth(float $height): float
    {
        $count = count($this->kids);
        if ($count === 0) {
            return 0.0;
        }

        $widest = 0.0;
        foreach ($this->kids as $kid) {
            $w = $kid->intrinsicWidth($height);
            if ($w > $widest) {
                $widest = $w;
            }
        }

        // 与 performLayout 的 ③④ 同式：等宽 ⇒ 最宽者乘段数，不是简单求和
        return $widest * $count + $this->style->gap * ($count - 1);
    }

    #[\Override]
    protected function contentIntrinsicHeight(float $width): float
    {
        $tallest = 0.0;
        foreach ($this->kids as $kid) {
            $h = $kid->intrinsicHeight($width);
            if ($h > $tallest) {
                $tallest = $h;
            }
        }

        return $tallest;
    }

    /**
     * 轨道 chrome：胶囊底 + 描边，覆盖**整盒**（含 padding —— 轨要把段包住）。
     *
     * ★ 圆角 = min(盒宽, 盒高) / 2（胶囊；窄轨时宽是约束）。写死常数会在改字号时脱节。
     */
    #[\Override]
    protected function paintOwnChrome(Canvas $canvas): void
    {
        $size = $this->size;
        if ($size === null || $size->width <= 0.0 || $size->height <= 0.0) {
            return; // 没排过 ⇒ 无墨（与 RenderToggle 同判据）
        }

        $rect = new Rect(0.0, 0.0, $size->width, $size->height);
        // ★ 圆角同样夹到 min(w,h)/2（与 RenderSegment 同一条纪律）：轨通常远宽于
        //   高，但"一段 + 短标签"的窄轨会让 h/2 超过半宽 ⇒ 自交圆角，且软光栅与
        //   Skia 桥的夹法不一致 ⇒ 双端 conformance 红。
        $radius = min($size->width, $size->height) / 2.0;

        $canvas->drawRect($rect, Paint::fill($this->style->track), $radius);

        if ($this->style->trackBorderWidth > 0.0) {
            $canvas->drawRect(
                $rect,
                Paint::stroke($this->style->trackBorder, $this->style->trackBorderWidth),
                $radius,
            );
        }
    }

}
