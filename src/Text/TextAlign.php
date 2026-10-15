<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 行内对齐（封闭域）。
 *
 * ★ 用 `Start` / `End` 而不是 `Left` / `Right` —— 对齐方向是**书写方向**的函数，
 *   不是屏幕方向的函数（§8.2 坑 7 的 bidi 要求）。
 *
 * @api
 */
enum TextAlign: string
{
    case Start = 'start';
    case Center = 'center';
    case End = 'end';
    case Justify = 'justify';
}
