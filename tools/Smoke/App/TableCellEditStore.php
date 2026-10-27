<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\State\Subject;

/**
 * 单元格编辑夹具的**业务数据**（`Subject` 子类，零 GUI 依赖）。
 *
 * ★ 为什么要一个 Store 而不是让 Application 直接持数组：应用改完数据必须让
 *   树重建，而 `Element::markNeedsBuild()` **只对 `StatefulElement` 有效**
 *   （根是 `StatelessElement` ⇒ 调了什么都不发生）。真机上的通道就是
 *   `Subject::notifyChanged()` → `SubjectBuilder` 重建 —— 夹具照抄它，
 *   否则"提交后格子显示新值"这类断言验的是假象。
 *
 * @internal 测试夹具
 */
final class TableCellEditStore extends Subject
{
    /** @var list<array{id: int, name: string}> */
    public array $rows = [
        ['id' => 1, 'name' => '甲'],
        ['id' => 2, 'name' => '乙'],
        ['id' => 3, 'name' => '丙'],
    ];

    /** 写一个单元格（行 key + 列名）—— 找不到就静默返回（应用侧行为，不属被测语义） */
    public function setCell(int $rowKey, string $column, string $value): void
    {
        foreach ($this->rows as $index => $row) {
            if ((int) $row['id'] !== $rowKey) {
                continue;
            }

            $this->rows[$index][$column] = $value;
            $this->notifyChanged();

            return;
        }
    }

    /** @param list<array{id: int, name: string}> $rows */
    public function setRows(array $rows): void
    {
        $this->rows = $rows;
        $this->notifyChanged();
    }
}
