<?php
declare(strict_types=1);

namespace Drift\Layout;

/**
 * `Stack` 的对齐（arch-v2 `04` §4.2）—— **九宫格，容器级**。
 *
 * ```
 * TopLeft     TopCenter     TopRight
 * CenterLeft  Center        CenterRight
 * BottomLeft  BottomCenter  BottomRight
 * ```
 *
 * ★ **两轴都要表达**，所以不能复用 `FlexAlign`（那是单轴）也不能复用
 *   `RenderBox::ALIGN_*`（那是 `alignSelf` 的单轴哨兵）。
 *
 * ★ **不支持"每个子单独对齐"**：`alignSelf` 的语义是"覆盖父容器的**交叉轴**对齐"，
 *   而 `Stack` 两轴都自由、没有交叉轴概念 ⇒ 那个字段对 `Stack` 的子**静默无效**。
 *   要每子对齐就嵌套一层 `Stack`。这是已知的能力缺口（`04` §4.2 记账）。
 *
 * @api
 */
enum StackAlign
{
    case TopLeft;
    case TopCenter;
    case TopRight;
    case CenterLeft;
    case Center;
    case CenterRight;
    case BottomLeft;
    case BottomCenter;
    case BottomRight;
}
