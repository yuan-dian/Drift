<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;
use Drift\Text\TextMetricsProvider;
use Drift\Text\TextStyle;

/**
 * 菜单项盒：一行 `[label ……… chord]`，或一条分隔线（§9.1 语义 #7"整行可点"）。
 *
 * ★ 与 `RenderButton` 同一形状（只吃解析好的值 + 标签是自己的孩子），差两点：
 *   ① 标签**左对齐**、快捷键提示**右对齐**（行内 `Spacer` 撑开）；
 *   ② **宽度由面板统一给**（`RenderPopupPanel` 先量最宽项再以 tight 宽布局）——
 *      所以"整行可点"是面板与行两级配合的结果，不是本盒自己算的。
 *
 * ★ **两种字体都走注入的度量**（`TextMetricsProvider`）—— 于是菜单里的文字
 *   断行 / 步进与独立 `Text` 完全一致，也顺带满足"确定性注入"（§14.2）。
 *
 * ★ `separator` 形态**不造子**（免得空文本盒在行内占位），布局出来是一条
 *   `separatorHeight` 的横条，绘制时在中间画 1px 线。
 *
 * @api
 */
final class RenderMenuItem extends RenderProxyBox
{
    /** 分隔线上下留白之和 */
    private const float SEPARATOR_HEIGHT = 7.0;

    public function __construct(
        string $label,
        ?string $chord,
        private readonly bool $separator,
        private readonly Color $labelColor,
        private readonly Color $chordColor,
        private readonly Color $dividerColor,
        private readonly EdgeInsets $insets,
        private readonly float $fontSize,
        TextMetricsProvider $metrics,
        ?Color $hoverOverlay = null,
        ?Color $pressedOverlay = null,
    ) {
        if ($separator) {
            return; // 分隔线没有孩子、不可点 —— 不声明两相位反馈
        }

        $this->setInteraction($hoverOverlay, $pressedOverlay);

        // 行：label · Spacer(flex 1) · chord（无 chord 时省略后两段）
        $row = new RenderFlex(false, 12.0, EdgeInsets::zero(), FlexAlign::Center, Overflow::Report);
        $row->addChild(new RenderText($label, TextStyle::of($fontSize), $labelColor, $metrics));

        if ($chord !== null) {
            // 份额 1 的纯转发盒（`RenderFlexible` 在 M2-4 删除）。它不经 Element
            // ⇒ 没有 `setMods()` 这一步，直接写盒上的意图字段。
            $gap = new RenderProxy();
            $gap->setFlexIntent(1, 0, \NAN, RenderBox::ALIGN_INHERIT);
            $row->addChild($gap);
            $row->addChild(new RenderText($chord, TextStyle::of($fontSize), $chordColor, $metrics));
        }

        $this->setChild($row);
    }

    public function isSeparator(): bool
    {
        return $this->separator;
    }

    /**
     * ★ **内禀宽要含内边距**（`RenderPadding` 同款）：面板按"最宽项"定宽
     *   （`RenderPopupPanel`），漏了 insets ⇒ 面板偏窄 ⇒ 行内内容溢出报 C5
     *   （开发中被 smoke 当场抓到）。
     */
    #[\Override]
    protected function contentIntrinsicWidth(float $height): float
    {
        if ($this->separator) {
            return $this->insets->horizontal();
        }

        return $this->insets->horizontal() + parent::contentIntrinsicWidth($height);
    }

    #[\Override]
    protected function contentIntrinsicHeight(float $width): float
    {
        if ($this->separator) {
            return self::SEPARATOR_HEIGHT;
        }

        return $this->insets->vertical() + parent::contentIntrinsicHeight($width);
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        if ($this->separator) {
            $h = min(self::SEPARATOR_HEIGHT, $constraints->maxHeight);

            return $constraints->constrain(new Size($constraints->minWidth, $h));
        }

        $inner = $constraints->deflate($this->insets);
        $this->child?->layout($inner->loosen());

        $content = $this->child?->size() ?? new Size(0.0, 0.0);
        $this->child?->setOffset($this->insets->left, $this->insets->top);

        return $constraints->constrain($this->insets->inflate($content));
    }

    #[\Override]
    protected function paintContent(Canvas $canvas): void
    {
        $size = $this->size ?? new Size(0.0, 0.0);

        if ($this->separator) {
            $y = $size->height / 2.0;
            $canvas->drawRect(
                new Rect($this->insets->left, $y, max(0.0, $size->width - $this->insets->horizontal()), 1.0),
                Paint::fill($this->dividerColor),
            );

            return;
        }

        $canvas->save();
        $canvas->translate($this->insets->left, $this->insets->top);
        $this->child?->paintComposed($canvas);
        $canvas->restore();
    }
}
