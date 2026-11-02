<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * **词 / 段**的字节区间查询（纯函数、零状态、零宿主依赖）。
 *
 * ```php
 * TextBoundary::wordAt('用户 1', 3);      // [0, 6]  —— 「用户」
 * TextBoundary::paragraphAt("ab\ncd", 4); // [3, 5]
 * ```
 *
 * ## 为什么单独一个类，而不是塞进 `TextDraft`
 *
 * 与 `Layout\ExtentWindow` 同一条判据：**"求某个区间"与任何宿主无关**。放在
 * `TextDraft` 里就变成"只有能构造草稿的地方才能分词"，于是表格的指针路径
 * （建盒之前、草稿之外）就得抄第二份 —— 而两份实现差一个字节边界时的现象是
 * "双击选中了半个字"，不报错。
 *
 * 也不放进 `Utf8`：那个类是**字符与断行**（码位解码、宽窄、禁则），它是本类的
 * **输入**。混在一起会让"UTF-8 工具"变成"文本杂项"。
 *
 * ## 为什么没有词典
 *
 * 真正的词边界要 ICU / UAX #29 分词表（`Utf8` 的类注释已经记过这笔账：不做完整
 * UAX #14，因为要 ICU 数据）。这里用**同类字符连续段**作词：
 *
 * | 类 | 成员 | 例 |
 * |---|---|---|
 * | 拉丁词 | ASCII 字母数字 `_` + 非宽的非 ASCII 字母 | `abc` `user_id` `café` |
 * | CJK | `Utf8::isCjk()` | `张三丰` |
 * | 空白 | `Utf8::isWhitespace()` | 空格 / 制表 / 全角空格 |
 * | 其它 | 其余（含全角标点） | `，` `-` `(` |
 *
 * ⇒ **中文没有分词**：连续汉字算一个词。这是可预测的，也是**诚实**的 ——
 * 猜错比不猜更糟（"双击选中了半个词"没法解释给用户）。真要做中文分词，
 * 换的是本类的实现，`TextDraft` 与两个手势消费面（双击 / 三击）一行都不动。
 *
 * ★ 汉字与拉丁分开成两类，而不是都算"词字符"：否则 `用户1` 会连成一串，
 *   而 `用户 1`（本仓表格夹具的形态）与 `用户1` 的手感会莫名其妙地不一致。
 *
 * ## 偏移口径
 *
 * 入参与出参**一律是字节偏移**（与 `TextDraft` 同一套，见 `Utf8` 的类注释：
 * 混用码位下标与字节下标是"光标乱跳"的根源）。`$offset` 是**插入符位置**（字符
 * 之间的缝），不是"第几个字符" —— 见 {@see self::wordAt()} 的取侧规则。
 *
 * @api
 */
final class TextBoundary
{
    // 同类连续段的四种归属（★ 不用枚举：`case` 拼错时 AOT 不报错，见 MEMORY）
    private const RUN_LATIN = 1;

    private const RUN_CJK = 2;

    private const RUN_SPACE = 3;

    private const RUN_OTHER = 4;

    /** 换行（段落分隔）；`\r` 只作为 `\r\n` 的前半个字节被吞掉 */
    private const NEWLINE = 0x0A;

    private const CARRIAGE_RETURN = 0x0D;

    private function __construct()
    {
    }

    /**
     * 插入符所在的**词**区间 `[start, end)`（字节偏移，`end` 不含）。
     *
     * 取侧规则（"点左半边还是右半边"）只有**两条**：
     *
     * 1. 右邻是词字符 ⇒ **右侧的段**（点在词首 / 词中间 / 词与空白之间偏字的那边）
     * 2. 否则 ⇒ **左侧的段**（点在词尾 / 点在空白或标点里）；左边没有字符 ⇒ 退右侧
     *
     * ★ 原本还有一条"左邻是词字符且右邻不是 ⇒ 选左"，**变异自检证明它是死规则**
     *   （去掉后 32 条用例全绿：规则 2 的兜底就是"选左"）⇒ 已删。写下来的"优先级
     *   三条"其实是两条 + 一个兜底。**判据不为真的注释比没有注释更贵。**
     *
     * ★ 规则 1 是这一族里唯一"少一行就错"的地方：去掉它 ⇒ 点在词首（`|world`）
     *   会选中前面的空格（实测 `TextBoundaryTest` 的"空白之后"那行红）。
     *
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
     *
     * ★ 插入符**正好落在换行上** ⇒ 归**前**一段（`end` = 换行的位置）。反过来
     *   定义的话，"把光标按到行尾再三击"会选中下一行开头那一段，与视觉直觉相反。
     *
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
     * `$offset` 左侧最近的**词首**（`Ctrl+←` 的落点）。
     *
     * 两步、顺序不可换：① 跳过紧邻的空白/标点 → ② 跳过同类词段到首。
     * 反过来（先跳词再看非词）会让 `用户 1|` 的 `Ctrl+←` 停在空格前，
     * 而用户预期是停在 `1` 前 —— 差一个字符，且看起来像"少跳了一下"。
     *
     * ★ 与 {@see self::wordAt()} 共用 `classOf()`：双击选中的词与 `Ctrl+←/→`
     *   跳过的一定是**同一个区间**。两套独立分类器是"双击选中 `用户`、
     *   `Ctrl+→` 却跳进 `1`"这类不一致的来源。
     *
     * ★ 只跳**同类**词段，所以 `用户1` 是两个可跳单位（与双击的手感一致）。
     *   若要"中文不分词、汉字连读到底"，那是 `classOf()` 的事，这里一行不动。
     */
    public static function previousWordStart(string $text, int $offset): int
    {
        $chars = Utf8::decode($text);
        $count = \count($chars);
        if ($count === 0) {
            return 0;
        }

        $offset = self::clamp($offset, \strlen($text));

        // 插入符左侧那个字符的下标
        $i = -1;
        for ($k = 0; $k < $count; $k++) {
            if ($chars[$k][1] + $chars[$k][2] > $offset) {
                break;
            }
            $i = $k;
        }
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
     * `$offset` 右侧最近的**词首**（`Ctrl+→` 的落点；末尾则返回文本长度）。
     *
     * 三步：① 跳过 `$offset` 所在的那一段 → ② 跳过空白/标点 → ③ 停在词首。
     * ②③ 不能合并：合并成"跳到一个词字符上"的话，词后面紧跟标点
     * （`abc, def`）会停在 `,` 上。
     *
     * ★ 从词**中间**按 `Ctrl+→` 会直接到**下一个词**的首（不是当前词尾）：
     *   Windows 原生 Edit / 浏览器 / GTK 一致，用户对"按两下跨过两个词"有预期。
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

        // 插入符右侧那个字符的下标
        $i = $count;
        for ($k = 0; $k < $count; $k++) {
            if ($chars[$k][1] >= $offset) {
                $i = $k;

                break;
            }
        }
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
     * 两条取侧规则（见 {@see self::wordAt()}）。
     *
     * ★ 抽成独立方法是为了让"取侧"可被单独读一遍：它比"扩到同类边界"更容易写错，
     *   而写错的现象是"某个位置选出来的词差一个字"，肉眼几乎看不出是取侧的问题。
     *
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
        // ★ 宽字符（全角标点、CJK 符号）走这里会被 `isWide()` 挡住 ⇒ 归"其它"。
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
