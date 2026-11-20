<?php
declare(strict_types=1);

namespace App\Showcase\Routes\Snackbar;

use Drift\Route\RouteEvent;

/**
 * 页面事件：一个不可变值，只带"用户想干什么"。
 *
 * 不带闭包、不带服务 —— 因此可以构造出来直接喂给 `update()` 做单测，
 * 也可以在探针里回放。
 *
 * @api
 */
final readonly class SnackbarEvent implements RouteEvent
{
    public function __construct(
        public SnackbarIntent $intent,
    ) {
    }
}
