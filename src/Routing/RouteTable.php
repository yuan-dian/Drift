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
 * ★ 值是**闭包工厂**而不是页面实例：路由表是数据，不该绑死在一棵具体树上。
 *   工厂在首次进入时调一次，之后由 `PageHostState` 按页名缓存实例 —— 切页不再
 *   重造，`State` 才保得住。
 *
 * @api
 */
final class RouteTable
{
    /** @var array<string, callable(): Widget> */
    private array $routes = [];

    /** @var array<string, callable(array<string, mixed>): Widget> */
    private array $argRoutes = [];

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

    /** 注册一条带参数的路由。参数从 `build()` 传进来，冷启动时由导航栈存档复原。 */
    public function routeArgs(string $name, callable $factory): self
    {
        if (isset($this->argRoutes[$name]) || isset($this->routes[$name])) {
            Errors::report(sprintf('路由 %s 已注册，重复注册会静默覆盖', $name));
        }

        $this->argRoutes[$name] = $factory;

        return $this;
    }

    /** 造一页。未命中 ⇒ `null`（开放域 ⇒ 由调用方的 `default` 分支处理） */
    public function build(string $name, array $args = []): ?Widget
    {
        if (isset($this->argRoutes[$name])) {
            return ($this->argRoutes[$name])($args);
        }

        return isset($this->routes[$name]) ? ($this->routes[$name])() : null;
    }

    public function has(string $name): bool
    {
        return isset($this->routes[$name]) || isset($this->argRoutes[$name]);
    }

    /** 已注册的页面名 —— devtools 列举 + "死路由"检查的静态输入 */
    public function names(): array
    {
        return array_keys(array_merge($this->routes, $this->argRoutes));
    }
}
