<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Widget\ConstraintObserver;

/**
 * 假观察者：每次都回一句"漂移"，用来测 `Frame` 的有界收敛与震荡诊断。
 *
 * ★ 它**不**标脏（真实实现会）—— 这里要验的是循环边界与诊断，不是重建本身。
 */
final class StuckConstraintObserver implements ConstraintObserver
{
    public int $polls = 0;

    /** @param \Closure(int): ?string $next 入参是第几次问（从 0 起） */
    public function __construct(private readonly \Closure $next)
    {
    }

    public function pollConstraintDrift(): ?string
    {
        $at = $this->polls;
        ++$this->polls;

        return ($this->next)($at);
    }
}
