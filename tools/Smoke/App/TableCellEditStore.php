<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\State\Cell;

/**
 * 单元格编辑夹具的**业务数据**（零 GUI 依赖，形如真应用的 Store）。
 *
 * ★ 为什么要一个 Store 而不是让 Application 直接持数组：应用改完数据必须让
 *   树重建，而 `Element::markNeedsBuild()` **只对 `StatefulElement` 有效**
 *   （根是 `StatelessElement` ⇒ 调了什么都不发生）。真机上的通道是"写格子 →
 *   `Bind` 的 builder 读到它"，夹具照抄它，否则"提交后格子显示新值"这类断言
 *   验的是假象。
 * ★ 2026-11-24 从 `extends Subject` + `notifyChanged()` 迁到 `Cell`：
 *   手写广播 + 切片订阅那套随 `SubjectBuilder` 一起退役了（A18）。
 *
 * @internal 测试夹具
 */
final class TableCellEditStore
{
    /** @var Cell<list<array{id: int, name: string}>> */
    public readonly Cell $rows;

    public function __construct()
    {
        /** @var Cell<list<array{id: int, name: string}>> $rows */
        $rows = Cell::of([
            ['id' => 1, 'name' => '甲'],
            ['id' => 2, 'name' => '乙'],
            ['id' => 3, 'name' => '丙'],
        ]);
        $this->rows = $rows;
    }

    /** 写一个单元格（行 key + 列名）—— 找不到就静默返回（应用侧行为，不属被测语义） */
    public function setCell(int $rowKey, string $column, string $value): void
    {
        $rows = $this->rows->get();
        foreach ($rows as $index => $row) {
            if ((int) $row['id'] !== $rowKey) {
                continue;
            }

            $rows[$index][$column] = $value;
            $this->rows->set($rows);

            return;
        }
    }

    /** @param list<array{id: int, name: string}> $rows */
    public function setRows(array $rows): void
    {
        $this->rows->set($rows);
    }
}
