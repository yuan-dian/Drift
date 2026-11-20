<?php
declare(strict_types=1);

namespace App\Showcase;

use Drift\Widget\IconName;
use Drift\Widget\Widget;

/**
 * 侧栏一项：稳定 id + 图标 + 名称 + 页工厂。
 *
 * `id` 是页身份（RouteTable 键 + 探针 AutomationId），与位置无关；值对象把
 * 四字段顺序钉在构造签名上，写成数组调序不会报错。
 */
final readonly class NavEntry
{
    /**
     * @param string $id 跨运行稳定的页名（RouteTable 的键、探针的定位名）
     * @param \Closure(): Widget $page 页工厂（**只调一次**，实例由 PageHostState 缓存）
     */
    public function __construct(
        public string $id,
        public IconName $icon,
        public string $label,
        public \Closure $page,
    ) {
    }
}
