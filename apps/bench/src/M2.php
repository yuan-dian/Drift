<?php
declare(strict_types=1);

namespace Drift\Bench;

/**
 * M2 的夹具：`mixed` 属性 vs 类型化属性。
 *
 * 对应 v7 的 `Cell` 分裂决策（`24` §5.1 第 2 条）：一个 `Cell` 的 `value` 是
 * `mixed` ⇒ 读出来是 `php::Var`，用之前必须 `toInt()/toFloat()/toString()` 拆箱；
 * 分裂成 `IntCell`/`FloatCell`/`StrCell` 之后读出来就是原生标量。
 */
final class CellMixed
{
    public mixed $value = 1;
}

final class CellInt
{
    public int $value = 1;
}

final class CellFloat
{
    public float $value = 1.0;
}

/**
 * ★★ **反例夹具**：同一个 `mixed` 属性，值是 **string**。
 *
 * 第一版只有 `CellMixed(int)`，测出 `A / B = 1.00` —— 与 int 直读**完全同价**。
 * 那个 1.00 可能是真的（`toInt()` 内联成一次 tag 检查），也可能是"值恰好是 int，
 * 编译器把它优化成了 int 存储"。两种解释都成立时，**判据不成立** ⇒ 加这一组。
 *
 * 如果 D 明显贵于 A，说明 `mixed` 的开销与**值的类型**有关：int 型 mixed 近乎免费，
 * 非 int 型 mixed 才要真拆箱 —— 那 v7 的 `Cell` 分裂就该按"这个 cell 会不会装
 * 非 int"来决定，而不是"统一分裂"。
 */
final class CellMixedStr
{
    public mixed $value = '42';
}

/**
 * M2：`mixed` 装箱的成本。
 *
 *   A  读 `mixed`（值 int）+ `toInt()`     ← v7 统一 Cell 的形状
 *   B  读 `int` 属性 + 直接相加            ← 分裂成 `IntCell` 之后
 *   C  读 `float` 属性 + 直接相加          ← 分裂成 `FloatCell` 之后
 *   D  读 `mixed`（值 string）+ `toInt()`  ← **反例**：值不是 int 时还免费吗
 *
 * 判据（`25` §3）：`A / B > 5` ⇒ `Cell` 分裂优先（1 周见效）；`≈ int` ⇒ 降级为顺手做。
 * ★ 若 `D / A` 明显 > 1，则"统一 Cell"的结论要按值类型分档，不能一句话概括。
 */
final class M2
{
    /** 每轮读次数（10 万 × 80 轮 ≈ 800 万次读，足够压过噪声） */
    private const READS = 100_000;

    private const ROUNDS = 80;

    /**
     * @return array<string, int> 组名 → 每读中位纳秒
     */
    public static function run(): array
    {
        $mixed = new CellMixed();
        $int = new CellInt();
        $float = new CellFloat();
        $str = new CellMixedStr();
        $reads = self::READS;
        $out = [];

        $out['A mixed+toInt'] = self::measure(static function () use ($mixed, $reads): float {
            $acc = 0.0;
            for ($i = 0; $i < $reads; $i++) {
                $acc += (float) $mixed->value->toInt();
            }

            return $acc;
        });

        $out['B int direct'] = self::measure(static function () use ($int, $reads): float {
            $acc = 0.0;
            for ($i = 0; $i < $reads; $i++) {
                $acc += (float) $int->value;
            }

            return $acc;
        });

        $out['C float direct'] = self::measure(static function () use ($float, $reads): float {
            $acc = 0.0;
            for ($i = 0; $i < $reads; $i++) {
                $acc += $float->value;
            }

            return $acc;
        });

        $out['D mixed str+toInt'] = self::measure(static function () use ($str, $reads): float {
            $acc = 0.0;
            for ($i = 0; $i < $reads; $i++) {
                $acc += (float) $str->value->toInt();
            }

            return $acc;
        });

        return $out;
    }

    /**
     * @param callable():float $body
     */
    private static function measure(callable $body): int
    {
        $samples = [];
        $acc = 0.0;

        for ($i = 0; $i < 5; $i++) {
            $acc += $body();
        }

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $t0 = \hrtime(true);
            $acc += $body();
            $t1 = \hrtime(true);
            $samples[] = $t1 - $t0;
        }

        return (int) \round((float) Stats::median($samples) / (float) self::READS);
    }
}
