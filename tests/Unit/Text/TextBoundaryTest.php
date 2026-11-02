<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Text;

use Drift\Text\TextBoundary;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 词 / 段的边界：**双击选哪个词、三击选哪一段**全靠这一个纯函数。
 *
 * ★ 判据全部落在字节偏移区间上（`[start, end)`），不落在"选出来的字符串"上：
 *   字符串相等看不出"多带了一个空格"还是"少了一个字节"，而少一个字节就是
 *   **半个汉字**（`Utf8` 类注释记的那类不报错的错）。
 *
 * ★ 取侧规则（左半 / 右半）单独成组：它是这三条里最容易写反的 —— 只测"点在词中间"
 *   时，把规则 1 和 2 调换顺序照样全绿，而实际手感变成"点在词尾选下一个词"。
 *
 * ★ 中文**没有分词**（无词典）：连续汉字算一个词。这一组用例把这条现状钉住 ——
 *   哪天接进 UAX #29 / ICU，这些行会红，逼一次明确决定，而不是静默改变手感。
 */
final class TextBoundaryTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: int, 2: int, 3: int}>
     */
    public static function wordCases(): array
    {
        return [
            // ── 取侧：同一个词，四个插入符位置 → 同一个区间 ──
            '词首（无左邻）⇒ 该词' => ['hello world', 0, 0, 5],
            '词中间 ⇒ 该词' => ['hello world', 2, 0, 5],
            '词尾（左词右空白）⇒ 仍是该词，不是后面的空白' => ['hello world', 5, 0, 5],
            '空白之后（左空白右词）⇒ 右边那个词' => ['hello world', 6, 6, 11],
            '文本末尾 ⇒ 左边那个词（不是空段）' => ['hello world', 11, 6, 11],
            '越界偏移 ⇒ 夹到末尾再取' => ['hello world', 99, 6, 11],
            '负偏移 ⇒ 夹到 0' => ['hello world', -5, 0, 5],
            // ── 类：标识符、数字、带音标拉丁 ──
            '下划线算词内字符 ⇒ `user_id` 是一个词' => ['user_id x', 2, 0, 7],
            '纯数字段自成一类（点左右侧仍是数字）' => ['v1.5', 0, 0, 2],
            '带音标的拉丁字母与 ASCII 同类 ⇒ `café` 不裂开' => ['café x', 3, 0, 5],
            // ── 空白 / 标点自成一段 ──
            '点在空白里 ⇒ 选空白段（不是一个空区间）' => ['a  b', 2, 1, 3],
            '点在逗号右侧 ⇒ 选逗号段' => ['hi, there', 3, 2, 3],
            '点在逗号左侧 ⇒ 选左边的词（标点不抢）' => ['hi, there', 2, 0, 2],
            // ── CJK：连续汉字一个词，全角标点断开 ──
            '三个汉字 ⇒ 一个词（无词典的现状）' => ['张三丰', 3, 0, 9],
            '汉字串中间 ⇒ 整串' => ['张三丰', 5, 0, 9],
            '全角逗号断词：左侧' => ['张三，丰', 6, 0, 6],
            '全角逗号断词：右侧' => ['张三，丰', 9, 9, 12],
            '汉字与数字分属两类 ⇒ 不连成一串（点汉字串里 ⇒ 只有汉字）' => ['用户1', 3, 0, 6],
            '汉字与数字分属两类 ⇒ 点数字侧只选数字' => ['用户1', 6, 6, 7],
            // ── 边界输入 ──
            '空文本 ⇒ 空区间，不炸' => ['', 0, 0, 0],
            '全空白 ⇒ 选空白段' => ['   ', 1, 0, 3],
        ];
    }

    #[DataProvider('wordCases')]
    public function testWordAt(string $text, int $offset, int $expectedStart, int $expectedEnd): void
    {
        self::assertSame(
            [$expectedStart, $expectedEnd],
            TextBoundary::wordAt($text, $offset),
            \sprintf(
                '%s @%d → 期望 [%d,%d)，实际选中 %s',
                \json_encode($text),
                $offset,
                $expectedStart,
                $expectedEnd,
                \json_encode(self::slice($text, TextBoundary::wordAt($text, $offset))),
            ),
        );
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: int, 3: int}>
     */
    public static function paragraphCases(): array
    {
        return [
            '单行 ⇒ 整串' => ['one two', 3, 0, 7],
            '两行：点第一行 ⇒ 只到换行前' => ["ab\ncd", 1, 0, 2],
            '两行：插入符正好在换行上 ⇒ 归**前**一段' => ["ab\ncd", 2, 0, 2],
            '两行：点第二行 ⇒ 从换行后开始' => ["ab\ncd", 3, 3, 5],
            '空段（连续两个换行）⇒ 空区间' => ["ab\n\ncd", 3, 3, 3],
            'CRLF：`\r` 不算内容' => ["a\r\nb", 1, 0, 1],
            'CRLF：第二行' => ["a\r\nb", 3, 3, 4],
            '末行之后还有换行 ⇒ 空区间' => ["a\n", 2, 2, 2],
            '空文本 ⇒ 空区间' => ['', 0, 0, 0],
            '越界 ⇒ 夹到末尾' => ['abc', 99, 0, 3],
        ];
    }

    #[DataProvider('paragraphCases')]
    public function testParagraphAt(string $text, int $offset, int $expectedStart, int $expectedEnd): void
    {
        self::assertSame([$expectedStart, $expectedEnd], TextBoundary::paragraphAt($text, $offset));
    }

    /**
     * `Ctrl+→` 的落点：**跳过当前段 + 中间分隔符，停在下一个词首**。
     *
     * ★ 期望值全是"下一个词的首字节偏移"，不是"当前词尾" —— 两者在 `hello world`
     *   差 1（5 vs 6）。写反了的表现是"按一下 `Ctrl+→` 光标停在空格前"，
     *   用户会觉得"少跳了一下"，而代码看着完全正常。
     *
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function nextWordStartCases(): array
    {
        return [
            '词首 ⇒ 下一个词首（跳过空格）' => ['hello world', 0, 6],
            '词中间 ⇒ 仍是下一个词首（不在当前词尾停）' => ['hello world', 2, 6],
            '词尾 ⇒ 下一个词首' => ['hello world', 5, 6],
            '已在空白里 ⇒ 下一个词首' => ['hello world', 6, 11],
            '末尾 ⇒ 文本长度（不是 0）' => ['hello world', 11, 11],
            '连续空白一次跳完' => ['a    b', 1, 5],
            '标点后紧跟空格 ⇒ 停在字母上，不停在标点' => ['abc, def', 0, 5],
            '词后直接接标点 ⇒ 标点被跳过，停在后一个词首' => ['abc,def', 0, 4],
            '中文没有分词 ⇒ 整段汉字是一个单位' => ['用户 1', 0, 7],
            'CJK 与拉丁不同类 ⇒ `用户1` 是两个单位' => ['用户1', 0, 6],
            '多字节字符不切半' => ['张三丰x', 0, 9],
            '空文本 ⇒ 0' => ['', 0, 0],
        ];
    }

    #[DataProvider('nextWordStartCases')]
    public function testNextWordStart(string $text, int $offset, int $expected): void
    {
        self::assertSame($expected, TextBoundary::nextWordStart($text, $offset));
    }

    /**
     * `Ctrl+←` 的落点：先跳分隔符、再跳同类段到**词首**。
     *
     * ★ 关键用例是「词中间 ⇒ 本词词首」与「词尾 ⇒ 本词词首」：这两条定义了
     *   "`Ctrl+←` 不会跨过当前词"。若实现先跳同类段再跳分隔符，`用户 1|`
     *   会停在空格前（6）而不是 `1` 前（7） —— 只差一个字节，肉眼看不出。
     *
     * @return array<string, array{0: string, 1: int, 2: int}>
     */
    public static function previousWordStartCases(): array
    {
        return [
            '末尾 ⇒ 本词词首' => ['hello world', 11, 6],
            '词中间 ⇒ 本词词首' => ['hello world', 8, 6],
            '词首 ⇒ 上一个词词首（跳过空格）' => ['hello world', 6, 0],
            '第二个词首 ⇒ 0' => ['a b', 2, 0],
            '已在空白里 ⇒ 左边那个词的词首' => ['hello world', 6, 0],
            '开头 ⇒ 0（不是负数）' => ['hello world', 0, 0],
            '连续空白一次跳完' => ['a    b', 5, 0],
            '标点隔开 ⇒ 停在标点左侧那个词的首' => ['abc, def', 8, 5],
            '在词首 ⇒ 跳过分隔符到上一个词首' => ['用户 1', 7, 0],
            'CJK 与拉丁不同类 ⇒ 只跳到本段首' => ['用户1', 7, 6],
            '空文本 ⇒ 0' => ['', 0, 0],
            '越界偏移 ⇒ 夹到末尾再算' => ['hello world', 99, 6],
        ];
    }

    #[DataProvider('previousWordStartCases')]
    public function testPreviousWordStart(string $text, int $offset, int $expected): void
    {
        self::assertSame($expected, TextBoundary::previousWordStart($text, $offset));
    }

    /**
     * ★ 两条跳词的落点必须**互相可达**：从 a 按 `Ctrl+→` 到 b，从 b 按 `Ctrl+←`
     *   应回到 a。这一条不写在用例表里，是因为"表里的每一行都恰好配到"这种事
     *   写不全 —— 而它对用户是**唯一可感知**的性质（"按过去再按回来"）。
     */
    public function testForwardAndBackwardWordStartsAreSymmetric(): void
    {
        foreach (['hello world', 'abc, def', '用户 1', '用户1', '  x  y  ', 'a_b c'] as $text) {
            $length = \strlen($text);
            $offset = 0;

            while (true) {
                $next = TextBoundary::nextWordStart($text, $offset);
                if ($next === $offset || $next >= $length) {
                    break;
                }

                self::assertSame(
                    $offset,
                    TextBoundary::previousWordStart($text, $next),
                    \sprintf('%s：从 %d 跳到 %d，回跳没回到原处', \json_encode($text), $offset, $next),
                );

                $offset = $next;
            }
        }
    }

    /**
     * ★ 这一条守的是"区间端点落在字符边界上"—— 字节偏移少一位就是半个汉字。
     *   上面每条用例都在隐式依赖它，但只有逐字比对能把"`[0,5)` 其实切出 `hell`"
     *   这类错误暴露成**可读的**失败。
     */
    public function testWordNeverCutsAGlyph(): void
    {
        $text = '张三 hello 世界';
        $length = \strlen($text);

        for ($offset = 0; $offset <= $length; $offset++) {
            $range = TextBoundary::wordAt($text, $offset);
            $word = \substr($text, $range[0], $range[1] - $range[0]);

            self::assertTrue(
                \mb_check_encoding($word, 'UTF-8'),
                \sprintf('offset=%d 切出了半个字符：%s', $offset, \json_encode($word)),
            );
        }
    }

    /**
     * @param array{0: int, 1: int} $range
     */
    private static function slice(string $text, array $range): string
    {
        return \substr($text, $range[0], $range[1] - $range[0]);
    }
}
