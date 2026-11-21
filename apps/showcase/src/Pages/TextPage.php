<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\ShowcasePage;


use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\ScrollView;
use Drift\Widget\Column;
use Drift\Widget\Row;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;

/**
 * 文本 Text—— 五级角色 + 排版控制。
 *
 * 对照 LayUI 文档页的标题层级（H1 主标题 + 引用导语 + H2 分节 + H3 小节）：
 * Drift 用**语义角色**（`TextRole`）而非字号硬编码 —— 换主题时字号/颜色跟着走
 * （"角色归主题"）。这也是本页想演示的核心：**复刻设计稿不写死 px**。
 *
 * 演示面：title / heading / body / caption / error 五级 + size / bold /
 * maxLines / ellipsize。
 */
#[Route(id: 'text', title: '文本 Text', icon: IconName::TextLines)]
final class TextPage extends ShowcasePage
{
    public function build(BuildContext $ctx): Widget
    {
        // ★ 页面 = **一整块白色面板 + 面板内滚动**（LayUI 文档页的形态）：
        //   卡片撑满视口高、内容在卡片里滚。反过来（卡片只包内容）会让卡片只占
        //   上半屏，下半屏露出灰底 —— 看着像"没加载完"。
        return Card::make(ScrollView::make($this->body()))->paddingAll(0.0);
    }

    /** 页面内容（`build()` 只负责套一层卡片外壳，与 `LayoutPage` 的"每块一张卡"互补） */
    private function body(): Widget
    {
        return Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
            Text::make('文本 Text')->title(),

            // ── 五级角色（字号/色从主题解析，不写死）──
            Text::make('五级语义角色（字号与颜色来自主题，不是硬编码）')->heading(),
            Text::make('title：页面主标题')->title(),
            Text::make('heading：分节标题')->heading(),
            Text::make('body：正文段落。复刻设计稿时优先用角色，需要精确字号时才 ->size()。')->body(),
            Text::make('caption：说明 / 次要信息')->caption(),
            Text::make('error：错误回显（同色不同级）')->error(),

            // ── 排版控制 ──
            Text::make('排版控制')->heading(),
            Text::make('bold 加粗（与 heading 的语义加粗不同：这是显式要求）')->bold(),
            Text::make('maxLines + ellipsize：超长截断加省略号，不折行不静默溢出。这一段文字故意写得比容器宽，用来触发省略号形态。')
                ->maxLines(2)
                ->ellipsize(),
            Text::make('显式 size：精确字号（复刻设计稿的最后手段）')->size(20.0),

            // ── 行内组合 ──
            Text::make('行内组合：Row 排布标签与值')->heading(),
            Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                Text::make('姓名')->caption(),
                Text::make('张三')->body(),
                Text::make('邮箱')->caption(),
                Text::make('zhangsan@example.com')->body(),
            ]),
        ]);
    }
}
