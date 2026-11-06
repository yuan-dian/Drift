<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * **能把焦点交给别人**的宿主（一个应用一份，由 `Application` 实现）。
 *
 * ★ 存在的理由：`keyboard()` 回调拿得到"声明者自己的 `Element`"，但拿不到
 *   "把焦点交给另一个 `Element`"的能力 —— 树的 `←` 跳父行要的就是这一句。
 *   回调签名**不能**加第三个参数传一个"焦点请求对象"（AOT 校验实参个数，
 *   解释器全绿、真机 Fatal），所以能力只能长在 `Element` 上（`requestFocus()`）。
 *
 * ★ 与成熟框架同形：DOM `el.focus()` / WPF `Keyboard.Focus(el)` /
 *   Qt `QWidget::setFocus()` 都是"从某个元素发起，作用于另一个元素"；
 *   本仓没有独立的 `FocusNode` 对象（焦点按 `Element` 收集）⇒ `Element` 就是 node。
 *   Flutter 的 `FocusScope.of(context)` 那种向上查找**不借鉴**（`BuildContext`
 *   的 docblock 第 ③ 条写死无 `of()`）。
 *
 * ★ 只放一个方法：唯一的消费者只需要"交给谁"，不需要读当前焦点、不需要遍历链。
 *   第二个消费者出现再加，每加一个都要有判据。
 *
 * @api
 */
interface FocusHost
{
    /**
     * 请求把焦点移到 `$target`（**请求**语义：可能被拒）。
     *
     * 拒绝条件是"目标此刻不能被聚焦"：不在可聚焦表里（已卸载 / 禁用 /
     * 不可聚焦的控件），或者压根没挂载。拒绝**静默**——这是刻意降级，
     * 控件在虚拟化列表里要移焦到一个还没被挂载的行时，不能因此崩。
     */
    public function requestFocus(Element $target): void;
}
