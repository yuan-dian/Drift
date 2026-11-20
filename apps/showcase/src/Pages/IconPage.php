<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use App\Showcase\Pages\Base\ShowcasePage;


use Drift\Geometry\EdgeInsets;
use Drift\Layout\FlexAlign;
use Drift\Rendering\Color;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Icon;
use Drift\Widget\IconName;
use Drift\Widget\ScrollView;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;

/**
 * 图标 Icon—— `Icon` 控件的形状、尺寸与着色。
 *
 * 演示面：
 *   · 10 个内置图标（`IconName` 全量 —— 与 `IconSet` 数据表一一对应）；
 *   · 12 / 16 / 24 / 32 四个尺寸（同一份归一化路径，形状一致）；
 *   · 显式着色（默认是主题的次要文字色 `Muted`）。
 *
 * ★ 图标是**矢量 Path**，不是位图也不是图标字体 —— 理由见 `IconName` 类注释
 *   （字模生成扫不到私有区码点 ⇒ 真机零宽隐形）。
 * ★ 这一页是"图标进 showcase"的第一个消费者：没有它，`Icon` 就只是"写了没人用"。
 */
#[Route(id: 'icon', title: '图标 Icon', icon: IconName::Plus)]
final class IconPage extends ShowcasePage
{
    public function build(BuildContext $ctx): Widget
    {
        // ★ padding 必须在 `Column` 上：`ScrollView` 不产渲染盒，装饰会静默失效
        //   （框架会 Fatal 拦住，这是守卫而非 bug）。
        return ScrollView::make(
            Column::make()->spacing(14.0)->cross(FlexAlign::Stretch)
                ->padding(EdgeInsets::all(24.0))->children([
                Text::make('图标 Icon')->title(),

                Card::make(Column::make()->spacing(12.0)->children([
                    Text::make('10 个内置图标（24px）')->caption(),
                    Wrap::make()->runSpacing(22.0)->spacing(22.0)->children([
                        Icon::make(IconName::Menu, 24.0),
                        Icon::make(IconName::Close, 24.0),
                        Icon::make(IconName::Check, 24.0),
                        Icon::make(IconName::ArrowLeft, 24.0),
                        Icon::make(IconName::ArrowRight, 24.0),
                        Icon::make(IconName::ChevronDown, 24.0),
                        Icon::make(IconName::Plus, 24.0),
                        Icon::make(IconName::Minus, 24.0),
                        Icon::make(IconName::Search, 24.0),
                        Icon::make(IconName::More, 24.0),
                    ]),
                ]))->paddingAll(16.0),

                Card::make(Column::make()->spacing(12.0)->children([
                    Text::make('尺寸：12 / 16 / 24 / 32（同一份路径，形状一致）')->caption(),
                    Wrap::make()->runSpacing(20.0)->spacing(20.0)->children([
                        Icon::make(IconName::Search, 12.0),
                        Icon::make(IconName::Search, 16.0),
                        Icon::make(IconName::Search, 24.0),
                        Icon::make(IconName::Search, 32.0),
                    ]),
                ]))->paddingAll(16.0),

                Card::make(Column::make()->spacing(12.0)->children([
                    Text::make('着色：默认 Muted / 显式 color()')->caption(),
                    Wrap::make()->runSpacing(20.0)->spacing(20.0)->children([
                        Icon::make(IconName::Check, 24.0),
                        Icon::make(IconName::Check, 24.0)->color(Color::fromRgb(0x16, 0x9F, 0xE8)),
                        Icon::make(IconName::Check, 24.0)->color(Color::fromRgb(0xC4, 0x3B, 0x36)),
                        Icon::make(IconName::Check, 24.0)->color(Color::fromRgb(0x39, 0xB3, 0x5A)),
                    ]),
                ]))->paddingAll(16.0),
            ]),
        );
    }
}
