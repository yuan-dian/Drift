<?php
declare(strict_types=1);

namespace App\Showcase\Pages\Base;

use Drift\Widget\Page;

/**
 * 示例台的单类页面：Page 本身就是 State，字段 / `build()` / 事件处理器同处一类。
 *
 * ★ 基类**不收任何依赖**。只画界面的页就是无参构造，`PageRegistry` 的默认路径
 *   直接 `new`。需要领域依赖的页在自己的构造器里声明具体类型（`ButtonsStore` /
 *   `Dialog` / `\Closure(int): Future` …），由组合根的页面工厂表按类覆写建页方式。
 *   ⇒ 依赖各有类型，写反是构造期 Fatal；也不再有一束同型闭包需要靠反射测试钉顺序。
 *
 * 除 `SnackbarPage` 外所有页面都继承本类；那个例外把状态机委托给 `ReducerState`，
 * 必须经 `createState()` 挂树，故保留薄壳（见该类注释）。
 */
abstract class ShowcasePage extends Page
{
}
