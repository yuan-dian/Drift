<?php
declare(strict_types=1);

namespace App\Users\Store;

/**
 * 加载三态（§A.2.8 / §11.5）。
 *
 * ★ 封闭域 ⇒ 消费它的 `match` **禁止 default**（§12.1 第 2 条）—— 加状态时
 *   编译期就会指出来漏了哪一页。
 */
enum LoadStatus: int
{
    case Idle    = 0;
    case Loading = 1;
    case Ready   = 2;
    case Error   = 3;
}
