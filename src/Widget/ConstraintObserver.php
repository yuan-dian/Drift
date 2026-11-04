<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 「布局之后还要再问一句」的节点（`LayoutBuilder` / 按真实尺寸分窗口的容器）。
 *
 * 契约：`Frame` 在 layout 之后问一遍 `pollConstraintDrift()`。实现方比较
 * 「本帧 layout 记下的真实约束」与「上一次 build 用的约束」，**变了就自己
 * `markDirty()`** 并返回漂移描述 ⇒ `Frame` 在同帧内再跑一轮 `settle + layout`，
 * 收敛完才 paint（没有"中间态上屏"）。
 *
 * ★ 为什么由 State 实现、由 `Pipeline` 持表：`Frame` 不该 `instanceof` 具体控件
 *   （结构判据在 `ARCH-REVIEW` I2），也不该为了几个节点遍历整棵树。
 * ★ **注册与注销成对**：`initState()` 注册、`dispose()` 注销。漏注销 = 卸载的节点
 *   还会被问，它一标脏就是重建一棵不在树里的子树。
 *
 * @api
 */
interface ConstraintObserver
{
    /**
     * 布局后自检。
     *
     * @return null|string `null` = 约束没变（不做事）；非空 = 漂移描述，格式建议
     *   `谁: 旧 → 新`（`Frame` 把它记进诊断，震荡时能指到"谁在漂"）。
     *   **返回非空必须已经 `markDirty()`** —— 返回值只是"要不要再来一轮"的信号。
     */
    public function pollConstraintDrift(): ?string;
}
