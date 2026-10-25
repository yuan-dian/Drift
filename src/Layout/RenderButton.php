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
use Drift\Text\TextMetricsProvider;
use Drift\Text\TextStyle;

/**
 * 按钮盒：盒样式 + 居中标签（§A.1）。
 *
 * ★ **只吃解析好的值**（盒样式、标签色、内边距、字号全由构造参数给）—— 因为它
 *   在 `Drift\Layout`，而 §4.3 不许 Layout 依赖 Theme。"变体 → token → 值"的
 *   解析发生在 widget 层（`Button::createRenderObject()` 经 `BuildContext`），
 *   所以合并仍然只发生一次（§15.7 纪律 3），只是落点在能碰主题的那一层。
 *
 * ★ 标签是**它自己的孩子**（`RenderText`，问注入的度量）—— 于是按钮里的文字
 *   断行行为与独立 `Text` 完全一致，不会出现"按钮里的文字换行规则不一样"。
 *
 * @api
 */
final class RenderButton extends RenderProxyBox
{
    public function __construct(
        string $label,
        private readonly BoxStyle $box,
        private readonly Color $labelColor,
        private readonly EdgeInsets $insets,
        private readonly float $fontSize,
        TextMetricsProvider $metrics,
    ) {
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
        return $this->insets->horizontal() + parent::contentIntrinsicWidth($height);
    }

    /** 内禀高 = 标签高 + 上下内边距（同 `contentIntrinsicWidth` 的理由） */
    #[\Override]
    protected function contentIntrinsicHeight(float $width): float
    {
        return $this->insets->vertical() + parent::contentIntrinsicHeight($width);
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $inner = $constraints->deflate($this->insets);

        $this->child?->layout($inner->loosen());

        $label = $this->child?->size() ?? new Size(0.0, 0.0);

        // 标签在内容区居中（内容定了就居中；父若要另算，自己 setOffset）
        $this->child?->setOffset(
            max(0.0, ($inner->maxWidth - $label->width) / 2.0),
            max(0.0, ($inner->maxHeight - $label->height) / 2.0),
        );

        return $constraints->constrain($this->insets->inflate($label));
    }

    #[\Override]
    protected function paintContent(Canvas $canvas): void
    {
        $size = $this->size ?? new Size(0.0, 0.0);
        $rect = new Rect(0.0, 0.0, $size->width, $size->height);

        if ($this->box->background !== null) {
            $canvas->drawRect($rect, Paint::fill($this->box->background), $this->box->radius);
        }
        if ($this->box->borderColor !== null && $this->box->borderWidth > 0.0) {
            $canvas->drawRect($rect, Paint::stroke($this->box->borderColor, $this->box->borderWidth), $this->box->radius);
        }

        $canvas->save();
        $canvas->translate($this->insets->left, $this->insets->top);
        $this->child?->paintComposed($canvas);
        $canvas->restore();
    }
}
