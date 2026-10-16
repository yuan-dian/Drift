<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\Canvas;
use Drift\Rendering\Paint;

/**
 * 顶条盒：chrome 底 + 一行子项（§12.1 第 3 条的 `top` 槽常驻内容）。
 *
 * ★ **继承 `RenderFlex`**（横轴）而不是新写一套子布局 —— 条就是一行弹性
 *   容器，差异只在"有底色"。溢出策略随 `RenderFlex` 的纪律显式 `Report`
 *   （W4：外壳类容器必须声明）。
 *
 * @api
 */
final class RenderAppBar extends RenderFlex
{
    public function __construct(
        private readonly BoxStyle $box,
        float $spacing,
        EdgeInsets $padding,
    ) {
        parent::__construct(false, $spacing, $padding, FlexAlign::Center, Overflow::Report);
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

        parent::paint($canvas);
    }
}
