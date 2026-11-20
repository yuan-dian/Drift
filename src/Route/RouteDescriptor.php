<?php
declare(strict_types=1);

namespace Drift\Route;

use Drift\Widget\IconName;

/**
 * 一条路由的不可变描述符 —— 注解的产物，导航的运行期输入。
 *
 * 由 `RouteCatalog::discover()` 在启动期造出，之后只读：侧栏导航项、
 * 页面工厂、自动化身份全部从这一张表派生，不再各自维护一份。
 *
 * ★ 只含导航三要素 + 页面类：`viewClass`/`servicesClass` 已移除。
 *   状态管理是页面内部选择，不进导航描述符 —— 否则纯展示页也被迫声明服务。
 *
 * @api
 */
final readonly class RouteDescriptor
{
    /** @param class-string $pageClass 带 #[Route] 的页面 Widget 类 */
    public function __construct(
        public string $id,
        public string $title,
        public IconName $icon,
        public string $pageClass,
    ) {
    }
}
