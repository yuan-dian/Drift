<?php
declare(strict_types=1);

namespace Drift\Geometry;

/**
 * 一根轴。
 *
 * ★ 为什么不是一个 `bool $vertical`：双轴容器（`RenderTable`）要能"按轴查表"——
 *   `thumbRect(Axis)` / `maxScroll(Axis)` 这类签名让"两根条"变成一次循环，
 *   而布尔写法要求每个调用点自己写三元式（`ScrollInputRouter` 原先就是三处）。
 *   ⇒ 轴成为**值**，路由层不需要知道"谁是主轴"。
 *
 * ★ **不带方法**：本仓其余 20 个 enum 全是纯 case，AOT 侧对 enum 方法没有先例。
 *   轴上的几何换算（取宽/取高/取点分量）住在 `ScrollBarGeometry` —— 那里本来就是
 *   "三个必须互相一致的量"的唯一计算处，多一个换算反而更该放在一起。
 *
 * ★ 与 `Rect` / `Size` 同住几何面：它是坐标系的属性，不是某个控件的属性。
 *
 * @api 只出现在内部接口签名里，应用不直接使用
 */
enum Axis
{
    case Horizontal;

    case Vertical;
}
