<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * **单子控件**的形状分类（§DEBT D2）—— "我恰好有一个子节点"。
 *
 * ```php
 * final class Padding extends SingleChildRenderObjectWidget
 * {
 *     public static function make(Widget $kid): self { return new self($kid); }
 *
 *     public function createRenderObject(BuildContext $context): RenderBox
 *     {
 *         return new RenderProxy();
 *     }
 * }
 * ```
 *
 * 三个方法只剩一个：`child()` / `expandChildren()` / `createElement()` 都由本类给，
 * 再也不用每个容器抄一遍。`ProxyElement` 里的 `adoptBoxes()`（把子盒收进 `RenderProxyBox`）
 * 本来就是这份管子的唯一实现，本类只是让"声明子"这件事不再列在控件里。
 *
 * ## 为什么子槽是 `readonly`，而 `Widget` 其余字段可变
 *
 * 本仓的结构与修饰是**两条可变性**：
 * - **修饰可变** —— `->paddingAll(8)` 走 `mutate()` + `return $this`（`Widget` 主干）。
 * - **结构不可变** —— `Padding::make($kid)` 的子只在构造期落一次。`Widget` 重建时
 *   整体换新（§13.2 盒换新），所以没有"就地换子"的需求。
 *
 * Flutter 全员不可变（`copyWith`），本仓没有那个语法糖、AOT 下也不愿多分配一次；
 * 但单子控件的子**本来就是不可变的**（`Padding` / `Center` / `SizedBox` 一直是
 * `readonly` 构造器注入）。这里跟随本仓既有事实，不是跟随 Flutter。
 *
 * ★ **子不可空**：`->child()` 的调用方全部直接当非空用
 *   （`[$widget->child()]`、`->createElement()`）。可空子的控件（`Spacer` 之类）
 *   继续直接继承 `RenderBoxWidget` —— 抽象只覆盖同形的多数，不追求 100%。
 *
 * @api
 */
abstract class SingleChildRenderObjectWidget extends RenderBoxWidget
{
    /** 唯一子节点 */
    protected readonly Widget $kid;

    protected function __construct(Widget $kid)
    {
        $this->kid = $kid;

        parent::__construct();
    }

    /**
     * 取子（`Padding::child()` / `Center::child()` 的历史签名）。
     *
     * ★ 这里**不用** `final`：`Offstage` 之类需要"取子前先做判断"的控件要覆写，
     *   但 `expandChildren()` / `createElement()` 必须 `final` —— 那两个才是管子。
     */
    public function child(): Widget
    {
        return $this->kid;
    }

    #[\Override]
    final public function expandChildren(): array
    {
        return [$this->kid];
    }

    #[\Override]
    final public function createElement(): Element
    {
        return new ProxyElement($this);
    }
}
