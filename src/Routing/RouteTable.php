<?php
declare(strict_types=1);

namespace Drift\Routing;

use Drift\Errors;
use Drift\Widget\Widget;

/**
 * 路由表：**数据**，不是控件（§12.1 第 7 条）。
 *
 * 名字 → 页面工厂。与"在 `Root` 里直接 `match`"是**同一件事的两个规模档**：
 * 小应用 `match`（零分配、零新概念）；需要 devtools 列举 / 单测 / 动态装配时
 * 才升级成表。二者产出都是"名字 → 页面 Widget"。
 *
 * ★ 为什么值必须是**闭包工厂**而不是页面 Widget 实例：页面要能**按需构造**
 *   （进这个分支才 new），且 `PageHost` 换页时要能重新造一份。存 Widget 实例
 *   会让"路由表"变成"某一棵具体树的快照"，与它作为数据的定位矛盾。
 *
 * @api
 */
final class RouteTable
{
    /** @var array<string, callable(): Widget> */
    private array $routes = [];

    private function __construct()
    {
    }

    /**
     * @api
     */
    public static function make(): self
    {
        return new self();
    }

    /**
     * 注册一条路由。
     *
     * @param string $name 页面名（**开放域**：加页 / 插件化都会加新名字）
     * @param callable(): Widget $factory 页面工厂
     *
     * @api
     */
    public function route(string $name, callable $factory): self
    {
        if (isset($this->routes[$name])) {
            // 与 CommandRouter 同名重复同一条理由：后注册静默覆盖 ⇒ 上线后
            // "导航到某页却没反应"且查不出为什么（G2）。
            Errors::report(sprintf('路由 %s 已注册，重复注册会静默覆盖', $name));
        }

        $this->routes[$name] = $factory;

        return $this;
    }

    /** 造一页。未命中 ⇒ `null`（开放域 ⇒ 由调用方的 `default` 分支处理） */
    public function build(string $name): ?Widget
    {
        return isset($this->routes[$name]) ? ($this->routes[$name])() : null;
    }

    public function has(string $name): bool
    {
        return isset($this->routes[$name]);
    }

    /** 已注册的页面名 —— devtools 列举 + "死路由"检查的静态输入 */
    public function names(): array
    {
        return array_keys($this->routes);
    }
}
