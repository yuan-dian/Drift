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
 * 按钮盒：盒样式 + 居中的「图标 + 标签」（§A.1）。
 *
 * ★ **只吃解析好的值**（盒样式、前景色、内边距、字号、图标路径全由构造参数给）——
 *   因为它在 `Drift\Layout`，而 §4.3 不许 Layout 依赖 Theme。"变体 → token → 值"的
 *   解析发生在 widget 层（`Button::createRenderObject()` 经 `BuildContext`），
 *   所以合并仍然只发生一次（§15.7 纪律 3），只是落点在能碰主题的那一层。
 *
 * ★ 标签是**它自己的孩子**（`RenderText`，问注入的度量）—— 于是按钮里的文字
 *   断行行为与独立 `Text` 完全一致，不会出现"按钮里的文字换行规则不一样"。
 *
 * ★ **图标为什么是字段而不是孩子**（2026-10-25）：`Flex::createRenderObject()` 只建
 *   `RenderFlex` 空壳，子盒由 Element 层挂 —— 在 Widget 层手工拼 `Row(图标, 文字)`
 *   会得到一个没有孩子的 `RenderFlex`。所以「图标 + 标签」在**同一个盒**里排：
 *   `label` 仍走 `RenderText`（保住断行口径），图标走一条已缩放的 `Path`。
 *   纯图标按钮 = `label` 传空串（空 `RenderText` 会占一行行高，把正方形撑成竖条）。
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
         *
         * ★ 由 `Button::createRenderObject()` 按变体从主题解析后传入 —— Layout 层
         *   **只吃值**，不依赖 Theme（§4.3 / §15.7 纪律 3）。
         * ★ 叠在底色之上、标签之下（`paintContent()` 的顺序）。
         */
        private readonly ?Color $hoverOverlay = null,
        /** 已按 `$iconSize` 缩放的图标路径；`null` = 本按钮没有图标 */
        private readonly ?Path $iconPath = null,
        private readonly float $iconSize = 0.0,
        /** 图标与文字之间的间距（只有两者都在时才占位） */
        private readonly float $iconGap = 0.0,
        private readonly float $iconStrokeWidth = 0.0,
        /**
         * 内容是否在盒内**居中**。
         *
         * ★ 默认 `true`（按钮语义：Material/Flutter 的按钮文字就是居中）。
         *   菜单 / 导航项要左对齐 —— 居中会让"图标 + 文字"这个整体按标签长度
         *   各自漂移（短标签的图标比长标签靠右 20+ px，一眼就是没对齐）。
         */
        private readonly bool $centerContent = true,
        /**
         * 悬停时的**浮起档位**（`Elevation` 口径；`0.0` = 不浮）。
         *
         * ★ 与 `hoverOverlay` 分工：那个是"变色"（状态层），这个是"离地"
         *   （影子）。`Flat` 变体只变色不浮起 —— 顶条上的图标按钮浮起来会动得
         *   太吵。
         */
        private readonly float $hoverElevation = 0.0,
    ) {
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

    /**
     * 声明了状态色或浮起档位才接受悬停（`08` §4.1）—— 都不声明时连一次
     * `setHovered()` 都不收，免得父链询问"这一层要不要变色"时白跑。
     */
    #[\Override]
    public function acceptsHover(): bool
    {
        return $this->hoverOverlay !== null || $this->hoverElevation > 0.0;
    }

    /**
     * 内禀宽 = **标签宽 + 左右内边距**。
     *
     * ★★★ 必须自己加 `insets`：本类的内边距**不进 `mods`**，而是在
     *   `performLayout()` 里 `deflate`/`inflate` 出来的 ⇒ 基类 `intrinsicWidth()`
     *   补的是 `mods` 的 padding（本类恒为 0）⇒ 不加这一句，内禀宽就等于**纯标签宽**。
     *
     *   后果不是"偏小几个像素"，是**按内禀定宽的容器会把按钮挤到放不下一行**：
     *   `RenderPopupPanel::performLayout()` 用 `child->intrinsicWidth(INF)` 定面板宽
     *   ⇒ 面板宽 ≈ 标签宽 + 面板内边距 ⇒ 按钮拿到的可用宽比标签还窄 ⇒ 中文逐字竖排。
     *   2026-10-25 从 `apps/tutorial` 的浮层段实测到（48 宽的面板里「北京」竖着排，
     *   而闸门全绿 —— `PopupPanel` 的既有消费者 `Select`/`Menu` 都用 `Option`/
     *   `MenuItem`，从没让裸 `Button` 进过面板）。
     *
     *   ★ `RenderMenuItem` 早就补了同一句，注释里记着同款症状（"漏了 insets ⇒
     *     面板偏窄 ⇒ 行内内容溢出报 C5"）—— 本类是同一个坑，只是没有外部消费者。
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

        // ★ 居中基准是**盒的实际尺寸**，不是父给的 `maxWidth`（2026-10-25 修）：
        //   松约束下 `inner->maxWidth` 可能是 400（父的上限），拿它居中会把内容推到
        //   盒子右边外面（图标按钮的图标直接飞出按钮）。紧约束下两者相等，行为不变。
        $innerW = $boxSize->width - $this->insets->horizontal();
        $innerH = $boxSize->height - $this->insets->vertical();

        $originX = 0.0;
        if ($this->centerContent) {
            $originX = max(0.0, ($innerW - $contentW) / 2.0);
        }
        $originY = max(0.0, ($innerH - $contentH) / 2.0);

        // ★ 存**盒坐标**（含 insets）：`paintContent()` 里对图标不再平移一次
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
     *
     * ★ **为什么在 `paintOwnChrome()` 而不是 `paintContent()`**：`paintContent()`
     *   跑在裁剪**之内**，而浮起影子按定义要画到盒外 ⇒ 按钮一旦声明 `clip()`
     *   或 `Overflow::Clip`，影子会被整齐切掉一圈（描边跨边界的一半同理，`AppBar`
     *   的 1px 底边就是这么消失过）。盒坐标系两者一致，位置不动。
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

        // ★ `hoverProgress()` 不建槽：没被悬停过就直接是 0，不会给每个按钮常驻
        //   一份动画状态（建槽只发生在 `setHovered()`）。
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

        // 状态层（悬停）：叠在底色/边框之上、内容之下 —— "压暗还是提亮"由状态色
        // 自己表达（Primary/Danger 给白色半透明，Default/Flat 给主题 Hover 色）。
        // ★ 颜色自带的 alpha 再乘过渡进度 ⇒ 淡入而不是硬切。
        $overlay = $this->hoverOverlay;
        if ($overlay !== null) {
            $alpha = (int) \round($overlay->a * $progress);
            if ($alpha > 0) {
                $canvas->drawRect($rect, Paint::fill($overlay->withAlpha($alpha)), $radius);
            }
        }
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
            // ★ 必须带上 child 自己的 offset（`RenderProxyBox::paintContent()` 就是这么做的）。
            //   2026-10-25 之前这里只 `translate(insets)` ⇒ `setOffset()` 的居中**从未生效**，
            //   一直靠"盒宽 = 内容宽"掩盖成"贴左上也不算太怪"；加了图标才暴露成
            //   "图标和首字叠在一起"。
            $canvas->translate(
                $this->insets->left + $this->child->offsetX(),
                $this->insets->top + $this->child->offsetY(),
            );
            $this->child->paintComposed($canvas);
            $canvas->restore();
        }
    }
}
