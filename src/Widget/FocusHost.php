<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 能把焦点交给别的 Element 的宿主（一个应用一份，由 `Application` 实现）。
 *
 * `keyboard()` 回调拿得到声明者自己的 Element，但拿不到"把焦点交给另一个 Element"
 * 的能力；回调签名不能加第三个参数（AOT 校验实参个数，解释器全绿、真机 Fatal），
 * 所以能力只能长在 Element 上。本仓没有独立 FocusNode，Element 就是 node。
 *
 * @api
 */
interface FocusHost
{
    /**
     * 请求把焦点移到 `$target`（可能被拒）。
     *
     * 目标已卸载 / 禁用 / 不可聚焦 / 未挂载时静默拒绝：虚拟化列表移焦到未挂载行不能崩。
     */
    public function requestFocus(Element $target): void;
}
