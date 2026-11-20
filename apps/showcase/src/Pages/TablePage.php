<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\StatefulShowcasePage;

use App\Showcase\ShowcaseCapabilities;
use Drift\Widget\State;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use App\Showcase\Pages\States\TablePageState;

/**
 * 表格（Table）—— P2 阶段 1 + 2 + 3 的**真实消费者**。
 *
 * ★ 它存在的理由同 `BigListPage`：让"表头固定 + 行虚拟化"在**生产形态**下被走通
 *   一次。10000 行 × 5 列只造视口覆盖的六行；换成 `Each` 全量，同数据单帧数秒。
 *
 * ★ 这一页同时演示**两条取值路径**：
 *   · `field()`：关联数组行按字段名取（ID / 姓名 / 邮箱 / 城市）；
 *   · `cell()`：派生值（状态由 id 算出来，不是行里的字段）。
 *   ★ 两者**不能同时**写在同一列上（挂载期 Fatal）—— 这是 `TableColumn` 的契约。
 *
 * ★ 列宽三层在这页都出现了：`fixed`（ID / 城市 / 状态）、`flex(2)` / `flex(3)`
 *   （姓名 / 邮箱按权重分剩余）、`minWidth`（姓名再挤也不小于 120）。
 *   对齐三种都有：`Start`（默认）/ `End`（数字右对齐）/ `Center`。
 *
 * ★ **选择与排序都是受控的**：选中集与排序状态住本页的 `State`，`Table` 只回调
 *   "点完之后应该是什么"。所以"已选 N 项 / 清空 / 清除排序"都能工作 —— 如果它们
 *   住在控件的 `State` 里，应用**永远读不到**（`State` 外部无引用通道，本仓也没有
 *   `GlobalKey`）。
 *
 * ★ **排序由本页重排**（不是控件）：`Table` 不知道 `$row` 里 `name` 是中文还是
 *   日期，比较规则只有应用知道。看 `comparators()` —— 连"状态"这个**派生列**都能排，
 *   因为闭包与 `sortable()` 互不约束。
 *
 * ★ **诚实边界**（页面底部也写了一句）：无 Ctrl+方向键扩选、无拖动框选 ——
 *   都是阶段 7 后半及其前置。单元格编辑已在（阶段 7a 键盘 / 7b 指针：单击落格、
 *   双击进编辑、插入符落在指针处）。横向滚轮已在（阶段 5b：触控板横滑 / Shift+竖轮）。
 *   表头的键盘排序已在（§61：首行 ↑ 进表头 / Enter 三态；§62：Shift 多键追加次键）。
 *   排序后光标**留在原位置**（`focusedRow` 是下标，"眼睛在哪"不跟数据身份，§61.5）。
 */
#[Route(id: 'table', title: '表格 Table', icon: IconName::Grid)]
final class TablePage extends StatefulShowcasePage
{
    public static function make(ShowcaseCapabilities $caps): self
    {
        return new self($caps);
    }

    public function createState(): State
    {
        return new TablePageState($this->caps);
    }
}
