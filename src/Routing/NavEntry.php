<?php
declare(strict_types=1);

namespace Drift\Routing;

use Drift\Widget\IconName;
use Drift\Widget\Page;
use Drift\Widget\Widget;

/**
 * 导航项：稳定 id + 图标 + 名称 + 页面类型。
 *
 * 导航项本身不持有闭包：零依赖页走默认无参构造，需要依赖的页由 `PageRegistry`
 * 的工厂表按类名覆写。
 *
 * @api
 */
final readonly class NavEntry
{
    /** @param class-string<Widget|Page> $pageClass */
    public function __construct(
        public string $id,
        public IconName $icon,
        public string $label,
        public string $pageClass,
    ) {
    }

    /**
     * 默认建页：页面无参构造（`Page` 基类不带依赖）。
     * 需要依赖的页面由 `PageRegistry` 的工厂表按类名覆写 —— 这条路径只负责"零依赖页"。
     *
     * ★ 只 `new`，不 `host()`：装配收口在 `PageRegistry` 一处，工厂表也就不必记得调它。
     *
     * @return Widget|Page
     */
    public function makePage(): Widget|Page
    {
        return new $this->pageClass();
    }
}
