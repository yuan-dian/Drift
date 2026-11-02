<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * **可空子、可改子的单子控件**（§DEBT D2）—— "我最多有一个子节点"。
 *
 * `Align` / `AspectRatio` / `SizedBox` / `Card` 全是这个形状。
 *
 * ## 与 `SingleChildRenderObjectWidget` 的两处真实差别
 *
 * | | 必填子（`Padding` / `Opacity` / `Expanded` …） | 可空子（本类） |
 * |---|---|---|
 * | 子槽 | `readonly Widget $kid`（构造期定死） | `?Widget $kid = null`（可改） |
 * | `child()` | **getter** `child(): Widget` | **setter** `child(Widget $kid): static` |
 * | `expandChildren()` | `[$this->kid]` | `$kid === null ? [] : [$kid]` |
 *
 * ★ **`child()` 同名不同签名**正是 D2 要治的那个病：四个可空子控件的 setter
 *   **逐字相同**（`mutate()` + 赋值 + `return $this`），却各抄了一遍。
 *   收进这里之后，新加一个可空子容器一行都不用写。
 *
 * ## 为什么是两个类而不是一个
 *
 * Flutter 只有一个（`child` 是 `Widget?`），因为 Dart 调用方天然容忍 `null`。
 * 本仓有 6 个控件把"子必填"写进了**返回类型**（`child(): Widget`），
 * `ScrollViewState` 还直接拿它当非空用。合成一个就得把那 6 处契约降级成 `?Widget`
 * —— 那等于把"必填/可空"这个**真实的语义差别**从类型里抹掉，正好是 D2 要杀的
 * "用空实现代替形状分类"。
 *
 * ⇒ 两个类各 4 行重复（`expandChildren` / `createElement`），换 6 处契约不动。
 *
 * @api
 */
abstract class NullableChildRenderObjectWidget extends RenderBoxWidget
{
    /** 唯一子节点；`null` = 没有子 */
    protected ?Widget $kid = null;

    /**
     * ★ 构造器**不强制收子**：`Align` / `AspectRatio` / `SizedBox` 的子是在
     *   `make()` 里直接赋的（它们各自的构造参数是布局参数，不是子）。收成必填参数
     *   会把它们的工厂方法全部改写 —— 而这件事跟"形状"无关。
     */
    protected function __construct(?Widget $kid = null)
    {
        $this->kid = $kid;

        parent::__construct();
    }

    /** 换子（四个控件原本逐字相同的 setter） */
    public function child(Widget $kid): static
    {
        $this->mutate();
        $this->kid = $kid;

        return $this;
    }

    #[\Override]
    final public function expandChildren(): array
    {
        return $this->kid === null ? [] : [$this->kid];
    }

    #[\Override]
    final public function createElement(): Element
    {
        return new ProxyElement($this);
    }
}
