<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * `ThemeScope` 的 Element：**只改环境，不改子层列表**（§2446）。
 *
 * 与 `OffstageElement` 正相反的那个极端 —— `OffstageElement` 是恒返回空数组
 * （子层刻意不走常规调和），这里是**照常走 `updateChildren()`**：主题换了
 * 子控件描述没换，diff 应该复用同一批 Element，否则"切主题"会顺带重建整棵树，
 * State 全丢。
 *
 * @api platform
 */
final class ThemeScopeElement extends StatelessElement
{
    #[\Override]
    protected function expand(): array
    {
        /** @var ThemeScope $widget */
        $widget = $this->widget;

        return [$widget->child()];
    }
}
