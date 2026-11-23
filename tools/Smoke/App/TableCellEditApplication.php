<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\Bind;
use Drift\Widget\BuildContext;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use Drift\Widget\TableColumnWidth;
use Drift\Widget\Widget;

/**
 * 单元格编辑的**真实路由**夹具（P2 阶段 7a，2026-10-27）。
 *
 * ## 为什么需要它
 *
 * 编辑会话有三件事只有真实路由才验得到：
 *
 * 1. **Enter 走的是 `keyboard()` 那一跳**（`FocusManager::dispatchKey()` → 最近的
 *    声明者 → `TableState::handleFocusKey()`）。直接调 `beginEdit()` 绕过它，
 *    验不出"焦点不在表上时不该进入编辑"。
 * 2. **不可编辑的列必须放行 Enter**（`dispatchKey()` 返回 `false`）：吞掉的话
 *    应用的自定义语义（"回车打开详情"）就被框架抢走了，而这件事在回调层面看不出来。
 * 3. **提交报的是 row key 不是行下标**：排序 / 过滤换了行序之后仍写对行 ——
 *    断言必须发生在"行序已经变了"之后。
 *
 * ## 数据怎么变（★ 这一条踩过）
 *
 * 应用改完数据要让树重建，而 `Element::markNeedsBuild()` **只对 `StatefulElement`
 * 有效**（根是 `StatelessElement` ⇒ 调了什么都不发生，现象是"回调跑了但画面没变"）。
 * ⇒ 夹具照真机的通道走：`TableCellEditStore` 的 `Cell` + `Bind` 的 builder 读它。
 *
 * ## 判据
 *
 * | 动作 | `editLog` | 编辑态 |
 * |---|---|---|
 * | `id` 列按 Enter | 空 | 不进编辑（键放行）|
 * | `name` 列按 Enter | 空 | 进编辑 |
 * | 打字 + Enter | `[key, 'name', 新文本]` | 退出，光标下移 |
 * | 打字 + Esc | 空 | 退出（草稿丢弃）|
 * | 不改直接 Enter | 空（同值不回调）| 退出 |
 * | 打字 + 失焦 / 滚动 | 有 | 退出 |
 * | 编辑中行序变了 + Enter | 报**原 row key** | 退出 |
 *
 * @internal 测试夹具
 */
final class TableCellEditApplication extends Application
{
    /** @var list<array{0: string|int, 1: string, 2: string}> 提交上报（行 key / 列 key / 新值） */
    public array $editLog = [];

    public readonly TableCellEditStore $store;

    private ?Table $table = null;

    public function __construct()
    {
        parent::__construct();
        $this->store = new TableCellEditStore();
    }

    public function tableWidget(): ?Table
    {
        return $this->table;
    }

    public function buildRoot(): Widget
    {
        return Bind::make(function (BuildContext $ctx): Widget {
            $this->table = Table::make(
                [
                    // 不可编辑的列（Enter 必须放行）
                    TableColumn::make('id', 'ID')
                        ->field('id')
                        ->width(TableColumnWidth::fixed(100.0)),
                    // 可编辑的列
                    TableColumn::make('name', '姓名')
                        ->field('name')
                        ->width(TableColumnWidth::fixed(200.0))
                        ->editable(),
                ],
                $this->store->rows->get(),
            )->keyOf(static fn(array $row): int => (int) $row['id'])
                ->rowHeight(40.0)
                ->headerHeight(32.0)
                ->onCellEdit(function (int $rowKey, string $column, string $value): void {
                    $this->editLog[] = [$rowKey, $column, $value];
                    $this->store->setCell($rowKey, $column, $value);
                });

            return $this->table;
        });
    }
}
