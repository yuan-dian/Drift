<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;

use App\Showcase\Routes\Snackbar\SnackbarServices;
use App\Showcase\Routes\SnackbarReducer;
use Drift\Reducer\ReducerState;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;

/**
 * Snackbar 页 —— 四段式 `ReducerView` 的**可选样板**。
 *
 * 导航身份来自本类的 `#[Route]` 注解（与其余 33 页同一条路径）；页面内部的
 * 状态管理则选择 `ReducerView` + `ReducerState`：逻辑在 {@see SnackbarReducer}，
 * 这里只把 `notify` 这一条能力投影成 `SnackbarServices` 并交给泛型壳。
 *
 * ★ 为什么只有这一页用四段式：它的「删除 → 撤销 → 计数」是纯状态转移，
 *   `update()` 能在无窗口的 PHP 单测里直接断言。其余页多是就地 UI 态
 *   （悬停计数、光标、滚动位置），套四段式只会多造 Model/Event/Effect。
 * ★ 服务投影在这里手写一行，不再由导航注解携带 —— 状态管理是页面内部选择，
 *   不进导航描述符。
 *
 * ★ 这是 34 页里唯一保留「壳 + State」两件套的页：状态机委托给框架的
 *   `ReducerState`，而它必须经 `createState()` 挂进树。其余页已合并成单类
 *   `ShowcasePage`；这里的例外是** reducer 可单测性**的代价，不是历史残留。
 *
 * @api
 */
#[Route(id: 'snackbar', title: '通知 Snackbar', icon: IconName::Bell)]
final class SnackbarPage extends StatefulWidget
{
    /** @param \Closure(\Drift\Widget\Snackbar): void $notify 只投影这一条能力 */
    public function __construct(private readonly \Closure $notify)
    {
        parent::__construct();
    }

    public function createState(): State
    {
        return new ReducerState(
            new SnackbarReducer(new SnackbarServices($this->notify)),
        );
    }
}
