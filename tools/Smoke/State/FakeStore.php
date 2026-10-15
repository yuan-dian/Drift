<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\State;

use Drift\State\Subject;

/**
 * @internal 冒烟夹具：一个最小的 `Subject` 子类（= 业务层的 `Store` 形态）。
 *
 * `silentTouch()` 是刻意留的：**改了字段但不调 `notifyChanged()`** ⇒ 订阅者不该
 * 被叫到。这条断的是"重建是由通知驱动的，不是由字段变动驱动的" —— 少了它，
 * "通知真的联上了"这件事测不出来（不通知也不重建时，两种情况长得一样）。
 */
final class FakeStore extends Subject
{
    private int $status = 0;

    /** @var list<int> 每次 notify 时 `status()` 的读数，用来验"通知带的是最新值" */
    private array $seenAtNotify = [];

    public function status(): int
    {
        return $this->status;
    }

    public function seenAtNotify(): array
    {
        return $this->seenAtNotify;
    }

    public function setStatus(int $value): void
    {
        $this->status = $value;
        $this->seenAtNotify[] = $this->status;
        $this->notifyChanged();
    }

    public function silentTouch(int $value): void
    {
        $this->status = $value; // 不 notify
    }
}
