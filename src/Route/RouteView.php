<?php
declare(strict_types=1);

namespace Drift\Route;

use Drift\Widget\BuildContext;
use Drift\Widget\Widget;

/**
 * 页面的四段式契约：init 造初值、view 纯描述、update 纯函数、apply 执行副作用。
 *
 * ★ 热路径全部是具体类静态直调，零反射、零服务查找：
 *   `RouteState::build()` 每帧只调 `view()`；`dispatch()` 调 `update()`。
 * ★ 反射只在 `RouteCatalog::discover()` 启动期出现一次，产出不可变描述符后不再查。
 * ★ `view()` 的 `$dispatch` 是固定 arity 的 `\Closure(RouteEvent): void`：事件闭包
 *   捕获它，回调里派发一个值；不做 `BuildContext.of()` 式隐式查找。
 *
 * @api
 */
interface RouteView
{
    /** 页面的初始不可变状态。切页时由 `RouteState` 调一次。 */
    public function init(): RouteModel;

    /**
     * 纯描述：只读 model，返回 widget 树。
     *
     * @param \Closure(RouteEvent): void $dispatch
     */
    public function view(BuildContext $ctx, RouteModel $model, \Closure $dispatch): Widget;

    /** 纯函数：按具体事件类型算出新 model + effect，不碰 UI、不调服务。 */
    public function update(RouteModel $model, RouteEvent $event): RouteUpdate;

    /**
     * 执行一条 effect —— 全页唯一的副作用出口。
     *
     * ★ 与 `update()` 的分工：`update()` 必须是纯函数（可单测、可重放），这里才碰
     *   服务。副作用收在一个已知落点，不再散进 `view()` 的按钮闭包里。
     * ★ `$dispatch` 留给"回调型副作用"：`->action('撤销', fn () => $dispatch(new Undo()))`
     *   在用户点击后重新走 `update()`，而不是就地改 model —— 否则状态就有两条写路径。
     *
     * @param \Closure(RouteEvent): void $dispatch
     */
    public function apply(RouteEffect $effect, \Closure $dispatch): void;
}
