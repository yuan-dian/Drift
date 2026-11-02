<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\Alignment;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderProxy;

/**
 * 把唯一的孩子摆在本盒内的某个**锚点**上（九宫格 + 任意分数）。
 *
 * ```php
 * Align::make($content)->alignment(Alignment::bottomRight());
 * Align::make($badge, Alignment::topRight())->boxSize(120.0, 80.0);
 * ```
 *
 * ## 它只是 `alignContent()` 的类形态
 *
 * 本类**不持有布局逻辑**：构造时 `alignContent($a)`，之后由 `RenderBox::layout()`
 * 的 ③（松下界）与 ⑦（平移直接子盒）施加。所以
 * `SizedBox::make(120, 80)->alignContent($a)->child($x)` 与
 * `Align::make($x, $a)->boxSize(120, 80)` **完全等价** —— 两者走同一段代码。
 *
 * ★ **不"撑满有界轴"**（这是与 Flutter `Align` 的唯一语义差异，也是刻意的）：
 *   Drift 的 `RenderFlex::childConstraints()` 给非弹性子**有界主轴**，而 Flutter 给
 *   `0..INF` ⇒ 撑满会在 `Row` 里吃掉整行、把兄弟挤出去（2026-10-23 实测 C6 Fatal）。
 *   要撑满请让父给 tight 约束（`Stack(Expand)` / `Dock` 的 center 槽 / 应用根），
 *   或直接 `boxSize()` / `width()`。
 *
 * ★ **不支持 `widthFactor` / `heightFactor`**：那两个参数的用途是"在没有父约束时
 *   按孩子尺寸的倍数给自己定尺寸"，而 Drift 的盒**本来就等于内容尺寸**（同一个
 *   前提下 Flutter 也收缩）⇒ 倍数在 Drift 里只有 `1.0` 有意义，多写一个参数是
 *   假配置。要放大用 `boxSize()`。
 *
 * ★ 构造参数写 `?Alignment = null` 而不是 `Alignment $a = new Alignment()`：
 *   "默认参数里 `new`"在 `src/` 里**零先例**，tpc 是否支持未验证 ⇒ 不赌。
 *
 * @api
 */
class Align extends NullableChildRenderObjectWidget
{
    private Alignment $alignment;

    public function __construct(?Alignment $alignment = null)
    {
        parent::__construct();

        // 默认 `center`（与 Flutter 的 `Align` 一致）；`Center` 是本类的 0 号特例。
        $this->alignment = $alignment ?? Alignment::center();
        $this->alignContent($this->alignment);
    }

    public static function make(?Widget $kid = null, ?Alignment $alignment = null): self
    {
        $self = new self($alignment);
        if ($kid !== null) {
            $self->kid = $kid;
        }

        return $self;
    }

    /**
     * 换锚点。
     *
     * ★ **必须同时写回 `mods`**：布局只读 `mods.alignment()`，只改本对象的字段
     *   等于"改了没反应、也不报错"（对齐是基类 ⑦ 步施加的，不在这里）。
     */
    public function alignment(Alignment $alignment): static
    {
        $this->mutate();
        $this->alignment = $alignment;
        $this->alignContent($alignment);

        return $this;
    }

    public function kid(): ?Widget
    {
        return $this->kid;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderProxy();
    }
}
