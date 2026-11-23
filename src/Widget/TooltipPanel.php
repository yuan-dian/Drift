<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\EdgeInsets;

/**
 * 悬停提示的**形体**（W8-2，2026-10-23）—— 纯工厂，不是 Widget。
 *
 * ```php
 * IconButton::of(IconName::Check, '保存')->tooltip('保存当前文档 (Ctrl+S)');   // 应用侧只有这一行
 * ```
 *
 * ★ **为什么是工厂而不是 Widget 类**：Tooltip 的形体就是"`PopupPanel` + 一段小字"，
 *   没有任何新的布局/绘制行为。造一个 `class TooltipPanel extends BoxWidget` 只是
 *   把三行链式调用搬进构造器，换来的成本是"多一个需要测的 RenderBox 子类"。
 *   ★ 分工承 `§5.1` 裁决 1：**控件只管形体、Application 管生命周期与落位**。
 *     本类只被 `OverlayManager::showTooltip()` 用一次。
 * ★ 要换样式（深色底 / 圆角更大 / 带阴影）就覆写本方法或直接改这里 —— 不要
 *   在应用里手搓一份 `PopupPanel` 再自己 push 浮层（那样时序得自己写对）。
 *
 * @api 应用一般**不需要**直接用它（`Widget::tooltip()` 就够了）
 */
final class TooltipPanel
{
    /**
     * 面板宽度上限（逻辑像素）。
     *
     * ★ 320 ≈ 40 个汉字，一行提示的合理上限。不设上限的话，一段长文案会让面板
     *   横穿整个窗口（`placeEntry()` 对非居中层给的是无界约束）。
     */
    public const float MAX_WIDTH = 320.0;

    private function __construct()
    {
        // 纯静态工厂：不允许实例化
    }

    /**
     * 造一个提示面板（`PopupPanel` 底 + `Caption` 小字）。
     *
     * ★ 内边距 8/5 比菜单的 4 更宽松 —— tooltip 没有可点行，文字贴着边框会显得局促。
     * ★ `elevation(4)` 留投影空间；`placeEntry()` 用 `panelRect()` 定位（已扣掉这一圈）。
     */
    public static function make(string $text): Widget
    {
        return PopupPanel::make(Text::make($text)->caption())
            ->padding(EdgeInsets::symmetric(horizontal: 8.0, vertical: 5.0))
            ->elevation(4.0);
    }
}
