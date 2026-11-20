<?php
declare(strict_types=1);

namespace Drift\Route;

use Drift\Widget\IconName;

/**
 * 一条路由的不可变描述符 —— 反射的产物，运行期的输入。
 *
 * 由 `RouteCatalog::discover()` 在启动期造出，之后只被读：
 * 导航项、建页、自动化身份全部从这一张表派生，不再各自维护一份。
 *
 * ★ `viewClass` / `servicesClass` 存 FQCN 字符串：`new $cls(...)` 在 AOT 产物里
 *   已验证可用（`probes/class_string`），所以组合根不需要逐页写工厂闭包。
 *
 * @api
 */
final readonly class RouteDescriptor
{
    /**
     * @param class-string<RouteView> $viewClass
     * @param class-string $servicesClass 服务投影类，构造器收一个"应用上下文"
     */
    public function __construct(
        public string $id,
        public string $title,
        public IconName $icon,
        public string $viewClass,
        public string $servicesClass,
    ) {
    }
}
