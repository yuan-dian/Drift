<?php
declare(strict_types=1);

namespace App\Showcase;

use App\Showcase\Routes\ShowcaseRoutes;
use Drift\Routing\NavEntry;
use Drift\Routing\PageRegistry;
use Drift\Routing\RouteTable;

/**
 * 示例台导航：把路由表投影成导航项 + 页面工厂。
 *
 * ★ 清单只有一处：`#[Route]` 注解（经 `ShowcaseRoutes::catalog()` 反射成描述符）。
 *   本类不再维护 PAGES 数组 —— 那张表曾与注解写同样的 id/icon/title，
 *   改一处忘另一处就静默漂移。现在 `id/icon/title/pageClass` 全部来自描述符。
 * ★ 建页：零依赖页走 `PageRegistry` 的默认无参构造；需要领域依赖的页由组合根在
 *   `$pageFactories` 里按类名覆写。工厂表住组合根，本类只转发。
 */
final readonly class ShowcaseNav
{
    private PageRegistry $registry;

    /**
     * @param array<class-string, \Closure(): \Drift\Widget\Widget> $pageFactories
     */
    public function __construct(array $pageFactories)
    {
        $entries = [];
        foreach (ShowcaseRoutes::catalog()->all() as $descriptor) {
            $entries[] = new NavEntry(
                $descriptor->id,
                $descriptor->icon,
                $descriptor->title,
                $descriptor->pageClass,
            );
        }
        $this->registry = new PageRegistry($entries, $pageFactories);
    }

    /** @return list<NavEntry> */
    public function entries(): array
    {
        return $this->registry->entries();
    }

    public function table(): RouteTable
    {
        return $this->registry->table();
    }

    public function defaultId(): string
    {
        return $this->registry->defaultId();
    }
}
