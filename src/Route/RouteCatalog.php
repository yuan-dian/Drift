<?php
declare(strict_types=1);

namespace Drift\Route;

use Drift\Widget\Page;
use Drift\Widget\Widget;

/**
 * 启动期把页面类上的 `#[Route]` 注解读成一张不可变导航表。
 *
 * 这是整套设计里**唯一出现反射的地方**，且只在进程启动时跑一次：
 * 之后侧栏导航项、页面工厂、自动化身份全部查 `RouteDescriptor`，热路径零反射。
 * 依据：`probes/annotation_reflection` 三态（解释器 / dev 壳 / 全 AOT）验证
 * `getAttributes()` 与 `ReflectionAttribute::newInstance()` 在 AOT 产物里可用。
 *
 * ★ 为什么类清单仍是显式数组：AOT 产物里没有"扫目录找类"这回事。
 *   清单是构建期可生成的产物，不是业务数据 —— 一页一行，且 `id/title/icon`
 *   只在页面类的注解里写一次，不再有 `ShowcaseNav::PAGES` 那张平行清单。
 * ★ 本类只产出**元数据**（descriptor）；怎么把 descriptor 变成 NavEntry /
 *   RouteTable 是应用层的接线（`ShowcaseNav`），框架不替它做产品决定。
 * ★ 为什么 id 冲突在启动期就抛：等到用户点导航才发现两个页撞 id，是线上问题；
 *   启动期抛是构建/自检问题。
 *
 * @api
 */
final class RouteCatalog
{
    /** @var array<string, RouteDescriptor> */
    private array $byId;

    /** @var list<string> */
    private array $ids;

    /** @param array<string, RouteDescriptor> $byId */
    private function __construct(array $byId)
    {
        $this->byId = $byId;
        $this->ids = \array_keys($byId);
    }

    /**
     * 读注解、校验、建表。
     *
     * @param list<class-string> $pageClasses 带 `#[Route]` 的页面类（通常是 `Page`；`Widget` 仅留给走 reducer 壳的例外页）
     *
     * @throws \InvalidArgumentException 缺注解 / id 重复 / 类既不是 Page 也不是 Widget
     */
    public static function discover(array $pageClasses): self
    {
        $byId = [];

        foreach ($pageClasses as $class) {
            $ref = new \ReflectionClass($class);

            $attributes = $ref->getAttributes(Route::class);
            if ($attributes === []) {
                throw new \InvalidArgumentException(sprintf(
                    '"%s" 缺 #[Route] 注解：导航项与自动化身份都从它派生，没有它这一页进不了侧栏',
                    $class,
                ));
            }

            if (!$ref->isSubclassOf(Widget::class) && !$ref->isSubclassOf(Page::class)) {
                throw new \InvalidArgumentException(sprintf(
                    '"%s" 带 #[Route] 但既不是 %s 也不是 %s 子类：路由声明的是页面身份',
                    $class,
                    Widget::class,
                    Page::class,
                ));
            }

            /** @var Route $meta */
            $meta = $attributes[0]->newInstance();

            if (isset($byId[$meta->id])) {
                throw new \InvalidArgumentException(sprintf(
                    '路由 id 冲突："%s" 同时被 "%s" 与 "%s" 声明',
                    $meta->id,
                    $byId[$meta->id]->pageClass,
                    $class,
                ));
            }

            $byId[$meta->id] = new RouteDescriptor(
                $meta->id,
                $meta->title,
                $meta->icon,
                $class,
            );
        }

        return new self($byId);
    }

    /**
     * 装好的路由表由**应用**持有（示例台里是 `ShowcaseRoutes::catalog()`）。
     *
     * ★ 框架不提供进程级单例：全局可变注册表会变成隐式服务定位器，
     *   也会让"这个页面到底从哪张表来"无法在测试里替换。应用拿一个
     *   `static $catalog ??= RouteCatalog::discover(...)` 就够，且更好测。
     */

    public function has(string $id): bool
    {
        return isset($this->byId[$id]);
    }

    public function get(string $id): RouteDescriptor
    {
        return $this->byId[$id] ?? throw new \InvalidArgumentException(
            sprintf('没有 id 为 "%s" 的路由；已注册：%s', $id, \implode(', ', $this->ids)),
        );
    }

    /** @return array<string, RouteDescriptor> */
    public function all(): array
    {
        return $this->byId;
    }

    /** @return list<string> */
    public function ids(): array
    {
        return $this->ids;
    }
}
