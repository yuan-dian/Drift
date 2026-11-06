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
 * ## 三态转移：点表头 vs Shift+点表头
 *
 * `cycle()` 是单键（无 → 升 → 降 → 无），`cycleMulti()` 是 Shift 路径：其它键原样
 * 保留，只循环被点的那一列（未参与 ⇒ 追加到最低优先级升序，升 → 降 → 移除）。
 * 两条 UI 入口（指针 / 键盘）共用它们，不会各写一份三态而分叉。
 *
 * ★ `thenBy()` 不能拿来实现 Shift 路径：它把已存在的键**移到末尾**，用它循环主键
 *   的方向会把主键降成次键。两者是"我要加个次键"与"我只改这一列的方向"两种意图。
 * ★ `directionOf()` 对任何参与键都返回方向 ⇒ 画箭头的代码对多键天然正确。
 * ★ 应用侧消费多键要按 `keys()` 的**顺序**组比较器链（主键优先、相等才比次键），
 *   只读 `primaryDirection()` 会让画面上两列有箭头、数据却只按一列排。
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
            Errors::report('TableSort::by() 的列 key 不能为空 —— 它是列的 `TableColumn::keyName()`，不是"没排序"的另一种写法。修法：不排序请用 TableSort::none()。');
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
            Errors::report('TableSort::thenBy() 的列 key 不能为空 —— 它是列的 `TableColumn::keyName()`，不是"没排序"的另一种写法。修法：不排序请用 TableSort::none()。');
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

    /**
     * 点表头：无 → 升 → 降 → 无（ag-Grid / WPF DataGrid 同款）。
     *
     * ★ 点非主键列（含"它是次键"）⇒ 升格为主键升序，而不是在次键上循环：
     *   否则"点一下次键列，箭头出现在主键列上"会显得毫无反应。
     */
    public function cycle(string $column): self
    {
        $dir = $this->directionOf($column);

        if ($dir === null || $this->primaryColumn() !== $column) {
            return self::by($column);
        }

        if ($dir === TableSortDirection::Ascending) {
            return self::by($column, TableSortDirection::Descending);
        }

        return self::none();
    }

    /**
     * Shift+点表头：其它键与优先级都不动，只循环这一列。
     *
     * ★ 已参与的列**留在原位**切方向（升 → 降 → 移除）：把它移走会让"只想改方向"
     *   意外换掉主键。这一点正是 `thenBy()` 不能复用的原因。
     */
    public function cycleMulti(string $column): self
    {
        if ($column === '') {
            Errors::report('TableSort::cycleMulti() 的列 key 不能为空 —— 它是列的 `TableColumn::keyName()`。');
        }

        $next = [];
        $found = false;

        foreach ($this->keys as [$key, $direction]) {
            if ($key !== $column) {
                $next[] = [$key, $direction];
                continue;
            }

            $found = true;
            if ($direction === TableSortDirection::Ascending) {
                $next[] = [$key, TableSortDirection::Descending];
            }
        }

        if (!$found) {
            $next[] = [$column, TableSortDirection::Ascending];
        }

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
