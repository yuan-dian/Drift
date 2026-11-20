<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\StatelessShowcasePage;


use Drift\Geometry\Alignment;
use Drift\Geometry\EdgeInsets;
use Drift\Rendering\Color;
use Drift\Widget\BackdropFilter;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Grid;
use Drift\Widget\Padding;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\SizedBox;
use Drift\Widget\Stack;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;

/**
 * 毛玻璃 BackdropFilter。
 *
 * ## 它和 `->opacity()` 是两件事
 *
 * `->opacity(0.6)` 做出的是"透"：背后内容**清晰**地透过来，文字叠文字读不下去。
 * 毛玻璃是"先糊再叠色"—— 背后被糊成一片柔和色块，面板上的字才站得住。
 * 真实系统（macOS 侧栏 / Win11 设置 / iOS 控制中心）走的都是后者。
 *
 * ## 三段的用意
 *
 * ① **效果本体**：一张"壁纸"（12 个高对比色块）上盖一块毛玻璃面板。
 *    色块边缘在面板里应该完全消失，面板外一条边都不该被糊 —— 这两点一眼可判。
 * ② **半径梯度**：同样内容的三个面板，sigma = 4 / 12 / 28。4 还看得出色块轮廓，
 *    28 已经是"磨砂"。半径是逻辑像素，跟着 dpr 走。
 * ③ **边界说明**：这一页同时是"哪里不该用"的说明书。
 *
 * ★ **Raster 后端不实现模糊**（`CAP_BACKDROP` 是 Extended 近似）：金色基线里
 *   这一页退化成纯半透明面板，结构/透明度/层级全对，只少一层糊。所以"糊没糊"
 *   只能真机看 —— 这正是本页存在的理由之一。
 */
#[Route(id: 'glass', title: '毛玻璃 Glass', icon: IconName::Brush)]
final class GlassPage extends StatelessShowcasePage
{
    public function build(BuildContext $ctx): Widget
    {
        return ScrollView::make(
            Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
                Text::make('毛玻璃 BackdropFilter')->title(),
                Text::make(
                    '先糊背后、再叠色 —— 与 `->opacity()` 的"整体变透"是两件事：'
                    . '透明让背后**清晰**地穿过来，毛玻璃让背后变成一片柔和色块。'
                )->caption(),

                self::sectionBar('① 效果本体：壁纸上的毛玻璃面板（sigma 18）'),
                Stack::make()->alignment(Alignment::topLeft())->children([
                    self::wallpaper(4, 3),
                    Padding::make(
                        BackdropFilter::make(
                            Card::make(Column::make()->spacing(6.0)->children([
                                Text::make('毛玻璃面板')->title(),
                                Text::make('面板里的色块边缘应该完全消失；面板外的色块边缘必须还是硬的。'),
                            ]))
                                ->background(Color::fromRgba(0xFF, 0xFF, 0xFF, 0xC0))
                                ->radius(10.0)
                                ->paddingAll(16.0)
                        )->sigma(18.0)->width(420.0)
                    )->only(48.0, 48.0),
                ]),

                self::sectionBar('② 半径梯度：sigma = 4 / 12 / 28（同内容、同底色）'),
                Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
                    self::chip(4.0, 'sigma 4'),
                    self::chip(12.0, 'sigma 12'),
                    self::chip(28.0, 'sigma 28'),
                ]),

                self::sectionBar('③ 已知边界'),
                Card::make(Column::make()->spacing(4.0)->children([
                    Text::make('· 模糊范围是**矩形**：面板四个角若要圆角，圆角外的四个小三角仍是糊的。')->body(),
                    Text::make('· 它糊的是"自己这块面积背后的东西"，所以要包住面板本身，不是包住面板里的内容。')->body(),
                    Text::make('· 软光栅后端（测试/基线）不实现模糊，退化成半透明面板 —— 有意的降级。')->body(),
                ]))->paddingAll(12.0),
            ])
        );
    }

    /** 一节的分隔标题（与其它页同款写法） */
    private static function sectionBar(string $label): Widget
    {
        return Text::make($label)->body();
    }

    /**
     * "壁纸"：`$cols × $rows` 个高对比色块。
     *
     * ★ 用色块而不是文字：模糊的证据是**边缘消失**，色块边缘比字边缘好判得多
     *   （字形本身有笔画间隙，糊了之后像"没渲染好"）。
     * ★ `$cellHeight > 0` ⇒ 行高定死。小尺寸用法（半径梯度那三个）必须给：
     *   行高由内容撑时，`Grid` 会把"文字高 + 内边距"如实要出来，装不下就
     *   `RenderFlex 放不下` 中止（真机实证：105.2 要、70 可用）。
     */
    private static function wallpaper(int $cols, int $rows, float $pad = 14.0, float $cellHeight = 0.0): Widget
    {
        $palette = [
            Color::fromRgb(0x0A, 0x84, 0xFF), Color::fromRgb(0x00, 0xB8, 0x6B),
            Color::fromRgb(0xFF, 0x7A, 0x45), Color::fromRgb(0x9B, 0x5D, 0xE5),
            Color::fromRgb(0xFF, 0xC5, 0x3D), Color::fromRgb(0x00, 0xB8, 0xC4),
            Color::fromRgb(0xE9, 0x1E, 0x63), Color::fromRgb(0x5C, 0x6B, 0xC0),
            Color::fromRgb(0x2E, 0x7D, 0x32), Color::fromRgb(0xF4, 0x51, 0x1E),
            Color::fromRgb(0x1E, 0x88, 0xE5), Color::fromRgb(0x8E, 0x24, 0xAA),
        ];

        $cells = [];
        $total = $cols * $rows;
        for ($i = 0; $i < $total; $i++) {
            $cells[] = Card::make(
                Text::make((string) ($i + 1))->title()
            )->background($palette[$i % count($palette)])->paddingAll($pad);
        }

        $grid = Grid::make($cols)->spacing(6.0)->children($cells);
        if ($cellHeight > 0.0) {
            $grid = $grid->cellHeight($cellHeight);
        }

        return $grid;
    }

    /**
     * 半径梯度用的小面板：底色略淡，让"糊得够不够"一眼可比。
     *
     * ★ 两层**都定死 160×96**：`Grid` 在松约束下会铺满可用宽度 ⇒ 三个 `Stack`
     *   各自想占满整行，`Row` 当场"子项共需 5004、可用 1660"而中止
     *   （真机实证）。给 `Stack` 一个定尺寸是这条的**唯一**修法 ——
     *   光给 `BackdropFilter` 定宽没用，`Stack` 尺寸取 `max(子)`，铺满的
     *   `Grid` 仍然是那个 max。
     */
    private static function chip(float $sigma, string $label): Widget
    {
        return SizedBox::make(
            Stack::make()->alignment(Alignment::topLeft())->children([
                SizedBox::make(self::wallpaper(2, 2, 6.0, 36.0))->width(160.0)->height(96.0),
                BackdropFilter::make(
                    Card::make(Text::make($label)->title())
                        ->background(Color::fromRgba(0xFF, 0xFF, 0xFF, 0xB0))
                        ->radius(8.0)
                        ->paddingAll(10.0)
                )->sigma($sigma)->width(160.0)->height(96.0),
            ])
        )->width(160.0)->height(96.0);
    }
}
