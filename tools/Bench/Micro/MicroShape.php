<?php
declare(strict_types=1);

namespace Drift\Dev\Bench\Micro;

/**
 * 虚分派的最小载体。
 *
 * ★ 为什么需要一个接口而不是直接用具体类：`RenderBox::layout()` 里两次调用
 *   （`performLayout()` / `visitChildren()`）都是**经接口/基类**出去的，
 *   而 tpc 在这条路上的生成码与"同一类里的 final 方法调用"不同。
 *   不把这两种分开量，就没法说清 1.73× 里有多少是"虚调用"付的。
 *
 * ★ 两个实现是**故意的**：单实现的调用点会被内联缓存压成单态，
 *   而真实树有几十种 `RenderBox` 子类，调用点必然是多态的。
 *
 * @internal 量具专用
 */
interface MicroShape
{
    public function scaled(int $x): int;
}
