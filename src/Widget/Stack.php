<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\Alignment;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderStack;
use Drift\Layout\StackFit;

/**
 * 层叠容器（arch-v2 `04` §4，W6 L4-4）：**后加的子压在上面**。
 *
 * ```php
 * Stack::make()
 *     ->alignment(Alignment::topRight())
 *     ->fit(StackFit::Loose)
 *     ->children([$content, $badge->positioned(right: 8.0, top: 8.0)]);
 * ```
 *
 * ## 定位（`A4`）
 *
 * 子控件上 `->positioned(left:, top:, right:, bottom:)` 声明它相对**本盒**的位置：
 * 声明**一条边** ⇒ 贴那边；声明**两条对边** ⇒ 那一轴被**拉伸**；没声明的轴按
 * `alignment()`。细节与三条契约见 `RenderStack::layoutPositioned()` 与
 * `tests/Unit/Layout/StackPositionedTest.php`。
 *
 * ★ **定位子不撑大本盒** ⇒ 只有定位子的 `Stack` 尺寸是 0 ⇒ 那种写法要自己套
 *   `SizedBox`。这是设计决定（解开"套尺寸 ↔ 摆位置"的循环依赖），不是缺陷。
 *
 * ★ **结构节点，不是 `BoxWidget`**：它有布局职责，样式（背景 / 圆角 / 内距）由
 *   `Widget` 基类的修饰字段提供 —— W5 之后修饰是**字段**，任何控件都有。
 *
 * ★ **没有 `zIndex`**（`04` §4.3）：绘制顺序 = `children` 顺序。要改层叠就改顺序。
 *   两套顺序必然打架，且"同 z 值时谁在上"需要稳定排序规则，规则一复杂就没人记得住。
 *
 * ★ **没有 `overflow()`**（`04` §4.2 的裁决）：本盒尺寸就是子的最大尺寸 ⇒ 不会超；
 *   而 `Overflow::Clip` 今天不真裁（`src/Rendering/` 没有裁剪 API）。加一个不生效的
 *   必填参数是假保障。真裁剪归 `Modifier::clip`（未排期）。
 *
 * @api
 */
final class Stack extends MultiChildRenderObjectWidget
{
    /** @var list<Widget> */
    private array $kids = [];
    private ?Alignment $align = null;   // null = TopLeft（RenderStack 的默认）
    private StackFit $fit = StackFit::Loose;

    public static function make(): self
    {
        return new self();
    }

    /** @param list<Widget> $kids 顺序即层叠序（后面的压在上面） */
    public function children(array $kids): static
    {
        $this->mutate();
        $this->kids = $kids;

        return $this;
    }

    public function alignment(Alignment $value): static
    {
        $this->mutate();
        $this->align = $value;

        return $this;
    }

    public function fit(StackFit $value): static
    {
        $this->mutate();
        $this->fit = $value;

        return $this;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderStack($this->align, $this->fit);
    }

    /** @return list<Widget> */
    public function kidList(): array
    {
        return $this->kids;
    }
}