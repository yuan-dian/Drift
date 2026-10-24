<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Rendering\Color;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\RichText;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\Span;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 页 19「富文本 RichText」—— W8-5 的第一件（2026-10-24）。
 *
 * 三段演示：
 *
 *   ① 错误提示（加粗 + 换色）—— 最常用的场景；
 *   ② 混合字号（32pt 数字 + 14pt 说明）—— 行高按**最大字号**算，大字不被压扁；
 *   ③ 长文本跨 span 断行（定宽 420）—— 断行点落在任意位置，不在 span 边界强制换行。
 *
 * ★ 判据是"同一行里三种样式、换行点跟着宽度走"；颜色/字号由 run 自带，
 *   排版仍是一次成段。
 */
final class RichTextPage extends StatelessWidget
{
    public function build(BuildContext $ctx): Widget
    {
        return ScrollView::make(
            Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
                Text::make('富文本 RichText')->title(),
                Text::make(
                    '一段里多个样式段；排版**一次成段** ⇒ 断行跨 span，光标/选中仍用全文字节偏移。'
                )->caption(),

                self::sectionBar('① 错误提示：加粗 + 换色'),
                RichText::make([
                    Span::make('保存失败：')->bold(),
                    Span::make('磁盘空间不足（剩余 12 MB）')->color(Color::fromHex('#d93025')),
                ])->size(16.0),

                self::sectionBar('② 混合字号（行高按最大字号，大字不被压扁）'),
                RichText::make([
                    Span::make('23')->size(32.0)->bold(),
                    Span::make(' ms · 平均帧时间（14pt 说明跟在大字后面）')->size(14.0),
                ]),

                self::sectionBar('③ 长文本跨 span 断行（定宽 420）'),
                RichText::make([
                    Span::make('这是一段很长的说明文字，用来验证 '),
                    Span::make('断行可以跨 span')->bold()->color(Color::fromHex('#009688')),
                    Span::make('，而不是在每个 span 的边界强制换行。窗口宽度变化时，换行点会跟着动。'),
                ])->width(420.0),

                self::sectionBar('④ 对照：同内容用 Text（单样式，不能混排）'),
                Text::make('保存失败：磁盘空间不足（剩余 12 MB）')->size(16.0),
            ])
        );
    }

    private static function sectionBar(string $title): Widget
    {
        return Row::make()->spacing(8.0)->children([Text::make($title)->heading()]);
    }
}
