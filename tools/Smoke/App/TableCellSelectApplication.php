<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\StatefulElement;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use Drift\Widget\TableColumnWidth;
use Drift\Widget\TableSelection;
use Drift\Widget\TableSelectionBehavior;
use Drift\Widget\TableSelectionMode;
use Drift\Widget\Widget;

/**
 * **格级矩形选区**的真实路由夹具（2026-10-31）。
 *
 * 与 `TableDragSelectApplication`（行级）形状相同，只多两样：
 * `selectionBehavior(Cells)` 与 `onCopy()`（记录 TSV，不碰真剪贴板）。
 *
 * ## 几何
 *
 * 6 行 × 40 + 表头 32，视口 320×240 ⇒ 行区 208。两列：`id` 固定 60、`name` flex(260)。
 * 所以列中心 x：`id` = 30、`name` = 190；行 i 中心 y = 32 + 40i + 20。
 *
 * @internal 测试夹具
 */
final class TableCellSelectApplication extends Application
{
    public function __construct(
        private readonly int $rows = 6,
        private readonly TableSelectionBehavior $behavior = TableSelectionBehavior::Cells,
        private readonly TableSelectionMode $mode = TableSelectionMode::Multiple,
    ) {
        parent::__construct();
    }

    public function buildRoot(): Widget
    {
        return new TableCellSelectHost($this->rows, $this->behavior, $this->mode);
    }

    public function host(): ?TableCellSelectHostState
    {
        $root = $this->root();
        if (!$root instanceof StatefulElement) {
            return null;
        }

        $state = $root->state();

        return $state instanceof TableCellSelectHostState ? $state : null;
    }
}

/** @internal 测试夹具 */
final class TableCellSelectHost extends StatefulWidget
{
    public function __construct(
        private readonly int $rows,
        private readonly TableSelectionBehavior $behavior,
        private readonly TableSelectionMode $mode,
    ) {
        parent::__construct();
    }

    #[\Override]
    public function createState(): State
    {
        return new TableCellSelectHostState($this->rows, $this->behavior, $this->mode);
    }
}

/** @internal 测试夹具 */
final class TableCellSelectHostState extends State
{
    public TableSelection $selection;

    public int $notifyCount = 0;

    public ?TableSelection $last = null;

    /** `Ctrl+C` 交出来的 TSV（`null` = 还没复制过） */
    public ?string $copied = null;

    public function __construct(
        private readonly int $rows,
        private readonly TableSelectionBehavior $behavior,
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
            ->selectionBehavior($this->behavior)
            ->onSelectionChange(function (TableSelection $next): void {
                $this->notifyCount++;
                $this->last = $next;

                $this->setState(function () use ($next): void {
                    $this->selection = $next;
                });
            })
            ->onCopy(function (string $tsv): void {
                $this->copied = $tsv;
            });
    }
}
