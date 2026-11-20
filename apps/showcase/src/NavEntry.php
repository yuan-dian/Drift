<?php
declare(strict_types=1);

namespace App\Showcase;

use Drift\Widget\IconName;
use Drift\Widget\Widget;

/**
 * 侧栏一项：稳定 id + 图标 + 名称 + 页工厂。
 *
 * ★ `id` 是页的**身份**（写进 RouteTable、被探针按名字定位），与数组位置无关。
 *   新增 / 重排条目不再改变既有页的身份，也不再需要"必须追加在末尾"的纪律。
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
