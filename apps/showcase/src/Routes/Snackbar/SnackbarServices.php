<?php
declare(strict_types=1);

namespace App\Showcase\Routes\Snackbar;

use App\Showcase\ShowcaseCapabilities;
use Drift\Widget\Snackbar;

/**
 * 这一页实际够得到的能力：**只有一条** `notify`。
 *
 * 原来页面拿到的是 6 个位置参数、其中 5 个同型闭包的 `ShowcaseCapabilities`，
 * 页面一个用不上其余的，却要保证顺序不写错（顺序由反射测试钉住源码文本）。
 * 投影之后，页面能调什么由这个类的方法表决定：多一个都拿不到，少一个编译期就报。
 *
 * ★ 仍然只收一个应用上下文参数：`RouteCatalog::create()` 按
 *   `new $servicesClass($context)` 造它（AOT 已验证），组合根不必逐页写工厂。
 *
 * @api
 */
final readonly class SnackbarServices
{
    public function __construct(
        private ShowcaseCapabilities $caps,
    ) {
    }

    public function notify(Snackbar $snackbar): void
    {
        $this->caps->notify($snackbar);
    }
}
