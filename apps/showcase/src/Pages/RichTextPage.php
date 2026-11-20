<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\ShowcasePage;
use Drift\Geometry\EdgeInsets;
use Drift\Rendering\Color;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\RichText;
use Drift\Widget\ScrollView;
use Drift\Widget\Span;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 富文本 RichText—— W8-5。
 *
 * 五段演示：
 *
 *   ① 错误提示（加粗 + 换色）；
 *   ② 混合字号（32pt + 14pt）—— 行高按最大字号；
 *   ③ 长文本跨 span 断行（定宽 420）；
 *   ④ 对照：同内容用 `Text`（单样式）；
 *   ⑤ 行内链接：`Span::tag('retry')` + `onSpanTap()`，点击后计数。
 *
 * ★ ⑤ 的判据是"点链接计数 +1、点文字其他地方不变"—— tag 命中靠字节偏移，
 *   不是"整个控件都是链接"。
 */
#[Route(id: 'rich-text', title: '富文本 Rich', icon: IconName::TextLines)]
final class RichTextPage extends ShowcasePage
{
    private int $clicks = 0;

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

                self::sectionBar('⑤ 行内链接：点「重试」计数（tag + onSpanTap）'),
                RichText::make([
                    Span::make('加载失败，'),
                    Span::make('重试')->tag('retry')->underline()->color(Color::fromHex('#009688')),
                    Span::make('　（已点击 ' . $this->clicks . ' 次；点链接，或 Tab 聚焦后按 Enter）'),
                ])->onSpanTap(function (string $tag): void {
                    if ($tag === 'retry') {
                        $this->setState(function (): void {
                            $this->clicks++;
                        });
                    }
                }),

                self::sectionBar('⑥ 背景色高亮（字体内容区，不涂行距）'),
                RichText::make([
                    Span::make('提示：'),
                    Span::make(' 已保存 ')->background(Color::fromHex('#e8f5e9')),
                    Span::make('  修改 ')->background(Color::fromHex('#fff3cd')),
                    Span::make('（背景按各自字号的内容区算）'),
                ])->size(16.0),
            ])
        );
    }

    private static function sectionBar(string $title): Widget
    {
        return Text::make($title)->heading();
    }
}
