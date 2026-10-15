<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 字形倾斜（封闭域）。
 *
 * @api
 */
enum FontSlant: string
{
    case Normal = 'normal';
    case Italic = 'italic';
    case Oblique = 'oblique';
}
