<?php
declare(strict_types=1);

namespace Drift\Bench;

/**
 * 中位数 / 分位数。**故意不用平均**：一次 GC 或一次页错误就能把均值拉歪，
 * 而中位数只关心"典型一帧"（阶段 0 要的正是典型值）。
 */
final class Stats
{
    /**
     * @param list<int> $xs 纳秒样本
     */
    public static function median(array $xs): int
    {
        $n = \count($xs);
        if ($n === 0) {
            return 0;
        }

        \sort($xs);
        $half = $n >> 1;

        return ($n % 2 === 1) ? $xs[$half] : (int) (($xs[$half - 1] + $xs[$half]) >> 1);
    }

    /**
     * @param list<int> $xs
     */
    public static function p90(array $xs): int
    {
        $n = \count($xs);
        if ($n === 0) {
            return 0;
        }

        \sort($xs);
        $idx = (int) \min($n - 1, (int) \floor(0.9 * (float) $n));

        return $xs[$idx];
    }

    /**
     * 毫秒显示（三位小数足够分辨 0.001ms）。
     */
    public static function ms(int $ns): string
    {
        return \number_format((float) $ns / 1_000_000.0, 3, '.', '');
    }

    public static function us(int $ns): string
    {
        return \number_format((float) $ns / 1000.0, 2, '.', '');
    }

    /**
     * 比值，保留两位。
     */
    public static function ratio(int $a, int $b): string
    {
        if ($b <= 0) {
            return 'n/a';
        }

        return \number_format((float) $a / (float) $b, 2, '.', '');
    }
}
