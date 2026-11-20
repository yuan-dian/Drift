<?php
declare(strict_types=1);

namespace Drift\Routing;

use Drift\Widget\IconName;
use Drift\Widget\Page;
use Drift\Widget\Widget;

/**
 * 导航项：稳定 id + 图标 + 名称 + 页面类型。
 *
 * 页面类型统一由 `PageRegistry` 注入应用上下文构造，导航项本身不持有闭包。
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

    /** 页面构造器统一收一个应用上下文对象（示例台里就是能力束）。 */
    public function makePage(object $context): Widget
    {
        $page = new $this->pageClass($context);

        // 单类页面经 host() 进树；走 reducer 壳的例外页本身已是 Widget，原样返回。
        return $page instanceof Page ? $page->host() : $page;
    }
}
