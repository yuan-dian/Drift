<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Rendering\Color;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Column;
use Drift\Widget\Each;
use Drift\Widget\Expanded;
use Drift\Widget\Padding;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\Spacer;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 页 5「列表 List」—— `Each` 数据驱动 + `ScrollView` 滚动（2026-10-17 落地）。
 *
 * 对照 LayUI 的 list / table：列表行 = 标题 + 次要信息 + 右侧动作。Drift 用
 * `Each`（key 必填，R4）驱动，长列表（16 行超过视口）走 `ScrollView` ——
 * 滚动偏移**布局期进子盒 offset**（坐标链纪律，§24.2 #15）。
 */
final class ListPage extends StatelessWidget
{
    public function build(BuildContext $ctx): Widget
    {
        // ★ 同其余文档页：白面板撑满视口，滚动发生在面板**内部**。
        return Card::make(ScrollView::make($this->body()))->paddingAll(0.0);
    }

    /** 页面内容（`build()` 只负责套一层面板外壳） */
    private function body(): Widget
    {
        $rows = [];
        for ($i = 1; $i <= 16; $i++) {
            $rows[] = '条目 ' . $i . ' —— 悬停我看光标变化';
        }

        return Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
            Text::make('列表 List')->title(),
            Text::make('16 行种子数据超过视口 ⇒ 页根自含滚动（ScrollView，§24.2 #15）。行由 Each 按 string key 驱动（R4：key 必填且唯一）。')->caption(),

            Each::make(static fn(string $row): string => $row)
                ->items($rows, static fn(string $row): Widget => ListPage::row($row)),
        ]);
    }

    private static function row(string $label): Widget
    {
        return Padding::make(
            Row::make()->spacing(8.0)->children([
                Expanded::make(Text::make($label)->body()),
                Button::make('操作')
                    ->variant(ButtonVariant::Default)
                    ->background(Color::fromRgb(0xFF, 0xFF, 0xFF))
                    ->border(Color::fromRgb(0xE6, 0xE6, 0xE6), 1.0),
            ])
        )->symmetric(12.0, 8.0);
    }
}
