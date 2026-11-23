<?php
declare(strict_types=1);

namespace Drift\Layout;

/**
 * `Stack` 给子的约束策略。
 *
 * ★ **`Loose` 是默认值**：`Stack` 最常见的用法是"主内容 + 一个角标"，两者都该按
 *   自己大小。默认 `Expand` 会把主内容强制拉满 —— 那是最不常见的用法当默认值。
 *
 * @api
 */
enum StackFit
{
    /** 两轴都松（`0..max`）—— 子按自己大小；本盒尺寸 = `constrain(max(子))` */
    case Loose;

    /**
     * 有界轴拉满**父给的空间**（不是 `max(子)`）、无界轴退化为 `Loose`。
     *
     * ★ **尺寸取自 `constraints.biggest` 而不是 `max(子)`**：后者是循环依赖
     *   （本盒尺寸来自子、子尺寸又来自本盒），而解它的"两遍 layout"正是
     *   `04` §5.3 与 `02` §5.2 否决 `Wrap` 的理由。取父给的尺寸 ⇒ 单遍，
     *   而且"背景铺满"的语义才成立（父给 500 而子只要 100 时要铺 500）。
     * ★ **无界轴退化**而不是报错：`INF` 的紧约束会让子炸，而"某轴无界"在
     *   `Row`/`Column` 里是常态（弹性子那一轴）。
     */
    case Expand;

    /** 原样透传父约束；本盒尺寸 = `constrain(max(子))` */
    case PassThrough;
}
