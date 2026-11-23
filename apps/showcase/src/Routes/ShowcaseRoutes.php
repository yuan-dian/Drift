<?php
declare(strict_types=1);

namespace App\Showcase\Routes;

use Drift\Route\RouteCatalog;

/**
 * 示例台的启动期反射入口：只读生成清单，`id/title/icon` 仍只在页面注解里写一次。
 *
 * AOT 不扫目录，参与反射的类由 `RouteManifest` 提供编译期常量数组；
 * 新增页写 `#[Route]` 后跑 `php tools/gen-route-manifest.php`，`verify` 的 sceneset
 * step 会盯住清单漂移。页面工厂不在这里，依赖页由组合根按类名覆写。
 *
 * @api
 */
final class ShowcaseRoutes
{
    private static ?RouteCatalog $catalog = null;

    public static function catalog(): RouteCatalog
    {
        return self::$catalog ??= RouteCatalog::discover(RouteManifest::CLASSES);
    }

    /** 测试用：丢掉缓存，下次重新发现。 */
    public static function reset(): void
    {
        self::$catalog = null;
    }

    /** @return list<class-string> */
    public static function classes(): array
    {
        return RouteManifest::CLASSES;
    }
}
