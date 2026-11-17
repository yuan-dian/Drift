<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\BoxChrome;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\Canvas;

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
        // ★ `Overflow::Clip`（2026-10-19 改，原 Report）：顶条的宽度**随窗口变化**
        //   —— 用户把窗口拖窄时，标题 + 导航项放不下是**用户行为**，不是编程错误。
        //   原先 Report ⇒ 拖动缩窗弹 Fatal（实测 322 可用宽 vs 327 自然宽）。
        //   裁剪语义：右侧导航项被裁掉（优于 Fatal；腾挪/折叠留给后续设计）。
        parent::__construct(false, $spacing, $padding, FlexAlign::Center, Overflow::Clip);
    }

    /**
     * chrome 画在裁剪**之外**：本盒声明 `Overflow::Clip`（见构造器），描边跨在盒边界上
     * ⇒ 画进 `paintContent()` 的话，外半边会被自己的裁剪切掉。
     */
    #[\Override]
    protected function paintOwnChrome(Canvas $canvas): void
    {
        $size = $this->size ?? new Size(0.0, 0.0);
        BoxChrome::paint($canvas, new Rect(0.0, 0.0, $size->width, $size->height), $this->box);
    }
}
