<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Platform\Clipboard;

/**
 * `TextEditingBridge` 向宿主要的两样东西（S1 第 4 步）。
 *
 * ★ 为什么是接口而不是把 `Application` 整个传进去：片只需要**焦点链**与
 *   **剪贴板**两个口子。传整 application 的话，片迟早会开始摸帧循环 / 浮层 /
 *   根 Element —— 那时"最小片"就不再最小，拆它就白拆了。
 * ★ 为什么不注入值而是注入 getter：焦点是运行期才建的（`attach()` 时
 *   `new FocusManager()`），剪贴板由组合根挂进来 —— 两者都是"就绪事件
 *   不确定的依赖"，getter 让片永远读当前值，不用管注入时序。
 *
 * @internal S1 的装配接口，不是应用契约
 */
interface TextEditingHost
{
    /** 焦点链（`null` = 还没 attach；§9.1 的焦点是渲染态，全局只能有一份） */
    public function focus(): ?FocusManager;

    /** 平台剪贴板（`null` = 平台没递进来；§5.1 框架不持平台） */
    public function clipboard(): ?Clipboard;
}
