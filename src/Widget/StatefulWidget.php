<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;

/**
 * 有**纯 UI 态**的控件（§11.5 / §12.3）。
 *
 * ★ 用它的判据：这个值**必须跨 build 活**、且**只影响这一棵子树**（悬停项、展开
 *   状态、编辑中的草稿）。业务数据、跨页共享、被虚拟化列表的滚动位置 ⇒ 全归
 *   Store（§11.5 / §12.3），不放这里。
 *
 * @api
 */
abstract class StatefulWidget extends Widget
{
    /**
     * 造 State。
     *
     * ★ **挂载时只调一次**。更新时 Element 复用同一个 State（否则 position 一换
     *   全丢了）—— 要"状态跟着新描述走"是 `State::didUpdateWidget()` 的事。
     */
    abstract public function createState(): State;

    /**
     * **直接从 State 产渲染盒**（可选；`02` §6.2，2026-10-23 落地）。
     *
     * 返回 `null`（默认）⇒ 走 `build()` 那条路：本控件是"**组合**"，子层由 State
     * 描述，盒由子层里某个 `RenderBoxWidget` 提供。
     * 返回盒 ⇒ 本控件是"**叶子**"：不再有子节点，`State::build()` **永不被调用**。
     *
     * ★ **什么时候用**：控件有态、有盒、**且没有子**（`Spinner`）。"有态"与
     *   "有盒"因此不再强制绑定到两个类上 —— 以前必须再写一个 `*Box` 传递相位
     *   （`Spinner → SpinnerState → SpinnerBox → RenderSpinner` 三段），现在
     *   `Spinner → SpinnerState → RenderSpinner` 两段。
     *
     * ★ **什么时候不用**：控件**有子**（`TextField` 的 label + 输入框、
     *   `ScrollView` 的内容）。叶子路径的语义是"不产子"，套上去会静默丢掉子层
     *   —— 那类控件继续走 `build()`，保留 `TextFieldBox` / `ScrollViewBox`。
     *
     * ★ **修饰（`mods`）照样生效**：`StatefulElement::syncBox()` 走的是与
     *   `Element::syncBox()` 同一条 `setMods()`。没有这条的话
     *   `Spinner->padding(8)` 会撞 `02` §11.7 的"修饰无处可去"守卫。
     *
     * ★ **`State` 的构造顺序**：`mount()` 里 `createState()` 先于本方法
     *   （`onMount()` 的 `callInit()` 之前）⇒ 这里读到的 `$state` 已可用。
     *
     * @api
     */
    public function createRenderObjectFromState(BuildContext $context, State $state): ?RenderBox
    {
        return null;
    }
}
