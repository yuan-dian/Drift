<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\BuildContext;
use Drift\Widget\StatefulElement;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use Drift\Widget\TableColumnWidth;
use Drift\Widget\TableSelection;
use Drift\Widget\TableSelectionMode;
use Drift\Widget\Widget;

/**
 * 拖动框选的真实路由夹具（P2 阶段 8，2026-10-28）。
 *
 * ## 为什么是 Application + Host 两层
 *
 * 受控值必须住在**一个 `State` 里**（`Application` 自己没有 `setState`）：应用
 * 收到 `onSelectionChange` 就 `setState` 回写，表按新选中集重建 —— 这是真应用的
 * 形状，也是"拖动松手后到底选了什么"唯一验得到的路径。
 *
 * ## 几何
 *
 * 6 行 × 40 + 表头 32，视口 320×240 ⇒ 行区 208（纵向装得下，测的是行定位与
 * 区间，不是滚动）。两列：id 固定 60、姓名 flex。
 *
 * @internal 测试夹具
 */
final class TableDragSelectApplication extends Application
{
    public function __construct(
        private readonly int $rows = 6,
        private readonly TableSelectionMode $mode = TableSelectionMode::Multiple,
    ) {
        parent::__construct();
    }

    public function buildRoot(): Widget
    {
        return new TableDragSelectHost($this->rows, $this->mode);
    }

    /** 根 `State`（受控值 / 上报记录都住那里） */
    public function host(): ?TableDragSelectHostState
    {
        $root = $this->root();
        if (!$root instanceof StatefulElement) {
            return null;
        }

        $state = $root->state();

        return $state instanceof TableDragSelectHostState ? $state : null;
    }
}

/** @internal 测试夹具 */
final class TableDragSelectHost extends StatefulWidget
{
    public function __construct(
        private readonly int $rows,
        private readonly TableSelectionMode $mode,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function createState(): State
    {
        return new TableDragSelectHostState($this->rows, $this->mode);
    }
}

/** @internal 测试夹具 */
final class TableDragSelectHostState extends State
{
    /** 当前选中集（受控值，应用自己持有） */
    public TableSelection $selection;

    /** 收到过几次 `onSelectionChange` —— "拖动中不逐帧上报"靠它验 */
    public int $notifyCount = 0;

    /** 最后一次拿到的选中集 */
    public ?TableSelection $last = null;

    public function __construct(
        private readonly int $rows,
        private readonly TableSelectionMode $mode,
    ) {
        $this->selection = TableSelection::none();
    }

    public function build(BuildContext $ctx): Widget
    {
        $rows = [];
        for ($i = 1; $i <= $this->rows; $i++) {
            $rows[] = ['id' => $i, 'name' => '用户 ' . $i];
        }

        return Table::make(
            [
                TableColumn::make('id', 'ID')
                    ->field('id')
                    ->width(TableColumnWidth::fixed(60.0)),
                TableColumn::make('name', '姓名')
                    ->field('name'),
            ],
            $rows,
        )->keyOf(static fn(array $row): int => (int) $row['id'])
            ->rowHeight(40.0)
            ->headerHeight(32.0)
            ->selection($this->selection)
            ->selectionMode($this->mode)
            ->onSelectionChange(function (TableSelection $next): void {
                $this->notifyCount++;
                $this->last = $next;

                $this->setState(function () use ($next): void {
                    $this->selection = $next;
                });
            });
    }
}
