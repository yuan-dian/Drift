<?php
declare(strict_types=1);

namespace Drift\Dev\Bench\Micro;

/**
 * 不可变值对象：对应 `Size` / `BoxConstraints`（本仓 32 个 `final readonly class` 之一）。
 *
 * ★ 布局里每一层的 `constrain()` / `deflate()` / `tighten()` 都**返回新实例**，
 *   于是一次全量重排的分配次数与节点数同阶。这是 1.73× 的头号嫌疑：
 *   分配是本仓热路径上最密的行为，而它与"装箱"完全是两件事。
 *
 * @internal 量具专用
 */
final readonly class MicroSize
{
    public function __construct(public float $width, public float $height)
    {
    }
}
