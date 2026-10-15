<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\EdgeInsets;
use Drift\Layout\FlexAlign;
use Drift\Layout\Overflow;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderFlex;

/**
 * `Column` / `Row` 的共同基座 —— 差别只有一个方向布尔（§13.1 的 `RenderFlex`）。
 *
 * ★ **继承而非两份拷贝**：两个类的糖方法必须逐字相同，抄两份必然漂移
 *   （GUI 仓的教训）。也不用 trait 共享 —— §15.7 纪律：trait 混入多个类在 AOT 下
 *   展开成什么没验过，继承已验。
 *
 * ★ **结构节点，不是 `BoxWidget`**：它有布局职责但没有盒样式 ⇒ 继承裸 `Widget`，
 *   拿不到 `->background()`（§15.7 纪律 1）。
 */
abstract class Flex extends RenderBoxWidget
{
    /** @var list<Widget> */
    protected array $kids = [];
    protected float $spacing = 0.0;
    protected EdgeInsets $padding;
    protected FlexAlign $cross = FlexAlign::Start;

    /**
     * C5：会超的容器必须**显式声明**。默认 Report 而不是 Clip —— 溢出在这种容器上
     * 几乎总是布局错误，静默裁剪让错误活到用户面前（G2 绝不静默）。要裁剪就
     * 明确写 `->overflow(Overflow::Clip)`。
     */
    protected Overflow $overflow = Overflow::Report;

    protected function __construct()
    {
        parent::__construct();
        $this->padding = EdgeInsets::zero();
    }

    /** true = `Column`（主轴竖）；false = `Row`（主轴横） */
    abstract protected function isVertical(): bool;

    /** @param list<Widget> $kids */
    public function children(array $kids): static
    {
        $this->mutate();
        $this->kids = $kids;

        return $this;
    }

    /** 主轴相邻子的间距（不含 padding） */
    public function spacing(float $value): static
    {
        $this->mutate();
        $this->spacing = $value;

        return $this;
    }

    public function padding(EdgeInsets $value): static
    {
        $this->mutate();
        $this->padding = $value;

        return $this;
    }

    /** 四边同值的便捷写法 —— 几何面，不是盒样式（§15.7） */
    public function paddingAll(float $value): static
    {
        return $this->padding(EdgeInsets::all($value));
    }

    public function cross(FlexAlign $value): static
    {
        $this->mutate();
        $this->cross = $value;

        return $this;
    }

    public function overflow(Overflow $value): static
    {
        $this->mutate();
        $this->overflow = $value;

        return $this;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderFlex(
            $this->isVertical(),
            $this->spacing,
            $this->padding,
            $this->cross,
            $this->overflow,
        );
    }

    /** @return list<Widget> */
    public function kidList(): array
    {
        return $this->kids;
    }

    public function createElement(): Element
    {
        return new FlexElement($this);
    }
}
