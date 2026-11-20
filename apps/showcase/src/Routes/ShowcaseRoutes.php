<?php
declare(strict_types=1);

namespace App\Showcase\Routes;

use Drift\Route\RouteCatalog;

/**
 * 示例台的路由清单 —— **一页一行**。
 *
 * 这是唯一需要手工维护的"页面清单"：`#[Route]` 注解仍是 id / 标题 / 图标 /
 * 服务投影的唯一事实源，这里只是告诉 AOT 产物"哪些类要参与反射扫描"
 * （AOT 里没有扫目录找类这回事，清单本身是构建期可生成的产物）。
 *
 * ★ 对比迁移前：一页要改 4 处（`ShowcaseNav` 的 PAGES 行、Page 壳、State 类、
 *   自动化身份）。现在少一处是一行，其余由注解派生。
 * ★ `static` 缓存保证反射只跑一次：首次用到时才扫，之后读表。
 *
 * @api
 */
final class ShowcaseRoutes
{
    /** @var list<class-string> */
    private const array CLASSES = [
        SnackbarRoute::class,
    ];

    private static ?RouteCatalog $catalog = null;

    public static function catalog(): RouteCatalog
    {
        return self::$catalog ??= RouteCatalog::discover(self::CLASSES);
    }

    /** 测试用：丢掉缓存，下次重新发现。 */
    public static function reset(): void
    {
        self::$catalog = null;
    }

    /** @return list<class-string> */
    public static function classes(): array
    {
        return self::CLASSES;
    }
}
