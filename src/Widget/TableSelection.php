<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 表格的选中集（**不可变值对象**）—— 只装"哪些行被选中"，不装 UI 态。
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
 * @api
 */
final readonly class TableSelection
{
    /**
     * @param array<string, string|int> $byNorm 归一化键 ⇒ 原始 key
     */
    private function __construct(private array $byNorm)
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
     * 从 key 列表构造。
     *
     * @param iterable<string|int> $keys
     */
    public static function of(iterable $keys): self
    {
        $byNorm = [];
        foreach ($keys as $key) {
            $byNorm[self::norm($key)] = $key;
        }

        return new self($byNorm);
    }

    /** 选中了没有（O(1)） */
    public function has(string|int $key): bool
    {
        return isset($this->byNorm[self::norm($key)]);
    }

    /** 反选：已选 ⇒ 去掉；没选 ⇒ 加上 */
    public function toggle(string|int $key): self
    {
        $norm = self::norm($key);
        $next = $this->byNorm;
        if (isset($next[$norm])) {
            unset($next[$norm]);
        } else {
            $next[$norm] = $key;
        }

        return new self($next);
    }

    public function add(string|int $key): self
    {
        if (isset($this->byNorm[self::norm($key)])) {
            return $this;
        }

        $next = $this->byNorm;
        $next[self::norm($key)] = $key;

        return new self($next);
    }

    public function remove(string|int $key): self
    {
        if (!isset($this->byNorm[self::norm($key)])) {
            return $this;
        }

        $next = $this->byNorm;
        unset($next[self::norm($key)]);

        return new self($next);
    }

    /**
     * 并入一批 key（Shift 连选的区间用它 —— 区间由 `TableState` 按行序算出来）。
     *
     * @param iterable<string|int> $keys
     */
    public function withAll(iterable $keys): self
    {
        $next = $this->byNorm;
        $changed = false;
        foreach ($keys as $key) {
            $norm = self::norm($key);
            if (!isset($next[$norm])) {
                $next[$norm] = $key;
                $changed = true;
            }
        }

        return $changed ? new self($next) : $this;
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
     * 选中的 key（顺序 = 加入顺序）。
     *
     * @return list<string|int>
     */
    public function keys(): array
    {
        return \array_values($this->byNorm);
    }

    public function count(): int
    {
        return \count($this->byNorm);
    }

    public function isEmpty(): bool
    {
        return $this->byNorm === [];
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
