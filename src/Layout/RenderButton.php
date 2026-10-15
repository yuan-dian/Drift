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

    public function paint(Canvas $canvas): void
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
        $this->child?->paint($canvas);
        $canvas->restore();
    }
}
