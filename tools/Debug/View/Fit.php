<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

/**
 * 终端里的**显示宽度**与截断。
 *
 * 中文一个字占两列，`strlen` 数的是字节 —— 用它对齐，表头与数据行会一列一列地
 * 错开，而且错得"看起来只是有点歪"，没人会去查。宽度一律走 `mb_strwidth`；
 * 没有 mbstring 的解释器上退回字节数（至少不会切断多字节字符，只是对不齐）。
 *
 * 截断必须**按宽度**切，不能按字符切：一个宽字符卡在边界上时，宁可少放一个，
 * 也不能把它劈成半个字节序列（那会输出乱码，还会把终端宽度算错）。
 */
final class Fit
{
    /** 视作不可分的最小排版单位：一个字符（可能占 1 或 2 列）。 */
    public static function width(string $s): int
    {
        return \function_exists('mb_strwidth') ? \mb_strwidth($s, 'UTF-8') : \strlen($s);
    }

    /**
     * 截到不超过 `$width` 列；截了就补一个 `…`（它自己占 1 列）。
     *
     * 放得下就原样返回 —— 不补空格。补空格会让"这一行到底有没有内容"变得不可判，
     * 判据里 `assertSame` 也就没法比。
     */
    public static function clip(string $s, int $width): string
    {
        if ($width <= 0) {
            return '';
        }
        if (self::width($s) <= $width) {
            return $s;
        }

        $out = '';
        $used = 0;
        $limit = $width - 1; // 给 `…` 留一列
        foreach (self::chars($s) as $ch) {
            $w = self::width($ch);
            if ($used + $w > $limit) {
                break;
            }
            $out .= $ch;
            $used += $w;
        }

        return $out . '…';
    }

    /** 右侧补空格到 `$width` 列（只用于必须撑满的行，比如表头与分隔线）。 */
    public static function pad(string $s, int $width): string
    {
        $w = self::width($s);

        return $w >= $width ? $s : $s . \str_repeat(' ', $width - $w);
    }

    public static function rule(int $width): string
    {
        return \str_repeat('─', \max(0, $width));
    }

    /**
     * 左右两段拼成一行：左段贴左、右段贴右，中间用空格填。
     *
     * 放不下时**先丢右段**（它通常是可以省的信息，比如耗时），而不是把左段截一半 ——
     * 左段一般是"这是哪一页、哪个 tab"，丢了读者就不知道自己在看什么。
     */
    public static function spread(string $left, string $right, int $width): string
    {
        $lw = self::width($left);
        $rw = self::width($right);
        if ($lw + 1 + $rw > $width) {
            return self::clip($left, $width);
        }

        return $left . \str_repeat(' ', $width - $lw - $rw) . $right;
    }

    /** @return list<string> */
    private static function chars(string $s): array
    {
        $out = [];
        $n = \function_exists('mb_strlen') ? \mb_strlen($s, 'UTF-8') : \strlen($s);
        for ($i = 0; $i < $n; ++$i) {
            $out[] = \function_exists('mb_substr') ? \mb_substr($s, $i, 1, 'UTF-8') : $s[$i];
        }

        return $out;
    }
}
