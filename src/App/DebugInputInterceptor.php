<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Input\InputEvent;

/**
 * 调试桥的**可选输入拦截能力**（A58）。
 *
 * ## 为什么是单独的接口，而不是给 `DebugTicker` 加方法
 *
 * `DebugTicker` 是 A57 定下的 `@api` 扩展点，实现者只需回答"主循环要什么"。
 * 拾取不是每个桥都要有的东西 —— 把它塞进那个接口，所有只做 attach 的桥都被迫
 * 实现一个空方法，而"可选"这件事在类型上就消失了。分开之后 `EventLoop` 用
 * `instanceof` 问一次，没装能力的路径与加这个接口前一字节不差。
 *
 * ## 为什么拦在 `EventLoop`，不进 `AppShell::dispatchInput()`
 *
 * 那个漏斗负责"输入怎么变成应用状态"，还负责在入口记脚本。dev 拾取要让输入
 * **根本不进应用**：吞掉 down、放过 up 会在脚本里留下一条没有配对的 up，也在
 * InputRouter 里留下一个按下目标已经消失的结算。拦在派发之前，两件事都不会发生。
 *
 * @api dev-only 扩展点：由 `dev-entry` 安装的实现消费，只由 `EventLoop` 询问
 */
interface DebugInputInterceptor
{
    /**
     * `true` = 这条输入已被调试面消费：不进应用、不进脚本、不进证据。
     *
     * 实现必须非阻塞，并且只对自己真正要的输入回 `true` —— 常回 `true` 等于
     * 让窗口假死（键盘、关闭请求除外的所有输入都到不了应用）。
     */
    public function interceptInput(InputEvent $event): bool;
}
