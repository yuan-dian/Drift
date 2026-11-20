<?php
declare(strict_types=1);

namespace App\Showcase\Pages\States;

/** 反馈页三态（页内封闭域 —— 不设 default，match 穷尽）。 */
enum StubState
{
    case Loading;
    case Error;
    case Empty;
}
