<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;
use Drift\Rendering\Path;
use Drift\Rendering\ShadowPainter;
use Drift\Text\TextMetricsProvider;
use Drift\Text\TextStyle;

/**
 * 按钮盒：盒样式 + 居中的「图标 + 标签」。
 *
 * 只吃解析好的值（盒样式、前景色、内边距、字号、图标路径全由构造参数给），
 * 不动 Theme（"变体 → token → 值"的解析发生在 widget 层）。
 * 图标与标签在**同一个盒**里排：标签走 `RenderText`（保住独立 `Text` 的断行口径），
 * 图标走一条已缩放的 `Path`。纯图标按钮传空 label —— 空 `RenderText` 占一行行高，
 * 会把正方形撑成竖条。
 *
 * @api
 */
final class RenderButton extends RenderProxyBox
{
    /** 图标绘制原点（**盒坐标**，已含 insets：`paintContent()` 里不加二次平移） */
    private float $iconX = 0.0;

    private float $iconY = 0.0;

    public function __construct(
        string $label,
        private readonly BoxStyle $box,
        private readonly Color $labelColor,
        private readonly EdgeInsets $insets,
        private readonly float $fontSize,
        TextMetricsProvider $metrics,
        /**
         * 悬停态叠加色（`null` = 本按钮不做悬停反馈）。
         * 由 `Button::createRenderObject()` 按变体从主题解析后传入 —— Layout 层只吃值，不依赖 Theme。
         * 叠色本身由 `RenderBox::paint()` 统一画（①.6），这里只负责把值落给基类。
         */
        ?Color $hoverOverlay = null,
        /** 已按 `$iconSize` 缩放的图标路径；`null` = 本按钮没有图标 */
        private readonly ?Path $iconPath = null,
        private readonly float $iconSize = 0.0,
        /** 图标与文字之间的间距（只有两者都在时才占位） */
        private readonly float $iconGap = 0.0,
        private readonly float $iconStrokeWidth = 0.0,
        /**
         * 内容是否在盒内**居中**。
         * 默认 `true`（按钮语义：文字居中）。菜单 / 导航项要左对齐 —— 居中会让
         * "图标 + 文字"整体按标签长度漂移（短标签的图标比长标签靠右，一眼没对齐）。
         */
        private readonly bool $centerContent = true,
        /**
         * 悬停时的**浮起档位**（`Elevation` 口径；`0.0` = 不浮）。
         * 与 `hoverOverlay` 分工：那个是"变色"（状态层），这个是"离地"（影子）。
         * `Flat` 变体只变色不浮起 —— 顶条上的图标按钮浮起来会动得太吵。
         */
        private readonly float $hoverElevation = 0.0,
        /**
         * 按下态叠加色（`null` = 本按钮不做按下反馈）。
         *
         * ★ 与 `hoverOverlay` **分成两个参数而不是一个"状态色表"**：两者的
         *   过渡是**两条独立的斜坡**（按下时 hover 仍在 1.0，抬起后 hover 还要
         *   留着），合成一个值就没法表达"按下比悬停深一档、松开回到悬停档"。
         *
         * ★ 排在末尾而不是紧跟 `hoverOverlay`：位置参数一旦插在中间，所有既有
         *   调用点会**静默错位**（`$iconPath` 收到一个 `Color`）—— AOT 下这是
         *   编译错，解释器下可能一路跑到 `drawPath` 才炸。
         */
        ?Color $pressedOverlay = null,
    ) {
        // 在纯图标的早退之前：状态色与有没有孩子无关
        $this->setInteraction($hoverOverlay, $pressedOverlay);

        if ($label === '') {
            return;   // 纯图标：不建 `RenderText`（空串仍占一行行高）
        }

        $this->setChild(new RenderText(
            $label,
            TextStyle::of($fontSize),
            $labelColor,
            $metrics,
        ));
    }

    public function boxStyle(): BoxStyle
    {
        return $this->box;
    }

    /** 有图标吗（夹具 / devtools 用） */
    public function hasIcon(): bool
    {
        return $this->iconPath !== null;
    }

    /** 内容是否居中（夹具 / devtools 用）—— `false` 就是左对齐，见 `Button::alignLeft()` */
    public function centersContent(): bool
    {
        return $this->centerContent;
    }

    #[\Override]
    protected function interactionRadius(): float
    {
        return $this->box->radius;
    }

    /**
     * 内禀宽 = **标签宽 + 左右内边距**。
     *
     * ★ 必须自己加 `insets`：本类的内边距**不进 `mods`**，而是在 `performLayout()`
     *   里 `deflate`/`inflate` 出来 ⇒ 基类补的是 `mods` 的 padding（本类恒为 0）⇒
     *   不加这一句，内禀宽就等于**纯标签宽**。后果不是"偏小几像素"，是**按内禀定宽的
     *   容器会把按钮挤到放不下一行**（面板宽 ≈ 标签宽 + 面板内边距 ⇒ 按钮可用宽比
     *   标签还窄 ⇒ 中文逐字竖排）。
     */
    #[\Override]
    protected function contentIntrinsicWidth(float $height): float
    {
        return $this->insets->horizontal() + $this->contentWidth($height);
    }

    /** 内禀高 = max(图标, 标签) + 上下内边距（同 `contentIntrinsicWidth` 的理由） */
    #[\Override]
    protected function contentIntrinsicHeight(float $width): float
    {
        $label = parent::contentIntrinsicHeight($width);

        if ($this->iconPath === null) {
            return $this->insets->vertical() + $label;
        }

        return $this->insets->vertical() + max($this->iconSize, $label);
    }

    /** 内容宽：图标 + 间距（两者都在才占）+ 标签 */
    private function contentWidth(float $height): float
    {
        $label = $this->child?->intrinsicWidth($height) ?? 0.0;
        if ($this->iconPath === null) {
            return $label;
        }

        $gap = 0.0;
        if ($label > 0.0) {
            $gap = $this->iconGap;
        }

        return $this->iconSize + $gap + $label;
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $inner = $constraints->deflate($this->insets);

        $this->child?->layout($inner->loosen());

        $label = $this->child?->size() ?? new Size(0.0, 0.0);

        // 图标 + 标签作为**一个整体**在内容区居中（不是各自居中 —— 那样图标会贴左、
        // 标签会贴右，中间留一条空档）。
        $iconSide = 0.0;
        if ($this->iconPath !== null) {
            $iconSide = $this->iconSize;
        }

        $gap = 0.0;
        if ($iconSide > 0.0 && $label->width > 0.0) {
            $gap = $this->iconGap;
        }

        $contentW = $iconSide + $gap + $label->width;
        $contentH = max($iconSide, $label->height);

        $boxSize = $constraints->constrain($this->insets->inflate(new Size($contentW, $contentH)));

        // ★ 居中基准是**盒的实际尺寸**，不是父给的 `maxWidth`：松约束下
        //   `inner->maxWidth` 可能是父的上限，拿它居中会把内容推到盒子右边外面
        //   （图标按钮的图标直接飞出按钮）。紧约束下两者相等，行为不变。
        $innerW = $boxSize->width - $this->insets->horizontal();
        $innerH = $boxSize->height - $this->insets->vertical();

        $originX = 0.0;
        if ($this->centerContent) {
            $originX = max(0.0, ($innerW - $contentW) / 2.0);
        }
        $originY = max(0.0, ($innerH - $contentH) / 2.0);

        // 存**盒坐标**（含 insets）：`paintContent()` 里对图标不再平移一次
        $this->iconX = $this->insets->left + $originX;
        $this->iconY = $this->insets->top + $originY + ($contentH - $iconSide) / 2.0;

        // 标签的 offset 相对**内容区**（paint 时先 translate(insets)）
        $this->child?->setOffset(
            $originX + $iconSide + $gap,
            $originY + ($contentH - $label->height) / 2.0,
        );

        return $boxSize;
    }

    /**
     * 底 / 边 / 悬停层 / 浮起影子。
     * 画在 `paintOwnChrome()` 而非 `paintContent()`：`paintContent()` 在裁剪之内，
     * 浮起影子要画到盒外 ⇒ 一旦 `clip()`/`Overflow::Clip`，影子会被切掉一圈。
     */
    #[\Override]
    protected function paintOwnChrome(Canvas $canvas): void
    {
        $size = $this->size;
        if ($size === null) {
            return;
        }

        $rect = new Rect(0.0, 0.0, $size->width, $size->height);
        $radius = $this->box->radius;

        // `hoverProgress()` 不建槽：没被悬停过就直接是 0，不会给每个按钮常驻
        // 一份动画状态（建槽只发生在 `setHovered()`）。
        $progress = $this->hoverProgress();

        if ($this->hoverElevation > 0.0 && $progress > 0.0) {
            ShadowPainter::paintOutside($canvas, $rect, $radius, $this->hoverElevation * $progress);
        }

        // 底（实色或渐变 —— 画笔由 `BoxStyle::backgroundPaint()` 收口，比例坐标在
        //    那里按盒尺寸落成绝对像素）
        $backgroundPaint = $this->box->backgroundPaint($size->width, $size->height);
        if ($backgroundPaint !== null) {
            $canvas->drawRect($rect, $backgroundPaint, $radius);
        }
        if ($this->box->borderColor !== null && $this->box->borderWidth > 0.0) {
            $canvas->drawRect($rect, Paint::stroke($this->box->borderColor, $this->box->borderWidth), $radius);
        }

        // 两相位叠色由 `RenderBox::paint()` 统一画（①.6），这里只留浮起影子
    }

    #[\Override]
    protected function paintContent(Canvas $canvas): void
    {
        // 图标与文字同色（`labelColor` 已按变体解析：Primary/Danger 上是画布色）
        if ($this->iconPath !== null && $this->iconStrokeWidth > 0.0) {
            $canvas->save();
            $canvas->translate($this->iconX, $this->iconY);
            $canvas->drawPath($this->iconPath, Paint::stroke($this->labelColor, $this->iconStrokeWidth));
            $canvas->restore();
        }

        if ($this->child !== null) {
            $canvas->save();
            // ★ 必须带上 child 自己的 offset（`RenderProxyBox::paintContent()` 就是这么做的）：
            //   只 `translate(insets)` 会让 `setOffset()` 的居中**从未生效**，
            //   图标和首字叠在一起。
            $canvas->translate(
                $this->insets->left + $this->child->offsetX(),
                $this->insets->top + $this->child->offsetY(),
            );
            $this->child->paintComposed($canvas);
            $canvas->restore();
        }
    }
}
