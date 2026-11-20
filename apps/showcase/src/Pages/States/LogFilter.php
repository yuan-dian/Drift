<?php
declare(strict_types=1);

namespace App\Showcase\Pages\States;

/** 日志页过滤档（页内封闭域）。 */
enum LogFilter
{
    case All;
    case WarnUp;
    case Layout;
}
