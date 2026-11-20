<?php
declare(strict_types=1);

namespace Drift\Reducer;

use Drift\Widget\BuildContext;
use Drift\Widget\Widget;

/**
 * 页面的 reducer 契约：init 造初值、view 纯描述、update 纯函数、apply 执行副作用。
 *
 * ★ 本层与导航（Drift\Route）零耦合：这里不读注解、不查路由表。页面是否用它
 *   是页面自己的选择（A13/A14）——只有"事件→纯更新→effect"形状的页才值得。
 * ★ 热路径全部是具体类静态直调，零反射、零服务查找：
 *   `ReducerState::build()` 每帧只调 `view()`；`dispatch()` 调 `update()`。
 * ★ `view()` 的 `$dispatch` 是固定 arity 的 `\Closure(ReducerEvent): void`：事件闭包
 *   捕获它，回调里派发一个值；不做 `BuildContext.of()` 式隐式查找。
 *
 * @api
 */
interface ReducerView
{
    /** 页面的初始不可变状态。切页时由 `ReducerState` 调一次。 */
    public function init(): ReducerModel;

    /**
     * 纯描述：只读 model，返回 widget 树。
     *
     * @param \Closure(ReducerEvent): void $dispatch
     */
    public function view(BuildContext $ctx, ReducerModel $model, \Closure $dispatch): Widget;

    /** 纯函数：按具体事件类型算出新 model + effect，不碰 UI、不调服务。 */
    public function update(ReducerModel $model, ReducerEvent $event): ReducerUpdate;

    /**
     * 执行一条 effect —— 全页唯一的副作用出口。
     *
     * ★ 与 `update()` 的分工：`update()` 必须是纯函数（可单测、可重放），这里才碰
     *   服务。副作用收在一个已知落点，不再散进 `view()` 的按钮闭包里。
     * ★ `$dispatch` 留给"回调型副作用"：`->action('撤销', fn () => $dispatch(new Undo()))`
     *   在用户点击后重新走 `update()`，而不是就地改 model —— 否则状态就有两条写路径。
     *
     * @param \Closure(ReducerEvent): void $dispatch
     */
    public function apply(ReducerEffect $effect, \Closure $dispatch): void;
}
