<?php
declare(strict_types=1);

namespace App\Showcase;

use Drift\Widget\IconName;
use Drift\Widget\Widget;

/**
 * 侧栏一项：图标 + 名称 + 页工厂。
 *
 * ★ 为什么不是 `[名称, 工厂]` 三元数组：加一个字段就要改所有 `[0]` / `[1]` 的下标，
 *   而且写错序（图标和名字调换）在 `array` 里**不报错**，只会渲染出怪东西。
 *   值对象把顺序钉在构造签名上。
 *
 * ★ `readonly`：切换页面只读这些字段，不该有人改它（`select-purity` 也只认
 *   readonly 对象作为 `select` 的返回）。
 */
final readonly class NavEntry
{
    /**
     * @param \Closure(): Widget $page 页工厂（每次切换新建，页状态各自独立）
     */
    public function __construct(
        public IconName $icon,
        public string $label,
        public \Closure $page,
    ) {
    }
}
