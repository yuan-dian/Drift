<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\App\SyncTaskHost;
use Drift\App\TaskHost;
use Drift\Dev\Smoke\State\FakeStore;
use Drift\Widget\Column;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：最小的"应用 + 后台端口"组合（§17.4.2 的五条判据）。
 *
 * ★ 三条判据只有**应用层**测得出（端口层测不到）：
 *   ① `submit()` 之后当帧不回调、下一帧回调（帧驱动）；
 *   ② `onDone` 里改 Store ⇒ **同帧**重建（drain 在 settle 之前）；
 *   ③ `taskHost()` 返回 null ⇒ `frame()` 不炸、行为不变。
 *
 * ★ `withTasks` 必须在 `attach()` **之前**设：端口在 attach 里建一次并缓存
 *   （见 `Application::$tasks` 的注释）。
 */
final class TaskApplication extends Application
{
    public FakeStore $store;

    /** false ⇒ `taskHost()` 返回 null（验"不装端口"的路径） */
    public bool $withTasks = true;

    public function __construct()
    {
        parent::__construct();
        $this->store = new FakeStore();
    }

    protected function taskHost(): ?TaskHost
    {
        return $this->withTasks ? new SyncTaskHost() : null;
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
