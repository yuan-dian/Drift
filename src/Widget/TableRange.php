<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 一块**选区**：行 key 列表 + 可选的列 key 列表（`null` = 整行）。
 *
 * ```php
 * new TableRange(['u1', 'u2', 'u3']);                     // 整行（Rows 行为）
 * new TableRange(['u1', 'u2'], ['name', 'age']);          // 2×2 的格矩形（Cells 行为）
 * ```
 *
 * ## 为什么行 / 列都存**key**，而不是下标
 *
 * 排序 / 过滤之后行下标会整体漂移，而"选中的是哪几条数据"不该跟着漂 ——
 * 这是 `TableRowPicker` 早就定下的纪律（锚点存 key）。列同理：列可以重排、
 * 可以按 `visible` 隐藏，`TableColumn` 的 `colKey` 才是稳定身份。
 *
 * ## 为什么行是**摊平的 key 列表**而不是"起止两个 key"
 *
 * 起止两点要靠**当前行序**才能展开成"中间有哪些行"，而 `TableSelection` 是
 * 交给应用的值对象（应用手里未必有当时的行序，而且行序可能已经变了）。
 * 摊平之后：`keys()` 不需要任何外部信息，`===` 比较也是纯值比较。
 * 代价是内存 —— 与既有 `TableSelection::withAll()` 同量级（它本来就摊平）。
 *
 * @api
 */
final readonly class TableRange
{
    /**
     * @param list<string|int> $rows 行 key（摊平，顺序 = 选区建立时的行序）
     * @param ?list<string> $cols 列 key；`null` = **整行**（所有列，含将来新增的列）
     */
    public function __construct(
        public array $rows,
        public ?array $cols = null,
    ) {
    }

    /** 整行选区（Rows 行为的唯一形态） */
    public function isWholeRow(): bool
    {
        return $this->cols === null;
    }

    public function rowCount(): int
    {
        return \count($this->rows);
    }

    public function isEmpty(): bool
    {
        return $this->rows === [] || $this->cols === [];
    }

    /**
     * 这一行在不在选区里。
     *
     * ★ 用 `===` 逐项比而不是 `in_array(..., true)`：后者对 `7` 与 `'7'` 是
     *   类型严格的，语义上正确，但这里要遍历的通常是 1~3 个元素，手写循环
     *   顺便把"key 类型混用"这条在 `TableSelection::norm()` 里已经处理过的
     *   问题再挡一次。
     */
    public function coversRow(string|int $rowKey): bool
    {
        foreach ($this->rows as $each) {
            if ($each === $rowKey) {
                return true;
            }
        }

        return false;
    }

    /** 这一**格**在不在选区里（整行选区恒看行；列选区还要列 key 命中） */
    public function covers(string|int $rowKey, string $colKey): bool
    {
        if (!$this->coversRow($rowKey)) {
            return false;
        }

        if ($this->cols === null) {
            return true;
        }

        foreach ($this->cols as $each) {
            if ($each === $colKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * 剔除一格（`Ctrl+点击` 在 Cells 行为下用它）。
     *
     * ★ 只处理"整行"与"单列宽"两种情形 —— 多列矩形里挖一个洞会让选区变成
     *   非矩形（要拆成 4 块），而 `Ctrl+点击` 在 Excel 里的语义本来就是
     *   **累加**而不是反选。挖不动就原样返回，不报错：调用方拿到同一实例
     *   自然跳过通知。
     */
    public function withoutCell(string|int $rowKey, string $colKey): self
    {
        if (!$this->covers($rowKey, $colKey)) {
            return $this;
        }

        // 整行：删这一行（整行选区里"删一格"等于删这一行，没有别的解释）
        if ($this->cols === null) {
            return new self(self::drop($this->rows, $rowKey), null);
        }

        // 单列宽：删这一行
        if (\count($this->cols) === 1) {
            return new self(self::drop($this->rows, $rowKey), $this->cols);
        }

        return $this;
    }

    /**
     * @param list<string|int> $rows
     * @return list<string|int>
     */
    private static function drop(array $rows, string|int $key): array
    {
        $next = [];
        foreach ($rows as $each) {
            if ($each !== $key) {
                $next[] = $each;
            }
        }

        return $next;
    }

    /** 值比较（`TableSelection` 的 `===` 判据靠"每次变更换新实例"，这里只给测试用） */
    public function equals(self $other): bool
    {
        return $this->rows === $other->rows && $this->cols === $other->cols;
    }
}
