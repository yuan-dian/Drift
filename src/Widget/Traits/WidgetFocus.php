<?php
declare(strict_types=1);

namespace Drift\Widget\Traits;

use Drift\Input\CursorShape;
use Drift\Input\FocusNode;

/**
 * `Widget` 的**焦点面**：光标形状、Tab 序、自动聚焦、可聚焦性。
 *
 * ★ 这些是**声明**不是状态 —— "现在谁聚焦"住在 `Drift\App\FocusManager`；
 *   Widget 挂载后封存（`seal()`），把可变焦点塞进来会立刻撞纪律。
 * ★ `focusNode()` 的 `FocusNode` 是**不可变值对象**（`withOrder()` / `skipped()` /
 *   `withAutoFocus()` 都返回新实例），与 `Modifier` 同一套"换新不就地改"。
 * ★ 这里**没有**"声明独占某个键"的入口：键归谁由路由顺序回答
 *   （焦点链先、加速键后），见 `App\InputRouter::dispatch()`。
 *
 * @internal 与 `Widget` 同生共死
 */
trait WidgetFocus
{
    /**
     * 显式指定本控件在**同一父容器内**的 Tab 序（§A.3.4）。
     *
     * 不声明 ⇒ 用文档序（深度优先）。★ 同一容器内**混用有/无** `focusOrder`
     * ⇒ 隐式相对顺序有歧义（§9.1 裁决为 G2 级错误）。
     * **诚实边界**：该 lint 守卫**尚未落地**（`php tools/lint.php --list` 里没有它）
     * —— 今天 `FocusManager` 的行为是**有 `focusOrder` 的排在无的前面**、同侧按文档序。
     *
     * @api
     */
    public function focusOrder(int $order): static
    {
        $this->mutate();
        $this->focus = ($this->focus ?? FocusNode::make())->withOrder($order);

        return $this;
    }

    /**
     * 整棵子树**不进** Tab 序（§A.3.4）。
     *
     * 典型：`AppBar` 的工具按钮组。**必须显式** —— 不做"智能跳过"。
     *
     * ★★ **只影响 Tab 序，不影响指针激活**（2026-10-29 修）：跳过 Tab 序的按钮
     *   仍然点得动。早先实现里"跳过 Tab"与"不可聚焦"共用一张表 ⇒ 加了
     *   `skipFocus()` 的按钮**完全点不动**，而画面上看不出任何异常（`TreeView`
     *   的行箭头就是这么静默失效的）。要"点不动"请用 `disabled()`，那是另一件事。
     *
     * ★★★ **复合控件别把整块都跳过**（2026-11-05，§57）：`skipFocus()` 只挡 Tab 序，
     *   挡不住方向键 ⇒ 把 31 个日期格全跳过后，方向键明明够得着，**Tab 却一步也
     *   进不来**（`CalendarView` / `TimeView` 就这么哑了一周多）。正确做法是
     *   **roving tabindex**：只让"活动格"（值所在那格）不跳，其余照跳。
     *   配套规则：**占站 ⇔ 真按得动** —— 没有回调的格子连入口都不该占。
     *
     * @api
     */
    public function skipFocus(): static
    {
        $this->mutate();
        $this->focus = ($this->focus ?? FocusNode::make())->skipped();

        return $this;
    }

    /**
     * 焦点**空着**时自动落到本控件（进页面 / 弹出模态之后就能直接打字）。
     *
     * 只认 Tab 序里**第一个**声明者，且只在没人聚焦时接管 —— 与 Flutter 的
     * `autofocus` 同语义。`skipFocus()` / `disabled()` 的子树不进 Tab 序，不参与。
     *
     * @api
     */
    public function autoFocus(): static
    {
        $this->mutate();
        $this->focus = ($this->focus ?? FocusNode::make())->withAutoFocus();

        return $this;
    }

    /**
     * 声明本控件（及其**未覆写**的后代）的鼠标指针形状（§9.1 / §A.3.2）。
     *
     * ```php
     * Row::make()->cursor(CursorShape::PointingHand)->children([...])   // 可点
     * Text::make('可选中文本')->cursor(CursorShape::IBeam)              // 文本
     * Column::make()->cursor(CursorShape::SizeAll)                     // 拖动把手
     * ```
     *
     * ★ 这是**声明**，不是状态：谁声明、命中链取**最深**者 —— 解析住在
     *   `FocusManager`（与焦点链同源，§9.1「一次命中，三方消费」）。
     * ★ **不给控件开自绘光标的口子**（§9.1 裁决）：自绘光标要自己画、自己跟帧，
     *   为一个装饰把渲染路径拉进输入态不划算。
     *
     * @api
     */
    public function cursor(CursorShape $shape): static
    {
        $this->mutate();
        $this->cursor = $shape;

        return $this;
    }

    /** @internal 焦点链读它（`null` = 本控件没声明任何焦点属性） */
    public function focusNode(): ?FocusNode
    {
        return $this->focus;
    }

    /** @internal 造盒时读它（`null` = 未声明 ⇒ 交给命中链上更近的祖先） */
    public function cursorShape(): ?CursorShape
    {
        return $this->cursor;
    }

    /**
     * 输入控件在**构造器里**声明"我能收焦点"（§9.1）。
     *
     * 默认 false：`Text` / `Column` 这类不该进 Tab 序。声明过的控件才可能被
     * `FocusManager` 收进焦点链。
     *
     * @internal
     */
    final protected function markFocusable(): void
    {
        $this->focusable = true;
    }

    /** @internal */
    final public function isFocusable(): bool
    {
        return $this->focusable;
    }
}
