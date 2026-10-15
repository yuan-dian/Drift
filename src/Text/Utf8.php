<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * UTF-8 工具（§8.2 坑 7）。
 *
 * ★ 断行与光标都按**码位**走，不按字节走 —— 但对外暴露的偏移一律是**字节偏移**，
 *   因为源文本是字节串，混用两套下标是"光标乱跳"这类 bug 的根源。
 *
 * ★ 断行规则只做**最小实现**（§8.3 对策 ②）：拉丁按空白、CJK 按字边界 +
 *   基本禁则。不做完整 UAX #14，也不做 bidi —— 那需要 ICU 数据（§8.3 血账 2）。
 *
 * @api
 */
final class Utf8
{
    /** 不能出现在行首的标点（禁则：行首禁则） */
    private const NO_LINE_START = [
        0x3001, 0x3002, 0xFF0C, 0xFF0E, 0xFF01, 0xFF1F, 0xFF1B, 0xFF1A, // 、。，．！？；：
        0xFF09, 0x3009, 0x300B, 0x300D, 0x300F, 0x3011, 0x3015, 0x2019, 0x201D, // ）〉》」』】〕’”… 的闭引号
        0x2026, 0x00B7,
    ];

    /** 不能出现在行尾的标点（行尾禁则） */
    private const NO_LINE_END = [
        0xFF08, 0x3008, 0x300A, 0x300C, 0x300E, 0x3010, 0x3014, 0x2018, 0x201C,
    ];

    private function __construct()
    {
    }

    /**
     * 解码成码位序列。
     *
     * @return list<array{0: int, 1: int, 2: int}> [码位, 字节偏移, 字节长度]
     */
    public static function decode(string $text): array
    {
        $out = [];
        $offset = 0;
        $length = strlen($text);

        while ($offset < $length) {
            $byte = ord($text[$offset]);
            if ($byte < 0x80) {
                $size = 1;
                $codepoint = $byte;
            } elseif (($byte & 0xE0) === 0xC0) {
                $size = 2;
                $codepoint = $byte & 0x1F;
            } elseif (($byte & 0xF0) === 0xE0) {
                $size = 3;
                $codepoint = $byte & 0x0F;
            } elseif (($byte & 0xF8) === 0xF0) {
                $size = 4;
                $codepoint = $byte & 0x07;
            } else {
                // 非法起始字节 ⇒ 当一个替换字符走，不静默吞掉
                $out[] = [0xFFFD, $offset, 1];
                $offset++;
                continue;
            }

            for ($i = 1; $i < $size; $i++) {
                $codepoint = ($codepoint << 6) | (ord($text[$offset + $i]) & 0x3F);
            }

            $out[] = [$codepoint, $offset, $size];
            $offset += $size;
        }

        return $out;
    }

    public static function encode(int $codepoint): string
    {
        if ($codepoint < 0x80) {
            return chr($codepoint);
        }
        if ($codepoint < 0x800) {
            return chr(0xC0 | ($codepoint >> 6)) . chr(0x80 | ($codepoint & 0x3F));
        }
        if ($codepoint < 0x10000) {
            return chr(0xE0 | ($codepoint >> 12))
                . chr(0x80 | (($codepoint >> 6) & 0x3F))
                . chr(0x80 | ($codepoint & 0x3F));
        }
        return chr(0xF0 | ($codepoint >> 18))
            . chr(0x80 | (($codepoint >> 12) & 0x3F))
            . chr(0x80 | (($codepoint >> 6) & 0x3F))
            . chr(0x80 | ($codepoint & 0x3F));
    }

    /** 码位数（不是字节数） */
    public static function length(string $text): int
    {
        return count(self::decode($text));
    }

    public static function isWhitespace(int $codepoint): bool
    {
        return $codepoint === 0x20 || $codepoint === 0x09 || $codepoint === 0x0A || $codepoint === 0x0D
            || $codepoint === 0x3000;
    }

    public static function isCjk(int $codepoint): bool
    {
        return ($codepoint >= 0x4E00 && $codepoint <= 0x9FFF)      // 基本区
            || ($codepoint >= 0x3400 && $codepoint <= 0x4DBF)      // 扩展 A
            || ($codepoint >= 0xF900 && $codepoint <= 0xFAFF)      // 兼容表意
            || ($codepoint >= 0x3040 && $codepoint <= 0x30FF)      // 假名
            || ($codepoint >= 0x3000 && $codepoint <= 0x303F);     // CJK 标点
    }

    /**
     * 全角（占两个半角宽）的码位。
     *
     * ★ 判据是**东亚宽度属性 = Wide / Fullwidth**，不只是"是不是汉字" ——
     *   全角标点、假名、emoji 都是全角，漏掉它们会让回退步进算窄一半。
     */
    public static function isWide(int $codepoint): bool
    {
        return self::isCjk($codepoint)
            || ($codepoint >= 0x1100 && $codepoint <= 0x115F)      // 谚文字母
            || ($codepoint >= 0x2E80 && $codepoint <= 0x2EFF)      // 部首补充
            || ($codepoint >= 0xAC00 && $codepoint <= 0xD7A3)      // 谚文音节
            || ($codepoint >= 0xFF00 && $codepoint <= 0xFF60)      // 全角形式
            || ($codepoint >= 0xFFE0 && $codepoint <= 0xFFE6)      // 全角符号
            || ($codepoint >= 0x1F300 && $codepoint <= 0x1FAFF);   // emoji
    }

    /**
     * `$before` 与 `$after` 之间能不能断行。
     *
     * 规则（最小实现）：**任一侧是全角就可断** —— 表意字之间处处可断，拉丁与表意
     * 的脚本边界也可断；两侧都窄（拉丁词内部）不可断，于是整词溢出而不被劈开
     * （§13.1 C5）。禁则优先：行首不放闭标点、行尾不放开标点。
     */
    public static function canBreakBetween(int $before, int $after): bool
    {
        if (in_array($after, self::NO_LINE_START, true)) {
            return false;
        }
        if (in_array($before, self::NO_LINE_END, true)) {
            return false;
        }
        if (self::isWhitespace($before)) {
            return true;
        }
        return self::isWide($before) || self::isWide($after);
    }
}
