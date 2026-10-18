<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\Dev\Smoke\State\FakeStore;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;

/**
 * @internal 冒烟夹具：订阅 `FakeStore` 并**记录重建次数与读到的值**。
 *
 * ★ 存在理由（§17.4.2 的第三条判据）：`onDone` 里改 Store ⇒ 同帧重建。
 *   只有一支"重建了几次 + 读到几"的读数才能分辨：
 *     · drain 在 settle **之前** ⇒ `builds` 当帧 +1、`seen` 是新值；
 *     · drain 在 settle **之后** ⇒ 当帧不变，下一帧才 +1。
 *   少了 `seen`，"重建了但读的是旧值"这种半同步会被漏掉。
 */
final class TaskWatcher extends StatefulWidget
{
    public function __construct(private readonly FakeStore $store)
    {
        parent::__construct();
    }

    public function store(): FakeStore
    {
        return $this->store;
    }

    public function createState(): State
    {
        return new TaskWatcherState();
    }
}
