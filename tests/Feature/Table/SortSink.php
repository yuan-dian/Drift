<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Widget\TableSort;

/**
 * 承接"表头上报的排序状态"—— 受控表的应用侧。
 *
 * ★ 同时接 `$index`：`onSortChange` 的第二个参数是**列序号**（应用按"第几列"
 *   分派比较规则时直接当下标用），只接 `TableSort` 会漏掉它。
 */
final class SortSink
{
    public ?TableSort $last = null;

    public int $columnIndex = -1;

    public function receive(TableSort $next, int $columnIndex): void
    {
        $this->last = $next;
        $this->columnIndex = $columnIndex;
    }
}
