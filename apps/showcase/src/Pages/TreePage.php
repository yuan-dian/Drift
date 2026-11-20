<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use App\Showcase\ShowcaseCapabilities;
use Drift\Widget\Expanded;
use Drift\Widget\State;
use Drift\Widget\TreeView;
use App\Showcase\Pages\States\TreePageState;

/**
 * 树 TreeView。
 *
 * ★ 这一页存在的理由同 `TablePage`：让"层级数据 + 虚拟化"在**生产形态**下被走通
 *   一次，同时把"受控"这件事演成看得见的东西 ——
 *   · 展开集合住本页 `State` ⇒ 上面两个按钮能直接改它（控件自持时应用**读不到**）；
 *   · 选中值也住本页 `State` ⇒ 右上角能显示"选中的是哪一个"；
 *   · 「展开全部 / 折叠全部」不是控件能力，是应用拿着数据的结果 —— 这正是
 *     受控的价值：搜索时自动展开命中路径、跨会话记住展开态，都是同一件事。
 *
 * ★ 页根**刻意不是** `ScrollView`（与其余文档页不同）：`TreeView` 内部就是
 *   `LazyColumn`，**自己就是滚动视口**。外面再套一层就是嵌套滚动 —— 内层永远
 *   "量到全部内容"，外层滚不动，两层都白搭。这里用 `Expanded` 给它有界高度。
 *
 * ★ 行高默认 42 不是审美参数：`IconButton` 的天然边长就是 42（`3×gap + 字号`）。
 *   调小会被 `LazyColumn` 的行盒静默裁掉，所以 `rowHeight()` 小于它**响亮失败**。
 */
final class TreePage extends StatefulShowcasePage
{
    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new TreePageState($this->caps);
    }
}
