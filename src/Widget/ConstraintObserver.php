<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 布局之后还要再问一句的节点（`LayoutBuilder` 等）。
 *
 * Frame 在 layout 后问一遍；实现比较本帧真实约束与上次 build 的约束，
 * 变了就自己 `markDirty()` 并返回描述，Frame 同帧再跑一轮 settle + layout，收敛后 paint。
 * 注册与注销必须成对，漏注销会让卸载节点继续标脏、重建不在树里的子树。
 *
 * @api
 */
interface ConstraintObserver
{
    /**
     * 布局后自检。
     *
     * @return null|string null = 没变；非空 = 漂移描述（建议 `谁: 旧 → 新`）。
     *   返回非空前必须已经 `markDirty()`，返回值只是"要不要再来一轮"的信号。
     */
    public function pollConstraintDrift(): ?string;
}
