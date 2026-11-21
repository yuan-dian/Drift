<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\State\Equatable;

/**
 * 表格的选中集（**不可变值对象**）—— 只装"选中了什么"，不装 UI 态。
 *
 * 唯一存储是选区列表（`list<TableRange>`）：整行选区 = 列端点为 `null` 的 range，
 * 格矩形 = 带列端点的 range。两种行为共用一份存储、一套 API、一个回调。
 *
 * ★ **每次变更返回新实例**：就地改会让同一份对象两次 build 间变了内容 ——
 *   那种 bug 只能靠肉眼发现。
 * ★ **判"变了没"问 `equals()`，不是 `===`**：`Cell<TableSelection>` 的门控按内容比，
 *   同一片选区上报两次（新实例、同内容）不该触发重建。`===` 只答"是不是同一个
 *   实例"，拿它当 diff 会让每次点行都白重建一次。
 *
 * ★ **键的类型保持原样**：PHP 数组会把数字字符串键静默转成 int（`['1'=>true]` 的
 *   键是 int 1）⇒ 内部按 `i:`/`s:` 前缀归一化，取出时还原原值，否则 `1` 与
 *   `'1'` 会被混为一谈。
 *
 * @api
 */
final readonly class TableSelection implements Equatable
{
    /**
     * @param list<TableRange> $ranges
     */
    private function __construct(private array $ranges)
    {
    }

    /** 一个空的选中集。 */
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
     * @param iterable<string|int> $keys
     */
    public function only(iterable $keys): self
    {
        return self::of($keys);
    }

    /**
     * 选中的行 key（顺序 = 加入顺序，**去重**）。
     *
     * 格矩形选区下这是"被碰到的行"；要拿格子本身用 `covers()` / `ranges()`。
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
     * 选中内容是否相同（**不是**实例身份）—— `Cell<TableSelection>` 的门控用它。
     *
     * ★ 选区顺序参与判等：`[1,2]` 与 `[2,1]` 在界面上是同一次选择，但追加顺序
     *   记录了用户的操作路径（`ranges` 的顺序就是 Shift 连选的方向），不该被抹平。
     */
    public function equals(Equatable $other): bool
    {
        if (!$other instanceof self) {
            return false;
        }

        $mine = $this->ranges;
        $theirs = $other->ranges;
        if (\count($mine) !== \count($theirs)) {
            return false;
        }

        foreach ($mine as $i => $range) {
            if (!$range->equals($theirs[$i])) {
                return false;
            }
        }

        return true;
    }

    /** 内部归一化键（`i:`/`s:` 前缀区分 int 与 string 键） */
    private static function norm(string|int $key): string
    {
        return \is_int($key) ? 'i:' . $key : 's:' . $key;
    }
}
