<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\Size;
use Drift\Rendering\Canvas;

/**
 * 自绘画家：`Canvas` op 覆盖不到的控件（仪表盘 / 走势图 / 示波器）经这里出去。
 * `CustomPaint` 持一个画家，渲染盒 `paint()` 时把已 translate 到本盒原点的画布和
 * 本盒尺寸递过来 —— 不引入第二套绘制词汇表。
 *
 * ★ 画家是 (canvas, size) 的纯函数：同输入必然同墨。动画相位住 `State`。
 * ★ 只用自己查得到的能力：`Canvas::capabilities()` 查后端支持，Optional 层缺失时响亮失败。
 * ★ 自绘内容默认不进语义树（`SemanticsRole` 没有"图"这一类）；要机器可读就配 `Text`。
 *
 * `version()` 是内容戳，框架今天全树重画不消费它，供脏矩形 / 重绘调度使用。
 *
 * @api
 */
abstract class CustomPainter
{
    /**
     * 自绘。画布已 translate 到盒原点；`$size` 是本盒的**已布局**尺寸
     * （超出盒子的绘制会被外层视口裁掉 —— C5）。
     */
    abstract public function paint(Canvas $canvas, Size $size): void;

    /**
     * 内容戳：内容变了就 +1（不动 ⇒ 0）。语义是"这个画家画出来的东西换了没有",
     * 与 Widget 的重建次数无关（同一画家反复重建，版本也应该是同一个）。
     */
    public function version(): int
    {
        return 0;
    }
}
