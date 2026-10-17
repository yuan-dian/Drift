<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Rendering\Color;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Center;
use Drift\Widget\Column;
use Drift\Widget\Dock;
use Drift\Layout\FlexAlign;
use Drift\Widget\Padding;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\Spacer;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 页 1「布局 Layout」—— 两种定位原语 + flex 排布。
 *
 * 演示面（全部 §13.1 的能力）：
 *   · `Dock` 五槽（顶/底横跨、左右占中段、center 余下）
 *   · `Column/Row` 的 `spacing` / `cross` 交叉轴对齐
 *   · `Expanded` 占剩余（flex 分母 2026-10-17 已修，见 §24.2 #15）
 *   · `Center` 居中、`Spacer` 吃掉剩余、`Padding` 内边距
 *   · 盒样式修饰符（`BoxWidget::background/border/radius`）
 *
 * 对照 LayUI：文档页没有专门的"布局"页（布局由 CSS 网格承担）；本页演示的是
 * Drift2 用**声明式控件**表达同一件事的形态。
 */
final class LayoutPage extends StatelessWidget
{
    public function build(BuildContext $ctx): Widget
    {
        return ScrollView::make(
            Column::make()->spacing(16.0)->padding(EdgeInsets::all(20.0))->children([
                Text::make('布局 Layout')->title(),
            Text::make('两种定位原语（CenterBox / Dock 的语义）+ flex 排布。以下每块都是可运行的声明。')->caption(),

            // ── Row：主轴排布 + cross 交叉轴对齐 ──
            Text::make('Row：主轴排布 + 三种交叉轴对齐')->heading(),
            Row::make()->spacing(8.0)->children([
                Button::make('cross:Start')->variant(ButtonVariant::Default),
                Button::make('cross:Center')->variant(ButtonVariant::Default),
                Button::make('cross:End')->variant(ButtonVariant::Default),
            ])->cross(FlexAlign::Center),
            Row::make()->spacing(8.0)->children([
                Text::make('Start（默认）')->caption(),
                Button::make('中')->variant(ButtonVariant::Primary),
                Text::make('End')->caption(),
            ])->cross(FlexAlign::End),

            // ── Spacer + Expanded：把剩余空间吃掉 / 占住 ──
            Text::make('Spacer / Expanded：剩余空间的两种用法')->heading(),
            Row::make()->spacing(8.0)->children([
                Button::make('左')->variant(ButtonVariant::Default),
                Spacer::make(),
                Text::make('中间被推开')->caption(),
                Spacer::make(),
                Button::make('右')->variant(ButtonVariant::Default),
            ]),
            Padding::make(
                Text::make('Spacer 已在上方演示"推开两边"；Expanded（占剩余高）归 M2LayoutPage 演示 —— 本页不再重复。')->caption()
            )->all(8.0),

            // ── Dock：五槽骨架 ──
            // ★ 演示**暂缺**（如实登记，§24.2 #18）：Dock 在**无界主轴**
            //   （ScrollView 给内容量长度的约束）下把 INF 当自己的高往下传
            //   （bottom 槽 offsetY=INF）—— 本页根是 ScrollView ⇒ 无界，
            //   复现脚本 `_build/repro_dock_inf.php`。Dock 五槽的**正确演示**
            //   在有界窗口的 `tools/Preview/ShellPage.php`（§12.1 外壳页），
            //   用户的列表页同样是 Dock（有限视口，不触发）。
            Text::make('Dock：五槽骨架 —— 见 ShellPage（有界窗口）与 §24.2 #18')->heading(),

            // ── 盒样式：背景 / 边框 / 圆角（§15.7 修正后真的画出来）──
            Text::make('盒样式修饰符：background / border / radius')->heading(),
            Row::make()->spacing(8.0)->children([
                Button::make('主色按钮')
                    ->background(Color::fromRgb(0x00, 0x96, 0x88))
                    ->border(Color::fromRgb(0x00, 0x6D, 0x62), 1.5)
                    ->radius(8.0),
                Button::make('危险')
                    ->variant(ButtonVariant::Danger),
                Button::make('幽灵')
                    ->background(Color::fromRgb(0xFF, 0xFF, 0xFF))
                    ->border(Color::fromRgb(0xE6, 0xE6, 0xE6), 1.0)
                    ->radius(6.0),
            ]),
        ])
        );
    }

    /** Dock 槽位的小盒子（固定内容，宽度由内容撑开） */
    private function slot(string $label): Widget
    {
        return Padding::make(
            Text::make($label)->caption()
        )->symmetric(12.0, 6.0);
    }
}
