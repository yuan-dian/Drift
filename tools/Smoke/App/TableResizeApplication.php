<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use Drift\Widget\TableColumnWidth;
use Drift\Widget\TableSort;
use Drift\Widget\Widget;

/**
 * 列宽拖拽的**按下归属**夹具（P2 阶段 4，2026-10-27）。
 *
 * ## 为什么需要它
 *
 * 阶段 4 的整个可行性押在一句话上：**手柄比表头整格在父链上更近 ⇒ 手柄赢下按下**。
 * 而 `TableState` 的常规 smoke 断言直接调 `pointerHandlerValue()`，**绕过了
 * `InputRouter::pointerOwner()` 的父链遍历** —— 验不出这件事。
 *
 * 上一轮（阶段 3 排序）就是这么掉进死胡同的：唯一手段是真机点像素，而探针的
 * 点击坐标与截图差了 1.6 倍，点偏之后被误判成"回调没触发"，白追大半天。
 * 本夹具存在的价值就是**把那件事搬进 CI**：`dispatchInput()` 走的是真实路由。
 *
 * ## 判据
 *
 * | 动作 | `resizeLog` | `sortLog` |
 * |---|---|---|
 * | 按在手柄上拖 | 有（拖动中 false、抬起 true）| **必须为空** |
 * | 按在表头标题上 | 空 | 有（升序）|
 *
 * ★ 两列都 `sortable()` + `resizable()`：这样"按住手柄"和"按住标题"是同一格上
 *   的两个点，才能证明按下**按位置**分流，而不是"整格归排序、手柄根本收不到"。
 *
 * @internal 测试夹具
 */
final class TableResizeApplication extends Application
{
    /** @var list<array{0: string, 1: float, 2: bool}> 列宽上报 */
    public array $resizeLog = [];

    /** @var list<array{0: string, 1: int}> 排序上报（列 key 与列序号）*/
    public array $sortLog = [];

    private ?Table $table = null;

    public function tableWidget(): ?Table
    {
        return $this->table;
    }

    public function buildRoot(): Widget
    {
        $this->table = Table::make(
            [
                TableColumn::make('id', 'ID')
                    ->field('id')
                    ->width(TableColumnWidth::fixed(60.0))
                    ->minWidth(40.0)
                    ->sortable()
                    ->resizable(),
                TableColumn::make('name', '姓名')
                    ->field('name')
                    ->minWidth(80.0)
                    ->maxWidth(200.0)
                    ->sortable()
                    ->resizable(),
            ],
            [
                ['id' => 1, 'name' => 'a'],
                ['id' => 2, 'name' => 'b'],
                ['id' => 3, 'name' => 'c'],
            ],
        )->keyOf(static fn(array $row): int => (int) $row['id'])
            ->rowHeight(40.0)
            ->headerHeight(32.0)
            ->sort(TableSort::none())
            ->onSortChange(function (TableSort $next, int $columnIndex): void {
                $this->sortLog[] = [$next->primaryColumn() ?? '', $columnIndex];
            })
            ->onColumnResize(function (string $key, float $width, bool $finished): void {
                $this->resizeLog[] = [$key, $width, $finished];
            });

        return $this->table;
    }
}
