<?php
declare(strict_types=1);

namespace App;

/**
 * 夹具：`str-concat-loop` 的已知错误 / 已知正确（§16.6.3 #13 / §2.4 纪律 2）。
 *
 * 该响：
 * - `growByDotEqual()`：`$out .= $row` 在 foreach 体内
 * - `growByConcat()`：`$out = $out . $row`（另一个形态，同一判据）
 *
 * 不该响：
 * - `once()`：拼接**不在循环里**（纪律只管"增长累加器"）
 * - `joined()`：`implode` —— 纪律给出的出路
 * - `counted()`：循环里做别的（`$n++`）
 * - `assigned()`：`$out[] = …` 是数组追加，不是字符串累加
 * - `rebound()`：`$x = $y . $z` 右边不是同一个变量 ⇒ 不是累加器
 */
final class StrConcatFixture
{
    public function growByDotEqual(array $rows): string
    {
        $out = '';
        foreach ($rows as $row) {
            $out .= $row;
        }

        return $out;
    }

    public function growByConcat(array $rows): string
    {
        $out = '';
        foreach ($rows as $row) {
            $out = $out . $row;
        }

        return $out;
    }

    public function once(string $row): string
    {
        return $row . '!';
    }

    public function joined(array $rows): string
    {
        return implode('', $rows);
    }

    public function counted(array $rows): int
    {
        $n = 0;
        foreach ($rows as $row) {
            $n++;
        }

        return $n;
    }

    public function assigned(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $row;
        }

        return $out;
    }

    public function rebound(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[] = $row . '!' . $row;
        }

        return $out;
    }
}
