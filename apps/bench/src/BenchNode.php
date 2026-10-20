<?php
declare(strict_types=1);

namespace Drift\Bench;

/**
 * M1 的夹具节点。
 *
 * 形状刻意贴近 v7 的 `Node`：几个**标量**字段 + 一个 `children` 数组 + 一个
 * `layout()`。这样测出来的差异来自"取到节点的代价"，不是"字段多寡"。
 *
 * ★ 属性一律**类型化**（`int` / `float` / `array`）：在 tpc 里它们编译成
 *   `Z_LVAL_P(o + offset)` 一类的直接偏移访问，与 C struct 同阶 ——
 *   这正是"普通 class 不慢"的前提（见 `MEMORY` 的 PHP 硬坑条）。
 */
final class BenchNode
{
    public int $kind = 0;

    public float $w = 0.0;

    public float $h = 0.0;

    public float $x = 0.0;

    public float $y = 0.0;

    public array $children = [];

    public function layout(float $c): void
    {
        $this->w = $c;
        $this->h = $c * 0.5;
    }

    public function extent(): float
    {
        return $this->w + $this->h;
    }
}

/**
 * M1 的 C 组夹具：**父持类型化引用**（固定 8 度）。
 *
 * ★ 为什么是 8 个具名字段而不是数组：PHP 的 `array` 元素在 tpc 里是 `php::Var`，
 *   存不了"类型化引用" —— 想表达"零装箱"就只能用**具名类型化属性**。
 *   ⇒ 这一组的**代码形状与 A/B 不同**，所以它只作"理想下界"的信息量，**不作判据**。
 *
 * ★★ **字段是 `BenchNode` 而不是 `?BenchNode`**（第一版用了 nullable + `?->`，
 *   实测 C 组 122 ns/node —— 比 A/B 都慢，因为它量的是 8 次 null 检查）。
 *   理想下界不该带 null 检查，所以改成构造时必填的非空字段。
 */
final class Fanout8
{
    public BenchNode $c0;

    public BenchNode $c1;

    public BenchNode $c2;

    public BenchNode $c3;

    public BenchNode $c4;

    public BenchNode $c5;

    public BenchNode $c6;

    public BenchNode $c7;

    public function __construct(
        BenchNode $c0,
        BenchNode $c1,
        BenchNode $c2,
        BenchNode $c3,
        BenchNode $c4,
        BenchNode $c5,
        BenchNode $c6,
        BenchNode $c7,
    ) {
        $this->c0 = $c0;
        $this->c1 = $c1;
        $this->c2 = $c2;
        $this->c3 = $c3;
        $this->c4 = $c4;
        $this->c5 = $c5;
        $this->c6 = $c6;
        $this->c7 = $c7;
    }

    /**
     * 8 次**静态**调用（`$this->c0` 的静态类型是 `BenchNode`，无 null 检查）。
     */
    public function layoutAll(float $c): void
    {
        $this->c0->layout($c);
        $this->c1->layout($c);
        $this->c2->layout($c);
        $this->c3->layout($c);
        $this->c4->layout($c);
        $this->c5->layout($c);
        $this->c6->layout($c);
        $this->c7->layout($c);
    }
}
