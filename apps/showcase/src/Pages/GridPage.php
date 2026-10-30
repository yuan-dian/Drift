<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Expanded;
use Drift\Widget\Grid;
use Drift\Widget\LazyGrid;
use Drift\Widget\Row;
use Drift\Widget\Spacer;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 页 17「网格 Grid / LazyGrid」—— W8-3 布局广度的第一件（2026-10-24）。
 *
 * 三段演示：
 *
 *   ① 3 列 × 2 行（6 项满行）：`cellHeight()` 统一行高 ⇒ 整齐的卡片墙；
 *   ② 5 项 / 3 列 ⇒ **末行只有 2 格，但格宽与首行相同** —— 这一条是本控件存在的
 *      全部理由（手写 `Column` 套 `Row` 时末行会被 `Expanded` 撑宽）；
 *   ③ `LazyGrid` 4 列 40 项：只造视口覆盖的行，滚动是它自己（不吃外层 `ScrollView`）。
 *
 * ★ **懒版必须放进有界高度**（这里是 `Expanded`）：`LazyGrid` / `LazyColumn` 自己
 *   就是滚动视口，塞进外层 `ScrollView` 会拿到无界高 ⇒ 窗口算不出来（`04` §1.5
 *   那条"布局必须纯"的边界）。所以本页**不套** `ScrollView`：上半是定高内容、
 *   下半 `Expanded` 交给懒网格。
 *
 * ★ 两个版本共用同一份分行规则（`Grid::rowOf()`）⇒ 同一批数据在"全量"与"懒"
 *   之间切换，格宽逐像素一致。想看这件事就调窗口宽度：①②③ 的格子会一起变宽变窄。
 */
final class GridPage extends StatelessWidget
{
    /** 卡片墙的行高（含卡片自己的内边距） */
    private const CELL_HEIGHT = 48.0;

    /** 懒网格的行**节距**（含行间距 12 ⇒ 格子实际 52 高） */
    private const LAZY_PITCH = 64.0;

    /** 懒网格的项数（40 项 / 4 列 = 10 行，视口只造前几行） */
    private const LAZY_ITEMS = 40;

    public function build(BuildContext $ctx): Widget
    {
        return Card::make(
            Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
                Text::make('网格 Grid / LazyGrid')->title(),
                Text::make(
                    '等宽列 = Row + Expanded（框架把剩余宽度按份额均分）—— Grid 只负责'
                    . '「切块」和「末行补位」，**没有新的渲染盒**。'
                )->caption(),

                self::sectionBar('① 3 列 × 2 行 · cellHeight 统一行高'),
                Grid::make(3)->spacing(12.0)->cellHeight(self::CELL_HEIGHT)->children(
                    self::cards(['甲', '乙', '丙', '丁', '戊', '己'])
                ),

                self::sectionBar('② 末行不满（5 项 / 3 列）—— 格宽与首行**相同**'),
                Grid::make(3)->spacing(12.0)->cellHeight(self::CELL_HEIGHT)->children(
                    self::cards(['一', '二', '三', '四', '五'])
                ),
                Text::make(
                    '末行只有 2 个格子，但宽度仍是 1/3 —— 手写 Column 套 Row 时，'
                    . 'Expanded 会把剩余宽度均分给剩下的格子，末行卡片会变宽（很难看出来）。'
                )->caption(),

                self::sectionBar('③ LazyGrid · 4 列 40 项（只造视口覆盖的行）'),
                // ★ 有界高度：它自己就是滚动视口，不能塞进外层 ScrollView
                Expanded::make(
                    LazyGrid::make(4, self::LAZY_PITCH)
                        ->spacing(12.0)
                        ->items(self::indices(self::LAZY_ITEMS), static fn(int $i): Widget => self::tile($i))
                ),
            ])
        )->paddingAll(0.0);
    }

    /** @param list<string> $labels @return list<Widget> */
    private static function cards(array $labels): array
    {
        $out = [];
        foreach ($labels as $label) {
            $out[] = Card::make(Text::make($label)->body())->paddingAll(8.0);
        }

        return $out;
    }

    /** @return list<int> */
    private static function indices(int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $out[] = $i;
        }

        return $out;
    }

    /** 懒网格的一格：卡片 + 行号（滚动时能核对"第几项真的在"） */
    private static function tile(int $index): Widget
    {
        return Card::make(
            Row::make()->spacing(6.0)->children([
                Expanded::make(Text::make('格 ' . ($index + 1))->body()),
            ])
        )->paddingAll(8.0);
    }

    private static function sectionBar(string $title): Widget
    {
        return Text::make($title)->heading();
    }
}
