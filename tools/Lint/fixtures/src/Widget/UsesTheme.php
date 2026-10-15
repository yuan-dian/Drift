<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Theme\ThemeData;

/**
 * 夹具：例外只有 `BuildContext` 一个文件 —— 这里的引用**照样要响**。
 *
 * 守的是"层级放行会让例外扩散"这条边界：若哪天把 `Widget` 整层放开到 `Theme`，
 * 这条就不再响了，量具会立刻发现。
 */
final class UsesTheme
{
    public function __construct(private ThemeData $theme)
    {
    }
}
