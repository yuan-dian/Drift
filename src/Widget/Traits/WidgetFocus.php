<?php
declare(strict_types=1);

namespace Drift\Widget\Traits;

use Drift\Input\CursorShape;
use Drift\Input\FocusNode;
use Drift\Input\KeyChord;

/**
 * `Widget` 的**焦点面**：光标形状、Tab 序、按键独占、可聚焦性。
 *
 * ★ 这些是**声明**不是状态 —— "现在谁聚焦"住在 `Drift\App\FocusManager`；
 *   Widget 挂载后封存（`seal()`），把可变焦点塞进来会立刻撞纪律。
 * ★ `focusNode()` 的 `FocusNode` 是**不可变值对象**（`withOrder()` / `skipped()`
 *   / `claiming()` 都返回新实例），与 `Modifier` 同一套"换新不就地改"。
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
     * @api
     */
    public function skipFocus(): static
    {
        $this->mutate();
        $this->focus = ($this->focus ?? FocusNode::make())->skipped();

        return $this;
    }

    /**
     * 声明本控件**独占**的键集：命中时不让加速键抢（§9.1 让位规则 ②）。
     *
     * `TextField` 的编辑命令（`Ctrl+A/C/V/X/Z`）就是这么声明的。
     *
     * @param list<KeyChord> $chords
     *
     * @api
     */
    public function claimKeys(array $chords): static
    {
        $this->mutate();
        $this->focus = ($this->focus ?? FocusNode::make())->claiming($chords);

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
