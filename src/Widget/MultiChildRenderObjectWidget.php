<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * **多子控件**的形状分类（§DEBT D2）—— "我有一组无名字的子节点，顺序即布局顺序"。
 *
 * ```php
 * final class Wrap extends MultiChildRenderObjectWidget
 * {
 *     public function kidList(): array { return $this->kids; }
 *
 *     public function createRenderObject(BuildContext $context): RenderBox
 *     {
 *         return new RenderWrap(...);
 *     }
 * }
 * ```
 *
 * 与 `SingleChildRenderObjectWidget` / `NullableChildRenderObjectWidget` 并列，
 * 三个类合起来就是本仓控件形状的全集。子从哪来（数组字段 / 构造展开 / 主题拼装）
 * 由子类的 `kidList()` 决定 —— 这是**唯一**的扩展点。
 *
 * ## 为什么 `kidList()` 而不是复用一个 `children()` 字段
 *
 * 五个多子控件的子来源本来就不同：`Flex`/`Stack`/`Wrap` 是构造时展开的参数列表，
 * `AppBar` 是 `title` + `actions` 拼出来的，`SegmentedControl` 是 `items`。
 * 统一成一个字段会让其中至少三个先构造一个临时数组再赋进去 —— 那只是把
 * "各写一个方法"换成"各写一段搬家代码"。
 *
 * 基线里这五个控件的 `kidList()` **本来就同名同签名**（`public function kidList(): array`），
 * 所以这一步没有改名、没有迁移调用点，只是把它提升成父类的契约。
 *
 * ## 子盒的挂法
 *
 * 由 {@see MultiChildElement} 统一做（`clearChildren()` + 逐个 `addChild()`），
 * 它只认 {@see \Drift\Layout\ChildContainer} 这个接口，不认具体盒类型。
 *
 * @api
 */
abstract class MultiChildRenderObjectWidget extends RenderBoxWidget
{
    /**
     * 子层（顺序即布局与绘制顺序）。
     *
     * @return list<Widget>
     */
    abstract public function kidList(): array;

    #[\Override]
    final public function expandChildren(): array
    {
        return $this->kidList();
    }

    #[\Override]
    final public function createElement(): Element
    {
        return new MultiChildElement($this);
    }
}
