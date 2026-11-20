<?php
declare(strict_types=1);

namespace Drift\Route;

/**
 * 启动期把 `#[Route]` 注解读成一张不可变路由表。
 *
 * 这是整套设计里**唯一出现反射的地方**，且只在进程启动时跑一次：
 * 之后导航、建页、自动化身份全部查 `RouteDescriptor`，热路径零反射。
 * 依据：`probes/annotation_reflection` 三态（解释器 / dev 壳 / 全 AOT）验证
 * `getAttributes()` 与 `ReflectionAttribute::newInstance()` 在 AOT 产物里可用。
 *
 * ★ 为什么类清单仍是显式数组：AOT 产物里没有"扫目录找类"这回事。
 *   清单是构建期可生成的产物，不是业务数据 —— 一页一行，取代原来的
 *   "PAGES 行 + Page 壳 + State 类 + automationId" 四处对账。
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
     * @param list<class-string> $viewClasses 实现 `RouteView` 且带 `#[Route]` 的类
     *
     * @throws \InvalidArgumentException 缺注解 / id 重复 / 类没实现契约
     */
    public static function discover(array $viewClasses): self
    {
        $byId = [];

        foreach ($viewClasses as $class) {
            $ref = new \ReflectionClass($class);

            $attributes = $ref->getAttributes(Route::class);
            if ($attributes === []) {
                throw new \InvalidArgumentException(sprintf(
                    '"%s" 缺 #[Route] 注解：导航项与自动化身份都从它派生，没有它这一页进不了侧栏',
                    $class,
                ));
            }

            if (!$ref->implementsInterface(RouteView::class)) {
                throw new \InvalidArgumentException(sprintf(
                    '"%s" 带 #[Route] 但没实现 %s：注解声明的是"可运行的路由"，不是装饰',
                    $class,
                    RouteView::class,
                ));
            }

            /** @var Route $meta */
            $meta = $attributes[0]->newInstance();

            if (isset($byId[$meta->id])) {
                throw new \InvalidArgumentException(sprintf(
                    '路由 id 冲突："%s" 同时被 "%s" 与 "%s" 声明',
                    $meta->id,
                    $byId[$meta->id]->viewClass,
                    $class,
                ));
            }

            $byId[$meta->id] = new RouteDescriptor(
                $meta->id,
                $meta->title,
                $meta->icon,
                $class,
                $meta->services,
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

    /**
     * 按描述符造一个页面实例。
     *
     * 两步动态构造，都在**切页时**发生（非热路径）：
     * `new $servicesClass($context)` 造服务投影，`new $viewClass($services)` 造页面。
     * 两者都在 `probes/class_string` 的 AOT 产物里实测可用；因此组合根不必
     * 逐页写工厂闭包，一页一行清单就够。
     *
     * 约定：服务投影类的构造器收**一个**参数 —— 应用上下文（示例台里是能力束）。
     * 这条约定由 `#[Route(services: ...)]` 显式声明，不靠反射猜类型。
     *
     * @param object $context 应用侧的能力束 / 服务根
     */
    public function create(string $id, object $context): RouteView
    {
        $descriptor = $this->get($id);
        $viewClass = $descriptor->viewClass;
        $servicesClass = $descriptor->servicesClass;

        return new $viewClass(new $servicesClass($context));
    }
}
