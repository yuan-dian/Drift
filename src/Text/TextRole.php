<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 文本的语义角色（§15.2）。
 *
 * ★ **`Unset` 是一个显式 case，不是哨兵数字** —— 于是 `0` 是合法取值（`->body()`
 *   解析出来就是它），"未设置"是它的**语义**。§15.2 哨兵定则：enum 语义的
 *   "未设置"必须在 enum 里有个名字，否则 A2（拿合法值当哨兵）又回来了。
 *
 * ★ 放在 `Drift\Text` 而不是 `Drift\Widget`：角色 → `TextStyle` 的解析在
 *   `ThemeData` 上（§15.7 纪律 3："合并只发生一次"），而 §4.3 只允许
 *   `Theme → Text`，不允许 `Theme → Widget`。
 */
enum TextRole: int
{
    /** 未设置 —— 由主题决定默认文本角色 */
    case Unset = 0;
    case Title = 1;
    case Heading = 2;
    case Body = 3;
    case Caption = 4;
    case Error = 5;
}
