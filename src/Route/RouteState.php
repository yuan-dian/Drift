<?php
declare(strict_types=1);

namespace Drift\Route;

use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\Widget;

/**
 * 通用页面壳：把 `RouteView` 接进框架的 State 生命周期。
 *
 * 一页只有这一份壳，作者不再手写 `XxxPage` + `XxxPageState` 两个类、也不用
 * 自己把能力束透传进 State。壳负责三件事，且只负责这三件：
 *
 *   ① 挂载时问 `init()` 要初值；
 *   ② 每帧把 `view()` 的描述交出去（热路径只有这一次具体类直调）；
 *   ③ 事件 → `update()` → 换 model + 跑 effect。
 *
 * ★ 为什么 model 用"挂载后惰性初始化"而不是构造参数：`State` 由框架在挂载时
 *   造（`createState()`），构造期还读不到 `BuildContext`；惰性一次比到处传更省。
 * ★ 为什么 model 换了才标脏：`update()` 可能只返回 effect（例如重复点击同一
 *   按钮），此时重建整棵树是纯浪费。恒等比较是廉价的，语义也直白：
 *   "状态对象没变＝画面不该变"。
 *
 * @api
 */
final class RouteState extends State
{
    private ?RouteModel $model = null;

    /** @var \Closure(RouteEvent): void */
    private \Closure $dispatch;

    public function __construct(private readonly RouteView $view)
    {
        $this->dispatch = function (RouteEvent $event): void {
            $this->applyEvent($event);
        };
    }

    public function initState(): void
    {
        $this->model = $this->view->init();
    }

    public function build(BuildContext $ctx): Widget
    {
        // 兜底：某些容器可能先 build 后 initState，惰性补一次（`init()` 应是纯的）。
        $model = $this->model ??= $this->view->init();

        return $this->view->view($ctx, $model, $this->dispatch);
    }

    /**
     * 事件主路：先算（纯），再落（脏标记 + 执行副作用）。
     *
     * 顺序不能反：effect 里可能有"派发下一个事件"的回调，先落 model 才能保证
     * 后续事件读到的状态是本轮之后的。
     */
    private function applyEvent(RouteEvent $event): void
    {
        $update = $this->view->update($this->model ??= $this->view->init(), $event);

        if ($update->model !== $this->model) {
            $this->setState(function () use ($update): void {
                $this->model = $update->model;
            });
        }

        foreach ($update->effects as $effect) {
            $this->view->apply($effect, $this->dispatch);
        }
    }

    /** 当前 model（只读视图，测试与探针用）。 */
    public function model(): ?RouteModel
    {
        return $this->model;
    }
}
