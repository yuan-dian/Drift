<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Layout\RenderCustomPaint;

/**
 * 自绘控件（`CustomPainter` 的 Widget 侧，2026-10-20 P2 落地）。
 *
 * ```php
 * // 仪表盘：画家自己画，控件只声明尺寸
 * new CustomPaint(new GaugePainter($value), 240.0, 160.0);
 * ```
 *
 * ## 尺寸语义（与 `SizedBox` 的 null 哲学一致，但没定的轴行为不同）
 *
 * | 轴 | 给值 | `null` |
 * |---|---|---|
 * | 行为 | 钉死（父更紧 ⇒ 听父，同 `SizedBox`） | **吃满可用的那一轴** |
 *
 * ★ **没定的轴吃满约束上限而不是 0**：逃生舱最常见的形态是"这块区域归我"，
 *   给 0 是静默不画（19 op 面板全对、自绘区一片空白 —— 最难查的形状之一）。
 * ★ **没定且约束不限 ⇒ 响亮失败**（现象 + 两条修法，不写内部编号）：自绘区
 *   不能无穷大，无限轴上必须显式给值。
 * ★ **自然尺寸不猜**：`Row` / `Dock` 走 `intrinsicWidth/Height` 量自然宽时，
 *   没给的轴量到 0 —— 画家对"我该多大"没有意见，要参与自然尺寸度量就给值
 *   （或外层 `SizedBox` 定尺寸）。
 *
 * ★ **构造器即终态**（readonly，无 fluent setter）：与 `PopupPanel` /
 *   `Card(?:kid)` 的新控件线一致；Widget 不可变 ⇒ 参数只会整体换新（§13.2）。
 *
 * @api
 */
final class CustomPaint extends RenderBoxWidget
{
    public function __construct(
        private readonly CustomPainter $painter,
        private readonly ?float $width = null,
        private readonly ?float $height = null,
    ) {
    }

    public static function make(
        CustomPainter $painter,
        ?float $width = null,
        ?float $height = null
    ): self {
        return new self($painter, $width, $height);
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderCustomPaint($this->painter, $this->width, $this->height);
    }
}
