<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\Size;
use Drift\Rendering\Canvas;

/**
 * 自绘画家 —— 19 op 的**逃生舱**（2026-10-20 P2 回植，design-v6 的 CustomPaint 决策）。
 *
 * 19 op 覆盖不到的控件（仪表盘 / 走势图 / 示波器 / 任何"这曲线只有画家自己知道
 * 怎么画"的东西）经这里出去：`CustomPaint` 持一个画家，渲染盒 `paint()` 时把
 * **已 translate 到本盒原点**的画布和本盒尺寸递过来。画家直接调 `Canvas` 的
 * op 画 —— 不引入第二套绘制词汇表，19 op 的能力边界就是它的能力边界。
 *
 * ★ **画家是 (canvas, size) 的纯函数**（§14.1 / G3）：同输入必然同墨。动画相位
 *   不住这里，住 `State`（`tickFrame()` 驱动，Widget 每次重建换新画家）—— 与
 *   `Spinner → SpinnerState` 同形态（2026-10-23 起 `Spinner` 走叶子路径，
 *   `SpinnerBox` 已删；有子的 `TextField` 仍保留 `TextFieldBox`）。
 * ★ **只用自己查得到的能力**：画家可经 `Canvas::capabilities()` 查后端支持
 *   （Extended 层允许近似降级、Optional 层缺失时响亮失败 —— 位掩码是 D2 的
 *   契约形状，见 `CanvasCapabilities`）。示例台的 gauge/sparkline 只画
 *   Core 层（ellipse/line/rect），所以双后端都能出图。
 * ★ **不给语义角色**：`SemanticsRole` 今天只有 button/textfield/scroll/dialog，
 *   没有"图"这一类 ⇒ 自绘内容默认不进语义树（§9 加性原则）。画出来的信息
 *   要用语义机器可读，就在画家旁边配 `Text`。
 * ★ **`version()` 是内容戳**：画家自己声明"内容变了"的单调版本号。今天框架
 *   全树重画（§13.3）不消费它；真实消费者是脏矩形 / 重绘调度（M6 候选），
 *   示例台的自绘页把它当诊断 HUD 显示出来（每次重画都让使用者看得见版本戳
 *   在走）—— 于是"没人消费的分支"没有落在桩上。
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
