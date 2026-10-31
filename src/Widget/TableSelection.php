<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 表格的选中集（**不可变值对象**）—— 只装"选中了什么"，不装 UI 态。
 *
 * ```php
 * // 应用持有，每次 build 递进来；点击只回调新值（受控，`docs/DESIGN-TABLE-2026-10-26.md` §11.4）
 * $this->selection = TableSelection::none();
 * Table::make($cols, $rows)->selection($this->selection)
 *     ->onSelectionChange(fn(TableSelection $next) => $this->setState(fn() => $this->selection = $next));
 * ```
 *
 * ★ **为什么受控**：批量删除 / 导出 / "已选 3 项"全都需要应用**读到**选中了谁。
 *   `State` 由 `StatefulElement::mount()` 内部造、外部拿不到引用（本仓也没有
 *   `GlobalKey`）⇒ 选择住 State 等于"应用永远读不到"。与 `Select::value()` /
 *   `Checkbox::checked()` 同一哲学。
 *
 * ★ **每次变更返回新实例**：`===` 就是"选择变了没"的判据（`SubjectBuilder` 的门控
 *   与 `StatefulWidget` 的 diff 都靠它）。就地改会让"同一份对象"在两次 build 之间
 *   变了内容 —— 那种 bug 只能靠肉眼发现。
 *
 * ★ **key 的类型保持原样**：`keyOf()` 的返回类型是 `string|int`，而 PHP 数组会把
 *   数字字符串键**静默转成 int**（`['1' => true]` 的键是 int 1）⇒ 直接用它当 map
 *   会让 `1` 与 `'1'` 混为一谈（应用里"用户 id 是字符串"与"是整数"完全可能是两张
 *   表）。所以内部按 `i:` / `s:` 前缀归一化，取出时还原原值。
 *
 * ## 唯一存储：**选区列表**（`TableRange`），行集是它的投影（2026-10-31）
 *
 * 以前内部就是"行 key 的集合"，于是"格级矩形选区"没法表达 —— `DESIGN-TABLE`
 * §19.9 把这条记成"与『选中集 = 行 key 集』的语义分叉"。现在存储换成
 * `list<TableRange>`：**整行选区 = 列端点为 `null` 的 range**，格矩形 = 带列端点的
 * range。两种行为共用一份存储、一套 API、一个回调 —— 不是两套。
 *
 * ★ **行级 API 全部保留且语义不变**（`has` / `keys` / `count` / `toggle` / `add` /
 *   `remove` / `only` / `withAll` / `of`）：它们作用在"整行"这一层，对格矩形选区
 *   的含义是"这一行有没有被碰到"。`keys()` 的顺序仍是加入顺序 —— 行在 `TableRange`
 *   里本来就是摊平的列表，顺序天然保留。
 *
 * @api
 */
final readonly class TableSelection
{
    /**
     * @param list<TableRange> $ranges
     */
    private function __construct(private array $ranges)
    {
    }

    /**
     * 一个空的选中集。
     *
     * ★ 不用 `new TableSelection([])`（构造是私有的）：`none()` 读起来就是"什么都没选"，
     *   而 `new (...)` 会让读者以为还有别的空构造形态。
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * 从 key 列表构造（**整行**选区 —— Rows 行为下的一切都走它）。
     *
     * @param iterable<string|int> $keys
     */
    public static function of(iterable $keys): self
    {
        // ★ 去重放在这里（不是 `keys()` 里）：`of(['a','a'])` 与 `of(['a'])` 必须
        //   是同一份选中集，否则 `count()` 对、`ranges()[0]->rows` 不对 —— 两处
        //   说法不一致比两处都错更难查。
        $seen = [];
        $rows = [];
        foreach ($keys as $key) {
            $norm = self::norm($key);
            if (isset($seen[$norm])) {
                continue;
            }

            $seen[$norm] = true;
            $rows[] = $key;
        }

        return $rows === [] ? new self([]) : new self([new TableRange($rows)]);
    }

    /**
     * 由**一个格矩形**构造（Cells 行为）。
     *
     * @param list<string|int> $rows
     * @param list<string> $cols
     */
    public static function ofCells(array $rows, array $cols): self
    {
        if ($rows === [] || $cols === []) {
            return new self([]);
        }

        return new self([new TableRange($rows, $cols)]);
    }

    /**
     * 并入一块选区。
     *
     * @param bool $replace `true` = 替换掉现有的全部选区（Shift 扩选的语义：
     *   "从锚点到目标"是**替换**而不是累加 —— 累加会让"缩小区间"永远做不到）
     */
    public function withRange(TableRange $range, bool $replace = true): self
    {
        if ($range->isEmpty()) {
            return $replace ? self::none() : $this;
        }

        if ($replace) {
            return new self([$range]);
        }

        $ranges = $this->ranges;
        $ranges[] = $range;

        return new self($ranges);
    }

    /** @return list<TableRange> */
    public function ranges(): array
    {
        return $this->ranges;
    }

    /** 这一**行**有没有被碰到（整行选区看行；格矩形看"行在不在矩形里"） */
    public function has(string|int $key): bool
    {
        foreach ($this->ranges as $range) {
            if ($range->coversRow($key)) {
                return true;
            }
        }

        return false;
    }

    /** 这一**格**在不在选区里（Rows 行为下等价于 `has($rowKey)`） */
    public function covers(string|int $rowKey, string $colKey): bool
    {
        foreach ($this->ranges as $range) {
            if ($range->covers($rowKey, $colKey)) {
                return true;
            }
        }

        return false;
    }

    /** 反选一行：已选 ⇒ 去掉；没选 ⇒ 加上（Rows 行为的 `Ctrl+点击`） */
    public function toggle(string|int $key): self
    {
        if ($this->has($key)) {
            return $this->remove($key);
        }

        return $this->add($key);
    }

    public function add(string|int $key): self
    {
        if ($this->has($key)) {
            return $this;
        }

        $ranges = $this->ranges;
        $ranges[] = new TableRange([$key]);

        return new self($ranges);
    }

    public function remove(string|int $key): self
    {
        if (!$this->has($key)) {
            return $this;
        }

        $ranges = [];
        foreach ($this->ranges as $range) {
            if (!$range->coversRow($key)) {
                $ranges[] = $range;
                continue;
            }

            // 整行 / 单列宽：整条剔掉；多列矩形：剔掉那一行，列端点保留
            // （矩形减一行仍是矩形，这是唯一能保持矩形形状的减法）
            $rows = [];
            foreach ($range->rows as $each) {
                if ($each !== $key) {
                    $rows[] = $each;
                }
            }

            if ($rows !== []) {
                $ranges[] = new TableRange($rows, $range->cols);
            }
        }

        return new self($ranges);
    }

    /**
     * 并入一批 key（Shift 连选的区间用它 —— 区间由 `TableGridPicker` 按行序算出来）。
     *
     * @param iterable<string|int> $keys
     */
    public function withAll(iterable $keys): self
    {
        $fresh = [];
        foreach ($keys as $key) {
            if (!$this->has($key)) {
                $fresh[] = $key;
            }
        }

        if ($fresh === []) {
            return $this;
        }

        $ranges = $this->ranges;
        $ranges[] = new TableRange($fresh);

        return new self($ranges);
    }

    /**
     * 只剩这几个 key（单选 / "替换选择"用它）。
     *
     * ★ 与 `of()` 的区别：`of()` 是"从外部数据造一份新的"，`only()` 是"在当前对象上
     *   换内容"。语义上等价、实现上也等价，但对调用方读起来不同 —— 替换选择时
     *   写 `$sel->only($key)` 比 `TableSelection::of([$key])` 更直白。
     *
     * @param iterable<string|int> $keys
     */
    public function only(iterable $keys): self
    {
        return self::of($keys);
    }

    /**
     * 选中的行 key（顺序 = 加入顺序，**去重**）。
     *
     * ★ 格矩形选区下这是"被碰到的行"（矩形覆盖到的每一行）—— 想拿格子本身用
     *   `covers()` / `ranges()`。
     *
     * @return list<string|int>
     */
    public function keys(): array
    {
        $seen = [];
        $keys = [];
        foreach ($this->ranges as $range) {
            foreach ($range->rows as $key) {
                $norm = self::norm($key);
                if (isset($seen[$norm])) {
                    continue;
                }

                $seen[$norm] = true;
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /** 选中的**行数**（格矩形 = 覆盖到的行数，不是格数） */
    public function count(): int
    {
        return \count($this->keys());
    }

    public function isEmpty(): bool
    {
        foreach ($this->ranges as $range) {
            if (!$range->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    /**
     * 内部归一化键。
     *
     * ★ `s:` 前缀不能省：`['1' => x]` 会变成 int 键，`gettype()` 判不出来。
     */
    private static function norm(string|int $key): string
    {
        return \is_int($key) ? 'i:' . $key : 's:' . $key;
    }
}
