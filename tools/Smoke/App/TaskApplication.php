<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Dev\Smoke\State\FakeStore;
use Drift\Widget\Column;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：最小的"应用 + 后台端口"组合（§17.4.2 的五条判据）。
 *
 * ★ 三条判据只有**应用层**测得出（端口层测不到）：
 *   ① `submit()` 之后当帧不回调、下一帧回调（帧驱动）；
 *   ② `onDone` 里改 Store ⇒ **同帧**重建（drain 在 settle 之前）；
 *   ③ 作用域里端口为 `null` ⇒ `frame()` 不炸、行为不变。
 *
 * ★ `withoutTasks()` 必须在 `attach()` **之前**调：端口在 attach 里被 `PumpHost`
 *   取走并缓存（M2 起端口归 `AppScope` —— 一个进程一个池）。
 */
final class TaskApplication extends Application
{
    public FakeStore $store;

    public function __construct()
    {
        parent::__construct();
        $this->store = new FakeStore();
    }

    /**
     * 关掉后台端口（验"不装端口"的路径）。
     *
     * ★ M2 之前这靠覆写 `taskHost()` 返回 null；现在那个方法是 `final`
     *   （覆写 = 加载期致命错误）⇒ 改成在**作用域**上说"本应用不用后台任务"。
     */
    public function withoutTasks(): void
    {
        $this->scope()->setTaskHost(null);
    }

    public function buildRoot(): Widget
    {
        return Column::make()->children([new TaskWatcher($this->store)]);
    }

    /** 夹具用：拿 watcher 的 State（读 `builds` / `seen`） */
    public function watcherState(): ?TaskWatcherState
    {
        $state = $this->root?->childAt(0)?->state();

        return $state instanceof TaskWatcherState ? $state : null;
    }
}
