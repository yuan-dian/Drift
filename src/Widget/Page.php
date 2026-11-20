<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 页面基类：一个页面就是一个 `State` —— 字段、`build()`、事件处理器同处一类。
 *
 * ★ 为什么不是 `StatefulWidget`：页面挂载一次、长期保活，不需要"描述不可变 +
 *   每帧换新描述"这套为重建准备的二分。做成 Widget 就得再配一个 State，
 *   每页白付一层转发壳。
 * ★ `host()` 是**唯一**装配入口：`State` 不在 Widget 树里，得由 `PageShell` 桥
 *   一下。把这条知识收在基类上，路由工厂 / 金标量具 / 预览就不必各自 new 宿主。
 * ★ `#[Route]` 标的是**页面身份**，不是"可挂载性"——可挂载性由类型表达。
 *
 * @api
 */
abstract class Page extends State
{
    /**
     * 取本页的宿主 Widget。
     *
     * `final`：装配只留一条路，避免某页覆写后变成不同的挂载语义。
     *
     * ★ 每次调用都返回**新**宿主：一个宿主只能挂一处，一个 Page 也只能挂一处
     *   （重复挂载在 `State::attach()` 处 Fatal）。要在多次重建间复用就缓存
     *   `host()` 的结果 —— `PageHostState` 正是这么做的：缓存住宿主，重建只是
     *   `update()`，Element 不换、State 不换。
     * ★ 但缓存**跨不过卸载**：`State::initState()` 只跑一次，卸载后把同一个实例
     *   挂回去不会重新订阅，`State::attach()` 会当场报 Fatal。卸载之后要新实例。
     */
    final public function host(): Widget
    {
        return new PageShell($this);
    }
}
