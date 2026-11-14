<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 词 / 段的字节区间查询（纯函数、零状态、零宿主依赖）。
 * 词 = 同类字符连续段：拉丁词 / CJK / 空白 / 其它四类（详见 `classOf()`）。
 * 无词典：**中文不分词**，连续汉字算一个词（比猜错更诚实）；要中文分词改 `classOf()`，
 * `TextDraft` 与双击/三击两个手势消费面一行不动。
 * 入参出参一律**字节偏移**；`$offset` 是**插入符位置**（字符之间的缝），不是第几个字符。
 * @api
 */
final class TextBoundary
{
    // 同类连续段的四种归属（不用枚举：case 拼错时 AOT 不报错）
    private const int RUN_LATIN = 1;

    private const int RUN_CJK = 2;

    private const int RUN_SPACE = 3;

    private const int RUN_OTHER = 4;

    /** 换行（段落分隔）；`\r` 只作为 `\r\n` 的前半个字节被吞掉 */
    private const int NEWLINE = 0x0A;

    private const int CARRIAGE_RETURN = 0x0D;

    private function __construct()
    {
    }

    /**
     * 插入符所在的**词**区间 `[start, end)`（字节偏移，`end` 不含）。
     * 取侧规则：右邻是词字符 ⇒ 右侧段；否则 ⇒ 左侧段（左边无字符则退右侧）。
     * ★ 规则 1（右邻是词字符选右侧）是这一族里唯一"少一行就错"处：去掉它 ⇒
     *   点在词首会选中前面空格（实测用例"空白之后"那行红）。
     * @return array{0: int, 1: int}
     */
    public static function wordAt(string $text, int $offset): array
    {
        $chars = Utf8::decode($text);
        $count = \count($chars);
        if ($count === 0) {
            return [0, 0];
        }

        $offset = self::clamp($offset, \strlen($text));

        $left = -1;
        $right = -1;
        for ($i = 0; $i < $count; $i++) {
            $start = $chars[$i][1];
            $end = $start + $chars[$i][2];
            if ($end <= $offset) {
                $left = $i;

                continue;
            }
            // 落在字符内部（正常路径不会：偏移恒已吸附）⇒ 当作"该字符的右缝"
            $right = $i;

            break;
        }

        $pick = self::pickIndex($chars, $left, $right);
        if ($pick < 0) {
            return [$offset, $offset];
        }

        $class = self::classOf($chars[$pick][0]);

        $startIdx = $pick;
        while ($startIdx > 0 && self::classOf($chars[$startIdx - 1][0]) === $class) {
            $startIdx--;
        }

        $endIdx = $pick;
        while ($endIdx + 1 < $count && self::classOf($chars[$endIdx + 1][0]) === $class) {
            $endIdx++;
        }

        return [$chars[$startIdx][1], $chars[$endIdx][1] + $chars[$endIdx][2]];
    }

    /**
     * 插入符所在的**段**（单行编辑器里就是整串）：按 `\n` 切，`\r\n` 归前一段吞掉。
     * ★ 插入符**正好落在换行上** ⇒ 归**前**一段；反过来定义，"光标按到行尾再三击"
     *   会选中下一行开头，与视觉直觉相反。
     * @return array{0: int, 1: int}
     */
    public static function paragraphAt(string $text, int $offset): array
    {
        $length = \strlen($text);
        $offset = self::clamp($offset, $length);

        $start = 0;
        $end = $length;

        foreach (Utf8::decode($text) as $glyph) {
            if ($glyph[0] !== self::NEWLINE) {
                continue;
            }

            $newline = $glyph[1];
            if ($newline < $offset) {
                $start = $newline + 1;

                continue;
            }

            // 换行归前一段：`\r\n` 的 `\r` 不算内容
            $end = ($newline > 0 && \ord($text[$newline - 1]) === self::CARRIAGE_RETURN)
                ? $newline - 1
                : $newline;

            break;
        }

        return [$start, $end];
    }

    /**
     * `$offset` 左侧最近的**词首**（`Ctrl+←` 落点）。
     * 两步、顺序不可换：① 跳过紧邻空白/标点 → ② 跳过同类词段到首；
     * 反过来会让 `用户 1|` 的 `Ctrl+←` 停在空格前，用户预期停在 `1` 前。
     * 与 {@see self::wordAt()} 共用 `classOf()`，保证双击选中的词与跳转跳过的是同一区间；
     * 只跳同类词段，故 `用户1` 是两个可跳单位（与双击手感一致）。
     */
    public static function previousWordStart(string $text, int $offset): int
    {
        $chars = Utf8::decode($text);
        $count = \count($chars);
        if ($count === 0) {
            return 0;
        }

        $offset = self::clamp($offset, \strlen($text));

        $i = self::indexAtOrBefore($chars, $offset);
        if ($i < 0) {
            return 0;
        }

        while ($i >= 0 && !self::isWord($chars[$i][0])) {
            $i--;
        }

        if ($i < 0) {
            return 0;
        }

        $class = self::classOf($chars[$i][0]);
        while ($i >= 0 && self::classOf($chars[$i][0]) === $class) {
            $i--;
        }

        return $chars[$i + 1][1];
    }

    /**
     * `$offset` 右侧最近的**词首**（`Ctrl+→` 落点；末尾返回文本长度）。
     * 三步：① 跳过所在段 → ② 跳过空白/标点 → ③ 停在词首（②③ 不可合并，
     * 否则 `abc, def` 会停在 `,` 上）。
     * 从词**中间**按 `Ctrl+→` 直接到**下一个词**首（非当前词尾）：Win32 Edit / 浏览器 / GTK 一致。
     */
    public static function nextWordStart(string $text, int $offset): int
    {
        $chars = Utf8::decode($text);
        $count = \count($chars);
        $length = \strlen($text);
        if ($count === 0) {
            return 0;
        }

        $offset = self::clamp($offset, $length);

        $i = self::indexAtOrAfter($chars, $offset);
        if ($i >= $count) {
            return $length;
        }

        $class = self::classOf($chars[$i][0]);
        while ($i < $count && self::classOf($chars[$i][0]) === $class) {
            $i++;
        }

        while ($i < $count && !self::isWord($chars[$i][0])) {
            $i++;
        }

        return $i >= $count ? $length : $chars[$i][1];
    }

    /**
     * `$offset` 右侧最近的**词尾**（`Ctrl+Delete` 落点；末尾返回文本长度）。
     * 落点**故意**与 `nextWordStart()` 不同：Win32 Edit / GTK / 浏览器三者一致（"移到词首、删到词尾"）。
     * 两条分支：① `$offset` 处是词字符 ⇒ 前进到**当前**词尾；② 否则跳过非词再到词尾。
     * ★ 分支 1 **不**继续跳后面的非词：跳了会把 `ab|c def` 连空格带 `def` 一起删，
     *   "删一个词"变"删一段"，用户只看到"多删了"。
     */
    public static function nextWordEnd(string $text, int $offset): int
    {
        $chars = Utf8::decode($text);
        $count = \count($chars);
        $length = \strlen($text);
        if ($count === 0) {
            return 0;
        }

        $offset = self::clamp($offset, $length);

        $i = self::indexAtOrAfter($chars, $offset);

        // 在非词上 ⇒ 先跳过这一段（在词上则不跳：跳了就是"连删后一个词"）
        while ($i < $count && !self::isWord($chars[$i][0])) {
            $i++;
        }

        if ($i >= $count) {
            return $length;
        }

        $class = self::classOf($chars[$i][0]);
        while ($i < $count && self::classOf($chars[$i][0]) === $class) {
            $i++;
        }

        return $i >= $count ? $length : $chars[$i][1];
    }

    /**
     * 右侧第一个字符的下标（`$offset` 落在字符内部或字符缝上都算右侧）。
     * @param list<array{0: int, 1: int, 2: int}> $chars
     * @return int 无 ⇒ `count($chars)`
     */
    private static function indexAtOrAfter(array $chars, int $offset): int
    {
        foreach ($chars as $k => $glyph) {
            if ($glyph[1] >= $offset) {
                return $k;
            }
        }

        return \count($chars);
    }

    /**
     * 左侧第一个字符的下标（其**结尾** ≤ `$offset`）。
     * @param list<array{0: int, 1: int, 2: int}> $chars
     * @return int 无 ⇒ `-1`
     */
    private static function indexAtOrBefore(array $chars, int $offset): int
    {
        $pick = -1;
        foreach ($chars as $k => $glyph) {
            if ($glyph[1] + $glyph[2] > $offset) {
                break;
            }
            $pick = $k;
        }

        return $pick;
    }

    /**
     * 两条取侧规则（见 {@see self::wordAt()}）。
     * 抽成独立方法：取侧比"扩到同类边界"更易写错，且现象是"某位置选出的词差一个字"，肉眼难辨。
     * @param list<array{0: int, 1: int, 2: int}> $chars
     */
    private static function pickIndex(array $chars, int $left, int $right): int
    {
        if ($right >= 0 && self::isWord($chars[$right][0])) {
            return $right;
        }

        return $left >= 0 ? $left : $right;
    }

    /** 词字符 = 拉丁词 或 CJK（"取侧"只看这条，不看细分四类） */
    private static function isWord(int $codepoint): bool
    {
        $class = self::classOf($codepoint);

        return $class === self::RUN_LATIN || $class === self::RUN_CJK;
    }

    private static function classOf(int $codepoint): int
    {
        if (Utf8::isWhitespace($codepoint)) {
            return self::RUN_SPACE;
        }

        if (Utf8::isCjk($codepoint)) {
            return self::RUN_CJK;
        }

        if ($codepoint === 0x5F) {   // `_`：标识符里算词内字符
            return self::RUN_LATIN;
        }

        $isAsciiAlnum = ($codepoint >= 0x30 && $codepoint <= 0x39)
            || ($codepoint >= 0x41 && $codepoint <= 0x5A)
            || ($codepoint >= 0x61 && $codepoint <= 0x7A);
        if ($isAsciiAlnum) {
            return self::RUN_LATIN;
        }

        // 带音标的拉丁字母（`café`）：非宽的非 ASCII 字符跟 ASCII 字母同类。
        // 宽字符（全角标点、CJK 符号）走这里会被 `isWide()` 挡住 ⇒ 归"其它"。
        if ($codepoint >= 0x80 && !Utf8::isWide($codepoint)) {
            return self::RUN_LATIN;
        }

        return self::RUN_OTHER;
    }

    private static function clamp(int $offset, int $length): int
    {
        if ($offset < 0) {
            return 0;
        }

        return $offset > $length ? $length : $offset;
    }
}
