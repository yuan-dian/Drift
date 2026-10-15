<?php
declare(strict_types=1);

namespace Drift\Routing;

use Drift\Widget\Widget;

/**
 * `PageHost` 的占位：路由未命中时**什么都不显示**（§12.1 第 2 条）。
 *
 * 不是空壳占位类（A15）—— 它是一个有名字的、确定的行为："开放域未命中 ⇒ 空"。
 * 兜底页（404 / "正在加载"）由应用自己 route 一个名字，框架不替产品做决定。
 *
 * @internal
 */
final class NoPage extends Widget
{
}
