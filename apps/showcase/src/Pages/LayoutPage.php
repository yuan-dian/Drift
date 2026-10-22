<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Rendering\Color;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Card;
use Drift\Widget\Center;
use Drift\Widget\Column;
use Drift\Widget\Dock;
use Drift\Layout\FlexAlign;
use Drift\Layout\StackAlign;
use Drift\Layout\StackFit;
use Drift\Widget\Padding;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\SizedBox;
use Drift\Widget\Spacer;
use Drift\Widget\Stack;
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
 * ★ **每个 demo 一个 `Card`**（2026-10-17）：页面背景是浅灰、内容块是白卡片 ——
 *   "分组"是质感的第一来源。此前所有内容直接铺在纯白页面上，块与块之间只有
 *   间距没有边界，观感就是用户说的"生硬、没有质感"。
 *
 * 对照 LayUI：文档页没有专门的"布局"页（布局由 CSS 网格承担）；本页演示的是
 * Drift2 用**声明式控件**表达同一件事的形态。
 */
final class LayoutPage extends StatelessWidget
{
    public function build(BuildContext $ctx): Widget
    {
        return ScrollView::make(
            // ★ `cross(Stretch)`：卡片是**块级**的（LayUI 的 `.layui-card` 就是
            //   撑满容器宽），不拉伸的话每张卡只有内容那么宽，右边一大片空白 ——
            //   那是"列表"不是"页面"。
            Column::make()->cross(FlexAlign::Stretch)->spacing(12.0)->padding(EdgeInsets::all(16.0))->children([
                Text::make('布局 Layout')->title(),
                Text::make('两种定位原语（CenterBox / Dock 的语义）+ flex 排布。以下每块都是可运行的声明。')->caption(),

                // ── Row：主轴排布 + cross 交叉轴对齐 ──
                $this->demo('Row：主轴排布 + 三种交叉轴对齐', Column::make()->spacing(10.0)->children([
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
                ])),

                // ── Spacer + Expanded：把剩余空间吃掉 / 占住 ──
                $this->demo('Spacer / Expanded：剩余空间的两种用法', Column::make()->spacing(10.0)->children([
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
                ])),

                // ── Dock：五槽骨架 ──
                // ★ 本块曾是 **§24.2 #18 的曝光位**：页根是 ScrollView（无界主轴，
                //   "先量内容有多长"）时 Dock 旧行为把 INF 当自己的高往下传
                //   （bottom 槽 offsetY=INF）⇒ 演示一度暂缺。2026-10-17 修复
                //   （无界主轴取内容自然高）后恢复 —— 这里是"示例台逼出核心缺陷、
                //   缺陷修复回填示例"闭环的注脚。
                $this->demo('Dock：五槽骨架（无界滚动页里也正确）', Dock::make()
                    ->top($this->slot('top（横跨整宽）'))
                    ->left($this->slot('left'))
                    ->center($this->slot('center'))
                    ->right($this->slot('right'))
                    ->bottom($this->slot('bottom（横跨整宽）'))),

                // ── 盒样式：背景 / 边框 / 圆角（§15.7 修正后真的画出来）──
                $this->demo('盒样式修饰符：background / border / radius', Row::make()->spacing(8.0)->children([
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
                ])),

                // ── Stack：层叠 + 3×3 方位对齐（W6 L4-4）──
                // ★ 三个形态各守一个口径，且都是**真消费**：AOT 只编译可达代码，
                //   没有这一段 `RenderStack` 在真机上等于没验。
                //   · 左：`Loose` + `TopRight` ⇒ 角标压在右上
                //   · 中：`Expand` ⇒ 底层拉满**父给的**空间（不是 max(子)）
                //   · 右：`BottomRight` ⇒ 另一个角，验证对齐不是只实现了 TopLeft
                // ★ 标题用"3×3 方位"而不是"九宫格"：内置点阵字模缺"九""宫"两个字
                //   （`CjkGlyphData.php` 落后仓库 164 个字符，本机装不上 PIL 无法重生成），
                //   而缺字是**静默无墨** —— 只有看图才发现。
                $this->demo('Stack：层叠 + 3×3 方位对齐（后加的子压在上面）', Row::make()->spacing(12.0)->children([
                    Stack::make()->alignment(StackAlign::TopRight)->children([
                        SizedBox::make()->size(160.0, 64.0)
                            ->background(Color::fromRgb(0xF2, 0xF2, 0xF2))
                            ->border(Color::fromRgb(0xE6, 0xE6, 0xE6), 1.0)
                            ->radius(6.0),
                        SizedBox::make()->size(20.0, 20.0)
                            ->background(Color::fromRgb(0xFF, 0x57, 0x22))
                            ->radius(10.0),
                    ]),
                    SizedBox::make(
                        Stack::make()->fit(StackFit::Expand)->children([
                            SizedBox::make()->height(64.0)
                                ->background(Color::fromRgb(0xE8, 0xEE, 0xEC))
                                ->radius(6.0),
                            Center::make(Text::make('Expand 铺满')->caption()),
                        ])
                    )->size(160.0, 64.0),
                    Stack::make()->alignment(StackAlign::BottomRight)->children([
                        SizedBox::make()->size(160.0, 64.0)
                            ->background(Color::fromRgb(0xF2, 0xF2, 0xF2))
                            ->border(Color::fromRgb(0xE6, 0xE6, 0xE6), 1.0)
                            ->radius(6.0),
                        SizedBox::make()->size(24.0, 12.0)
                            ->background(Color::fromRgb(0x00, 0x96, 0x88))
                            ->radius(3.0),
                    ]),
                ])),
            ])
        );
    }

    /**
     * 一个 demo 块 = **白卡片 + 标题 + 内容**。
     *
     * ★ 收 `Widget` 而不是 `list<Widget>`：`array_merge` 在 tpc 下的行为没验过，
     *   而"多一层 Column"零风险（本仓纪律：不为省打字引入未验机制）。
     */
    private function demo(string $title, Widget $content): Widget
    {
        return Card::make(
            Column::make()->spacing(10.0)->children([
                Text::make($title)->heading(),
                $content,
            ])
        )
            // LayUI 的 `.layui-card` 是 `0 1px 2px rgba(0,0,0,.05)` —— 一点点贴地阴影
            // 让卡片从灰底上"浮"起来半毫米。再重就是 Material 的卡片，不是 LayUI。
            ->shadow(0.06);
    }

    /** Dock 槽位的小盒子（固定内容，宽度由内容撑开） */
    private function slot(string $label): Widget
    {
        return Padding::make(
            Text::make($label)->caption()
        )->symmetric(12.0, 6.0);
    }
}
