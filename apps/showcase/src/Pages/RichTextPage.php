<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use Drift\Widget\RichText;
use Drift\Widget\Span;
use Drift\Widget\State;
use Drift\Widget\Text;
use App\Showcase\Pages\States\RichTextPageState;

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
final class RichTextPage extends StatefulShowcasePage
{
    public function createState(): State
    {
        return new RichTextPageState($this->caps);
    }
}
