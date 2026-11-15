<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Support;

use Drift\Input\InputEvent;
use Drift\Platform\InputSource;

/** `InputSource` 的最小第二实现：一条内存队列，不接任何 OS 消息。 */
final class HeadlessInputSource implements InputSource
{
    /** @var list<InputEvent> */
    private array $queue = [];

    public function poll(): ?InputEvent
    {
        return array_shift($this->queue) ?? null;
    }

    public function push(InputEvent $event): void
    {
        $this->queue[] = $event;
    }
}
