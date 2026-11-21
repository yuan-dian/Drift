<?php
declare(strict_types=1);

namespace App\Showcase\Models;

use Drift\Widget\TableSort;
use Drift\Widget\TableSortDirection;

/**
 * 用户目录的数据与排序规则 —— **不挂树、不碰 Widget 的纯逻辑**。
 *
 * ★ 存在的理由：这些规则原先住在 `TablePage` 里，于是"主键相等才轮到次键"
 *   这种判据只能靠反射进页面私有方法来验（`MultiSortTest` 真那么干过）。
 *   逻辑在页面里 ⇒ 测试必须知道页面的内部形状 ⇒ 页面一改测试就碎。
 *   搬到这里之后，测试直接 `new` 一个目录对象。
 *
 * ★ **比较器属于应用，不属于表格控件**：控件不知道 `name` 是中文还是日期，
 *   更不知道「状态」这一列是算出来的（见 {@see isDisabled()}）。所以排序
 *   规则必须留在业务侧 —— 这也是 `Table` 只回调"应该按什么排"的原因。
 *
 * ★ 依赖 `TableSort` 是有意的：它是**排序描述值对象**（键 + 方向），不是
 *   渲染类型。自己再造一个同形类只会让页面多一层翻译。
 */
final class UserDirectory
{
    public const CITIES = ['北京', '上海', '广州', '深圳', '杭州'];

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(private array $rows)
    {
    }

    /**
     * 造 `$count` 行确定性数据 —— 像素基线依赖"同样的 count 出同样的行"。
     */
    public static function seed(int $count): self
    {
        $rows = [];
        for ($i = 1; $i <= $count; $i++) {
            $rows[] = [
                'id' => $i,
                'name' => \sprintf('用户 %d', $i),
                'email' => \sprintf('user%d@example.com', $i),
                'city' => self::CITIES[$i % 5],
            ];
        }

        return new self($rows);
    }

    /**
     * 派生列「状态」的唯一判据 —— 列渲染与排序**共用**它。
     *
     * ★ 两处各写一遍就会漂移：画面上写"停用"而行序按另一条规则排，是那种
     *   只能靠肉眼发现的错。放在这里 ⇒ 一处定义，两处引用。
     */
    public static function isDisabled(int $id): bool
    {
        return $id % 3 === 0;
    }

    /** @return list<array<string, mixed>> */
    public function rows(): array
    {
        return $this->rows;
    }

    /**
     * 按 `$sort` 重排**一份副本**；没有有效键 ⇒ 原序。
     *
     * ★ 返回原数组（不是副本）在"取消排序"时是安全的：PHP 数组按值传，
     *   调用方拿到的是同一份写时复制视图，改不到这里。
     */
    public function sortedBy(TableSort $sort): array
    {
        $comparators = self::comparators();
        $chain = [];

        foreach ($sort->keys() as [$key, $dir]) {
            if (isset($comparators[$key])) {
                $chain[] = [$comparators[$key], $dir === TableSortDirection::Ascending ? 1 : -1];
            }
        }

        if ($chain === []) {
            return $this->rows;
        }

        return self::mergeSort($this->rows, $chain);
    }

    /** 写回一格；`false` = 没有这一行（页面据此决定要不要发变更信号） */
    public function update(int $id, string $column, string $value): bool
    {
        foreach ($this->rows as $index => $row) {
            if ((int) $row['id'] === $id) {
                $this->rows[$index][$column] = $value;

                return true;
            }
        }

        return false;
    }

    /**
     * 列 key ⇒ 比较规则。
     *
     * ★ 按**列 key** 索引（不是列序号）：`TableSort::keys()` 给的就是 key，
     *   插一列不会让整张比较器表错位。
     * ★ 一律用字面量键取字段（`$a['id']` 而非 `$a[$key]`）：动态键取出来是
     *   `mixed`，比较会退化成混合类型比较。
     * ★ 中文按 UTF-8 字节序（`strcmp`），不是拼音序 —— 拼音序要的 `collator`
     *   同样是应用侧知识，不该由框架猜。
     *
     * @return array<string, callable(array<string, mixed>, array<string, mixed>): int>
     */
    private static function comparators(): array
    {
        return [
            'id' => static fn(array $a, array $b): int => (int) $a['id'] <=> (int) $b['id'],
            'name' => static fn(array $a, array $b): int => \strcmp((string) $a['name'], (string) $b['name']),
            'email' => static fn(array $a, array $b): int => \strcmp((string) $a['email'], (string) $b['email']),
            'city' => static fn(array $a, array $b): int => \strcmp((string) $a['city'], (string) $b['city']),
            'status' => static fn(array $a, array $b): int => (int) self::isDisabled((int) $a['id'])
                <=> (int) self::isDisabled((int) $b['id']),
        ];
    }

    /**
     * 稳定归并排序 —— **不用 `\usort`**，为的是稳定。
     *
     * ★ 按「状态」排时同组内部必须保持原顺序，否则点一次表头整张表就"抖"一下。
     *   `\usort` 自 PHP 8 起稳定，但 AOT 产物里的 sort 由运行时自己实现
     *   （`std::sort` 这类不稳定算法很常见）—— 表格不能赌这一条。
     * ★ 一次排序 = 一次性操作（点表头），临时数组的开销可以接受；每帧不排序。
     *
     * @param list<array<string, mixed>> $rows
     * @param list<array{0: callable(array<string, mixed>, array<string, mixed>): int, 1: int}> $chain
     * @return list<array<string, mixed>>
     */
    private static function mergeSort(array $rows, array $chain): array
    {
        $n = \count($rows);
        if ($n < 2) {
            return $rows;
        }

        $mid = \intdiv($n, 2);
        $left = self::mergeSort(\array_slice($rows, 0, $mid), $chain);
        $right = self::mergeSort(\array_slice($rows, $mid), $chain);

        $lc = \count($left);
        $rc = \count($right);
        $i = 0;
        $j = 0;
        $out = [];

        while ($i < $lc && $j < $rc) {
            if (self::compareChain($left[$i], $right[$j], $chain) <= 0) {
                $out[] = $left[$i];
                $i++;
            } else {
                $out[] = $right[$j];
                $j++;
            }
        }

        while ($i < $lc) {
            $out[] = $left[$i];
            $i++;
        }

        while ($j < $rc) {
            $out[] = $right[$j];
            $j++;
        }

        return $out;
    }

    /** 主键相等才轮到次键；全相等返回 0，归并的 `<= 0` 保住原始顺序（稳定） */
    private static function compareChain(array $a, array $b, array $chain): int
    {
        foreach ($chain as [$cmp, $sign]) {
            $r = $sign * $cmp($a, $b);
            if ($r !== 0) {
                return $r;
            }
        }

        return 0;
    }
}
