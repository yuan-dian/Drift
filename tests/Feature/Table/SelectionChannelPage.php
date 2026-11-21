<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\State\Cell;
use Drift\Widget\BuildContext;
use Drift\Widget\Page;
use Drift\Widget\Table;
use Drift\Widget\TableColumn;
use Drift\Widget\TableColumnWidth;
use Drift\Widget\TableSelection;
use Drift\Widget\Widget;

/**
 * 最小"页面 + 表格"—— 用来分辨选中集的两种形态各自由谁重建。
 *
 * ★ 页面 `build()` 在通道形态下**不读** `$selection`：读了就等于页面也订阅，
 *   点一次行整页重建，两种形态的差别就测不出来。值形态那一支读它，正是它
 *   必须付的价（每次选中变化都整页重建）。
 */
final class SelectionChannelPage extends Page
{
    /** 整页 `build()` 跑了几次 —— 两种形态的差别全在这一个数上 */
    public int $builds = 0;

    /** 最近一次 build 造出来的表（测试用它读"表格看到的是哪份选中集"） */
    public ?Table $table = null;

    public Cell $selection;

    public function __construct(private readonly bool $channel)
    {
    }

    public function initState(): void
    {
        $this->selection = Cell::of(TableSelection::none())->named('test.selection');
    }

    public function build(BuildContext $ctx): Widget
    {
        ++$this->builds;

        $table = Table::make(self::columns(), Fixtures::rows(50))
            ->keyOf(static fn(array $row): int => $row['id'])
            ->rowHeight(Fixtures::ROW_H)
            ->headerHeight(Fixtures::HEADER_H)
            ->onSelectionChange(function (TableSelection $next): void {
                $this->selection->set($next);
            });

        if ($this->channel) {
            $table->selectionFrom($this->selection);
        } else {
            $table->selection($this->selection->get());
        }

        return $this->table = $table;
    }

    /** @return list<TableColumn> */
    private static function columns(): array
    {
        return [
            TableColumn::make('id', 'ID')->field('id')->width(TableColumnWidth::fixed(60.0)),
            TableColumn::make('name', '姓名')->field('name')->width(TableColumnWidth::flex(2.0)),
            TableColumn::make('city', '城市')->field('city')->width(TableColumnWidth::flex(1.0)),
        ];
    }
}
