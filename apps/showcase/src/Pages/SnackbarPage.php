<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use App\Showcase\Pages\Base\StatefulShowcasePage;
use App\Showcase\Routes\ShowcaseRoutes;
use Drift\Route\RouteState;
use Drift\Widget\State;

/**
 * Snackbar 页的**兼容壳**：只负责把路由表接到框架的挂载点。
 *
 * 为什么还要这个类：`NavEntry` 与 golden 量具都按类名持有页面
 * （`new $pageClass($context)`），所以页面类名与构造器形态必须保住。
 * 一页真正的逻辑全在 {@see SnackbarRoute}，这里不再有状态、不再有 build。
 *
 * ★ 迁移期的形态：旧 33 页照原样 `extends StatefulShowcasePage` + 手写 State；
 *   新页走 `RouteState`。等全量迁完，这个壳连同 `StatefulShowcasePage` 一起消失
 *   ——`NavEntry` 改为从 `RouteCatalog` 取描述符建页。
 *
 * @api
 */
final class SnackbarPage extends StatefulShowcasePage
{
    public function createState(): State
    {
        return new RouteState(
            ShowcaseRoutes::catalog()->create('snackbar', $this->caps),
        );
    }
}
