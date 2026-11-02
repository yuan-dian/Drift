<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Layout\RenderBackdropFilter;
use Drift\Layout\RenderBox;

/**
 * 毛玻璃（把背后的内容糊掉，再画自己的子树）。
 *
 * ```php
 * BackdropFilter::make(
 *     Card::make()->children([...]),   // 面板自己带半透明底色
 * )->sigma(16.0);
 * ```
 *
 * ## 它解决的是"半透明面板看着脏"这件事
 *
 * `->opacity(0.6)` / 半透明底色能做出"透"，但透出来的是**清晰的**内容 ——
 * 文字叠文字，读不下去。真实系统（macOS 侧栏、Win11 设置、iOS 控制中心）
 * 都是先**糊**再叠色，这就是本控件。
 *
 * ## 用法上的两条
 *
 * ① **面板自己的半透明底色由子控件给**（`->background(Color::rgba(...))`），
 *    本控件只负责糊。分开的理由：糊与着色是两个可独立调节的观感参数，
 *    合成一个 API 会让"只想糊不想着色"或"想用渐变当底色"都变别扭。
 * ② **必须包住面板本身**，不是包住面板里面的内容 —— 它糊的是"自己这块面积
 *    背后的东西"，面积由自己的尺寸决定。
 *
 * ## 降级
 *
 * Raster 后端（测试/金色基线）不实现模糊 ⇒ 退化成"纯半透明面板"，
 * 结构、透明度、层级全部照常。`CAP_BACKDROP` 仍是 Extended 层（近似也算处理），
 * 所以本控件**不做能力查询**，也不会响亮失败。
 *
 * @api
 */
final class BackdropFilter extends SingleChildRenderObjectWidget
{
    private float $sigma;

    private function __construct(Widget $kid)
    {
        parent::__construct($kid);
    }

    /**
     * @param float $sigma 模糊半径（逻辑像素）。12 是"玻璃"、24 以上是"磨砂"，
     *                     0 = 不模糊（等价于什么都不做）
     *
     * @api
     */
    public static function make(Widget $kid, float $sigma = 12.0): self
    {
        $filter = new self($kid);
        $filter->sigma = $sigma;

        return $filter;
    }

    /**
     * 改模糊半径。
     *
     * ★ 与 `opacity()` 一样是**声明值**：改它会重建盒（模糊半径不是每帧都在变的
     *   动画量，走 `setCompose()` 那条零重建的路不值当）。要做"玻璃逐渐变糊"的
     *   动画，用 `AnimatedValue` 驱动 rebuild。
     *
     * @api
     */
    public function sigma(float $value): static
    {
        $this->mutate();

        if ($value < 0.0) {
            Errors::report(\sprintf('BackdropFilter：模糊半径不能为负，收到 %.3f', $value));
        }

        $this->sigma = $value;

        return $this;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderBackdropFilter($this->sigma);
    }
}
