<?php
declare(strict_types=1);

namespace Drift\Dev\Bench\Micro;

/**
 * 可变的小对象：对应 `RenderBox` 那一类（有可变字段、有 final 方法、实现接口）。
 *
 * ★ `$w` 单独声明成 `float` 而不是放构造器提升：要复刻"先 `new` 再逐个写字段"
 *   这条路径 —— 布局里 `$this->size = $boxSize` 就是这个形状。
 *
 * @internal 量具专用
 */
class MicroNode implements MicroShape
{
    public float $w = 0.0;

    public function __construct(public int $a, public int $b)
    {
    }

    /** 同类内的 final 方法调用（对照 L09 的虚分派）。 */
    final public function plus(int $x): int
    {
        return $this->a + $this->b + $x;
    }

    public function scaled(int $x): int
    {
        return $this->a * $x + $this->b;
    }

    /** 模拟 `performLayout()`：读字段、算 float、**分配**一个值对象返回。 */
    final public function measure(MicroSize $c, float $k): MicroSize
    {
        $w = $c->width * $k + (float) $this->a;
        $h = $c->height * $k + (float) $this->b;

        return new MicroSize($w, $h);
    }
}
