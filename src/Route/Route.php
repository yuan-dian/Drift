<?php
declare(strict_types=1);

namespace Drift\Route;

use Drift\Widget\IconName;

/**
 * 导航声明：id / 标题 / 图标，标在**页面 Widget 类**上。
 *
 * ★ 定位收窄：这里只声明"这一页在导航里是谁"，不碰页面内部怎么管状态。
 *   初版把 `services` 投影也塞进注解、要求每个页面实现四段式 `ReducerView`，
 *   结果一个纯展示页也要造 Model/Event/Effect —— 那不是降低心智负担，
 *   只是把负担换了个地方。现在四段式降级为复杂页的**可选实现**：
 *   需要纯函数 `update()` 可测的页自己用 `ReducerView` + `ReducerState`，
 *   其余页照旧就地 `setState`。路由层与状态管理层互不依赖。
 *
 * ★ `icon` 直接收 `IconName` 枚举（不是字符串）：枚举 case 作注解参数在
 *   解释器 / dev 壳 / 全 AOT 三态实测可用（`probes/enum_attribute`），
 *   `IconName` 本身就是"拼错即编译期报错"的守卫 —— 退成字符串就丢了它。
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class Route
{
    public function __construct(
        public string $id,
        public string $title,
        public IconName $icon,
    ) {
    }
}
