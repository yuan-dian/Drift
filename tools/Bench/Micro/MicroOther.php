<?php
declare(strict_types=1);

namespace Drift\Dev\Bench\Micro;

/**
 * 第二个实现，只为让 `MicroShape` 上的调用点**变成多态**。
 *
 * ★ 单实现时内联缓存完全命中，测出来的是"单态虚调用"——那在真实树里不存在；
 *   多态（这里两个）才是布局里的常态。实现体故意与 `MicroNode` 不同，
 *   避免"被合并成同一个函数"这种意外优化。
 *
 * @internal 量具专用
 */
final class MicroOther implements MicroShape
{
    public function __construct(private readonly int $k)
    {
    }

    public function scaled(int $x): int
    {
        return $x * $x - $this->k;
    }
}
