<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * `keyboard()` 回调的三种回答（`FocusManager::dispatchKey()` 的第 ① 环）。
 *
 * ★ 三态而不是 `bool`（2026-11-05，§58）：`false` 把两件不同的事压成了一个字 ——
 *   "这个键不归我，但别让焦点跑掉"（`Select` 面板关着）与"这个键交回框架的默认
 *   导航"（树把 ↑↓ 让给几何导航）。两者混在一起 ⇒ **任何想认领一两个键的控件都得
 *   先接受"整组按键都归我"**，于是树至今没有 ←→ 展开折叠、日历没有 PageUp/PageDown
 *   换月，而 `keyboard()` 的注释只能写"做不到"。
 * ★ `bool` 照样收（`true`→`Handled`、`false`→`Owned`）：存量声明一条都不用改。
 *
 * @api
 */
enum KeyResult
{
    /** 吃掉了：派发到此为止，激活与加速键都不再跑 */
    case Handled;

    /** 没吃掉，但键归我：下游各环照跑（激活 / `EditableText`），**框架的默认导航不跑** */
    case Owned;

    /** 不要这个键：交回框架的默认语义（方向键几何导航 / 翻页键滚容器） */
    case Declined;
}
