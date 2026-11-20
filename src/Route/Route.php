<?php
declare(strict_types=1);

namespace Drift\Route;

use Drift\Widget\IconName;

/**
 * 页面声明：id / 标题 / 图标 / 服务投影类型，集中在一处。
 *
 * ★ 注解即清单：`RouteCatalog` 在启动期读它产出导航与建页描述符，
 *   页面作者不再维护"PAGES 行 + Page 壳 + State 类 + AutomationId"四处对账。
 * ★ `icon` 直接收 `IconName` 枚举（不是字符串）：枚举 case 作注解参数在
 *   解释器 / dev 壳 / 全 AOT 三态实测可用（`probes/enum_attribute`），
 *   而 `IconName` 本身就是"拼错即编译期报错"的守卫 —— 退成字符串就把这层守卫丢了。
 *   `services` 仍是 FQCN 字符串：注解里没有"类名字面量"之外的表达方式。
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Route
{
    /** @param class-string $services 服务投影类，构造器收一个"应用上下文" */
    public function __construct(
        public string $id,
        public string $title,
        public IconName $icon,
        public string $services,
    ) {
    }
}
