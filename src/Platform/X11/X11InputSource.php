<?php
declare(strict_types=1);

namespace Drift\Platform\X11;

use Drift\Input\InputEvent;
use Drift\Platform\InputSource;

/**
 * X11 输入队列：只做入队/出队，归一化留在桥和 Platform::translate() 两侧。
 * @internal 平台实现
 */
final class X11InputSource implements InputSource
{
    /** @var list<InputEvent> */
    private array $queue = [];

    public function poll(): ?InputEvent
    {
        $event = array_shift($this->queue);

        return $event instanceof InputEvent ? $event : null;
    }

    public function push(InputEvent $event): void
    {
        $this->queue[] = $event;
    }
}
