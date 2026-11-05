<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;
use Drift\Rendering\SegmentedStyle;
use Drift\Text\TextMetricsProvider;
use Drift\Text\TextStyle;

/**
 * 分段控件的**一段**：标签 + 选中块（画在自己的盒内，四边内缩 `inset`）。
 *
 * ★ **它是独立可聚焦的叶子盒**（`Segment` 控件 `markFocusable()`）：命中测试取
 *   "最深盒" ⇒ 点哪一段焦点就落哪一段，`activate()` 因此知道"选了哪段"。
 *   把整个分段控件做成一个焦点是答不出这个问题的（`Application::
 *   activateFocusedWidget()` 不收坐标，也不该收 —— 那会把中央派发变复杂）。
 *
 * ★ **选中块画在段自己身上**，不画在轨道上：选中位置 = 段盒位置，随布局天然
 *   对齐；轨道只负责"外框"（`RenderSegmentedControl::paintOwnChrome()`）。
 *   两边各画一半才会出现"选中块与段错开一个 padding"那类静默偏移。
 *
 * ★ **标签是自己的孩子**（`RenderText`，问注入的度量）：断行 / 内禀与独立
 *   `Text` 完全一致（与 `RenderButton` 同一条理由）。字色在**造盒时**按
 *   selected 选好 —— 盒按 Widget 重建（§13.2），没有"盒活着改选中态"这条路。
 *
 * ★ **段盒 = 标签 + 四边 `inset`**（不是"标签就是段盒"）：选中块按 `inset` 内缩
 *   后画，所以段盒必须比标签大出一圈，否则药丸比标签窄、选中态的白字会溢出到
 *   轨道上。这条不变量由 `performLayout` 与两个 `contentIntrinsic*` 共同维持
 *   （三者不同式 = 内禀与实排自相矛盾，父容器按内禀分配时轨道被挤窄）。
 *
 * @api
 */
final class RenderSegment extends RenderProxyBox
{
    public function __construct(
        private readonly bool $selected,
        private readonly SegmentedStyle $style,
        string $label,
        TextMetricsProvider $metrics,
        // 不 promote：基类同名字段会在 AOT 下撞 "Cannot redeclare non-readonly property"
        ?Color $hoverOverlay = null,
        ?Color $pressedOverlay = null,
    ) {
        $this->setInteraction($hoverOverlay, $pressedOverlay);

        $color = $this->style->labelIdle;
        if ($this->selected) {
            $color = $this->style->labelOnFill;
        }

        $this->setChild(new RenderText(
            $label,
            TextStyle::of($this->style->fontSize),
            $color,
            $metrics,
        ));
    }

    public function isSelected(): bool
    {
        return $this->selected;
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        // 标签在段盒内居中（容器给等宽紧约束 ⇒ 盒比标签宽，居中才有意义；
        // 自然量那一遍盒 = 标签 + 2*inset，居中是恒等的 1*inset 平移）
        $this->child?->layout($constraints->loosen());

        $inset = $this->style->inset;
        $label = $this->child?->size() ?? new Size(0.0, 0.0);

        // ★ 段盒 = 标签 + 四边 inset：选中块（= 段盒 − 2*inset）必须**装得下标签**。
        //   少了这一圈，`equal` 就等于标签宽、药丸 = 标签宽 − 4 ⇒ 选中态的白字
        //   每边溢出 1px 落到轨道上（真机像素实测：字迹 12px / 药丸 10px）。
        //   加上这一圈之后，"药丸与段边界"的间隙才真的是 inset 那么大。
        $own = $constraints->constrain(new Size(
            $label->width + 2.0 * $inset,
            $label->height + 2.0 * $inset,
        ));

        $dx = 0.0;
        if ($label->width < $own->width) {
            $dx = ($own->width - $label->width) / 2.0;
        }
        $dy = 0.0;
        if ($label->height < $own->height) {
            $dy = ($own->height - $label->height) / 2.0;
        }
        $this->child?->setOffset($dx, $dy);

        return $own;
    }

    /**
     * 内禀宽 = 标签宽 + 2*inset（与 `performLayout` 同式）。
     *
     * ★ 必须覆写：`RenderProxyBox` 的内禀直接委托给孩子（= 标签宽，不含 inset）
     *   ⇒ 不覆写就会出现"内禀 14、实排 18"那类自相矛盾，`RenderStack` 按内禀
     *   分配的父容器会把轨道挤窄。
     */
    #[\Override]
    protected function contentIntrinsicWidth(float $height): float
    {
        $child = $this->child?->intrinsicWidth($height) ?? 0.0;

        return $child + 2.0 * $this->style->inset;
    }

    #[\Override]
    protected function contentIntrinsicHeight(float $width): float
    {
        $child = $this->child?->intrinsicHeight($width) ?? 0.0;

        return $child + 2.0 * $this->style->inset;
    }

    /**
     * 选中块是**背景**（chrome），按 `final paint()` 的层序它必须排在状态层**之下** ——
     * 否则悬停叠色被药丸盖住（段选中时指针正好悬在它上面，反馈看不见）。
     *
     * 圆角夹到 `min(w,h)/2`：段窄时（标签短 ⇒ 等宽仍可能小于段高），`h/2` 会超过
     * 半宽 ⇒ 退化为自交圆角。软光栅的 insideRoundRect 内部会夹，Skia 桥不夹 ⇒
     * 两边必须**自己**夹，否则双端不一致（conformance 判据直接红）。
     */
    #[\Override]
    protected function paintOwnChrome(Canvas $canvas): void
    {
        $size = $this->size;
        if (!$this->selected || $size === null) {
            return;
        }

        $inset = $this->style->inset;
        $w = $size->width - 2.0 * $inset;
        $h = $size->height - 2.0 * $inset;
        if ($w <= 0.0 || $h <= 0.0) {
            return;
        }

        $canvas->drawRect(
            new Rect($inset, $inset, $w, $h),
            Paint::fill($this->style->fillSelected),
            $w < $h ? $w / 2.0 : $h / 2.0,
        );
    }

    /** 状态层 = 药丸本身（四边内缩 `inset`），不铺满整个段盒。 */
    #[\Override]
    protected function paintInteraction(Canvas $canvas, ?Size $size): void
    {
        if ($size === null) {
            return;
        }

        $inset = $this->style->inset;
        $w = $size->width - 2.0 * $inset;
        $h = $size->height - 2.0 * $inset;
        if ($w <= 0.0 || $h <= 0.0) {
            return;
        }

        $this->paintStateLayer(
            $canvas,
            new Rect($inset, $inset, $w, $h),
            $w < $h ? $w / 2.0 : $h / 2.0,
        );
    }

    protected function paintContent(Canvas $canvas): void
    {
        $size = $this->size ?? new Size(0.0, 0.0);
        if ($size->width <= 0.0 || $size->height <= 0.0) {
            return; // 没排过 ⇒ 无墨（与 RenderToggle 同判据）
        }

        // 标签（偏移已在 performLayout 里定好；RenderProxyBox 负责 translate）
        parent::paintContent($canvas);
    }
}
