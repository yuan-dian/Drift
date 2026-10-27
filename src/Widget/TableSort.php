<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;

/**
 * 表格的排序状态（**不可变值对象**）—— 只装"按哪几列、什么方向"，不装数据。
 *
 * ```php
 * // 应用持有，每次 build 递进来；点表头只回调新值（受控，§13）
 * $this->sort = TableSort::none();
 * Table::make($cols, $rows)->sort($this->sort)
 *     ->onSortChange(function (TableSort $next) {
 *         $rows = $this->rows;
 *         $dir = $next->primaryDirection();
 *         if ($dir !== null) {
 *             usort($rows, fn($a, $b) => $dir === TableSortDirection::Ascending
 *                 ? $a['age'] <=> $b['age']
 *                 : $b['age'] <=> $a['age']);
 *         }
 *         $this->setState(fn() => [$this->sort, $this->visible] = [$next, $rows]);
 *     });
 * ```
 *
 * ## 为什么控件**不排序**
 *
 * `$row` 是任意值（数组 / 对象 / 派生值），控件不知道哪一列该按什么比 —— 数字按
 * 数值、日期按时间戳、中文按拼音、空值排前还是排后，全是应用侧的知识。
 * 控件只做三件事：**画箭头、点击表头派发意图、三态循环**（无 → 升 → 降 → 无）。
 * 与"选择受控"同一哲学（§11.4）：**数据所有权不分裂**。
 *
 * ★ 反过来（控件内排序）看着更省事，但有两处硬伤：① 得在 State 里缓存排序结果，
 *   而"行数组变了没"只能靠深比较判（10000 行每次 build 一次深比较 = 把虚拟化省的
 *   钱原样花掉）；② 应用手里的数据仍是原序，导出 / 批量操作会与眼前顺序不一致。
 *
 * ## 多键在**结构上**已经成立，只是阶段 3 的 UI 只上报单键
 *
 * `thenBy()` 是次键，`directionOf()` 对任何参与键都返回方向 ⇒ 画箭头的代码对
 * 多键天然正确（每列画自己的箭头）。将来做 Shift+点表头，只改 UI 层的一行上报，
 * **不动本类的签名** —— 这就是"一次把 API 形状定死"的收益。
 *
 * ★ 每次变更返回新实例（同 `TableSelection`）：就地改会让"同一份对象"在两次
 *   build 之间变了内容，那种 bug 只能靠肉眼发现。
 *
 * @api
 */
final readonly class TableSort
{
    /**
     * @param list<array{0: string, 1: TableSortDirection}> $keys 顺序 = 优先级
     */
    private function __construct(private array $keys)
    {
    }

    /** 没有排序（表格按应用给的原始顺序显示） */
    public static function none(): self
    {
        return new self([]);
    }

    /** 按一列排序（会替换掉原有的全部键） */
    public static function by(string $column, TableSortDirection $dir = TableSortDirection::Ascending): self
    {
        if ($column === '') {
            Errors::report('TableSort::by() 的列 key 不能为空 —— 它是列的 `TableColumn::keyName()`');
        }

        return new self([[$column, $dir]]);
    }

    /**
     * 追加（或改写）一个次键，**排在最后**。
     *
     * ★ 已存在的列会被移到末尾并换成新方向 —— 而不是留在原位：调用方写
     *   `->thenBy('name')` 的意思是"主键相同时再按 name"，若 name 原本是主键，
     *   留在原位等于把主键换成它，与调用意图相反。
     */
    public function thenBy(string $column, TableSortDirection $dir = TableSortDirection::Ascending): self
    {
        if ($column === '') {
            Errors::report('TableSort::thenBy() 的列 key 不能为空 —— 它是列的 `TableColumn::keyName()`');
        }

        $next = [];
        foreach ($this->keys as [$key, $direction]) {
            if ($key !== $column) {
                $next[] = [$key, $direction];
            }
        }

        $next[] = [$column, $dir];

        return new self($next);
    }

    /** 这一列参与排序吗（`null` = 不参与 —— 表头不画箭头） */
    public function directionOf(string $column): ?TableSortDirection
    {
        foreach ($this->keys as [$key, $direction]) {
            if ($key === $column) {
                return $direction;
            }
        }

        return null;
    }

    /** 主键列（`null` = 没有排序） */
    public function primaryColumn(): ?string
    {
        return $this->keys[0][0] ?? null;
    }

    public function primaryDirection(): ?TableSortDirection
    {
        return $this->keys[0][1] ?? null;
    }

    public function isEmpty(): bool
    {
        return $this->keys === [];
    }

    /**
     * 参与排序的键（顺序 = 优先级）。
     *
     * @return list<array{0: string, 1: TableSortDirection}>
     */
    public function keys(): array
    {
        return $this->keys;
    }
}
