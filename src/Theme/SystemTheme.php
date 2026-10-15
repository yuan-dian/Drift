<?php
declare(strict_types=1);

namespace Drift\Theme;

/**
 * 系统主题（深色模式，§9.2）。
 *
 * 平台经 `screen()` 通道上报，与 `DpiChanged` 同路；应用把它存进 store，
 * 改了 ⇒ notify ⇒ **全树重建**（不是 relayout）—— 因为它改的是 `build()` 的输入。
 *
 * @api
 */
enum SystemTheme
{
    case Light;
    case Dark;
}
