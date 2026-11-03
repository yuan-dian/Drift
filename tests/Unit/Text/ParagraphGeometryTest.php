<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Text;

use Drift\Geometry\Scale;
use Drift\Text\Paragraph;
use Drift\Text\TextStyle;
use PHPUnit\Framework\TestCase;

/**
 * `Paragraph` 的**正向**几何（字节偏移 → 行列坐标）。
 *
 * ★ 这是 `indexAt()` 的反面，多行输入框的插入符 / 选区 / IME 候选窗全靠它。
 *   它坏了的现象不是报错，是"插入符画在上一行"或"选区少涂一行"。
 *
 * ★ 断言尽量用**相对关系**（跨行落点、段数、差值）而不是绝对像素：字体度量
 *   换了之后绝对像素会变，关系不会 —— 后者才是要守的契约。
 */
final class ParagraphGeometryTest extends TestCase
{
    private function para(string $text, float $maxWidth = INF): Paragraph
    {
        return smokeContext()->metrics()->layout($text, TextStyle::of(14.0), $maxWidth, Scale::of(1.0));
    }

    /**
     * 硬换行：`\n` 必须真的断行，且插入点**落在正确那一行**。
     *
     * ★ 这条挡的是"用单行的 `advance(前缀)` 算多行"的实现：x 会算出整段宽度，
     *   而行号永远是 0，现象是插入符画在第一行右边很远处。
     */
    public function testHardLineBreakPlacesCaretOnTheRightLine(): void
    {
        $p = $this->para("ab\ncd");

        self::assertSame(2, $p->lineCount(), '硬换行必须断成两行');
        // "ab\ncd" 的字节：a=0 b=1 \n=2 c=3 d=4
        self::assertSame(1, $p->lineIndexAt(3), '换行之后的偏移必须归第二行');
        self::assertSame(0.0, $p->caretX(0), '段首插入点在行首');
        self::assertEqualsWithDelta(
            $p->lineRect(1)->left,
            $p->caretX(3),
            0.001,
            '第二行行首的插入点 x = 第二行行左缘（不是第一行右缘）',
        );
    }

    /** 软换行：宽度不够时自动断，末尾插入点落在**最后一行** */
    public function testSoftWrapPutsTrailingCaretOnLastLine(): void
    {
        // ★ 用 CJK 而不是连续拉丁字母：`breakLines()` 只在**可断点**断行，
        //   拉丁词内部没有断点 ⇒ 整词溢出成一行（那是 C5 溢出契约，不是 bug）。
        $text = str_repeat('中文', 20);
        $narrow = $this->para($text, 60.0);
        $wide = $this->para($text);

        self::assertGreaterThan(1, $narrow->lineCount(), '窄宽度下必须折行');
        self::assertSame(1, $wide->lineCount(), '不折时就是一行');

        $lastIndex = $narrow->lineCount() - 1;
        self::assertSame($lastIndex, $narrow->lineIndexAt(strlen($text)), '文本末尾必须归最后一行');
        self::assertEqualsWithDelta(
            $narrow->lineRect($lastIndex)->left + $narrow->lineRect($lastIndex)->width,
            $narrow->caretX(strlen($text)),
            0.001,
            '末尾插入点 = 末行内容右缘',
        );
    }

    /** 行内单调：偏移越大，x 不减小 */
    public function testCaretXIsMonotonicWithinALine(): void
    {
        $p = $this->para('abcd');
        $prev = -INF;

        for ($offset = 0; $offset <= 4; $offset++) {
            $x = $p->caretX($offset);
            self::assertGreaterThanOrEqual($prev, $x, "offset {$offset} 的 x 不应小于前一个");
            $prev = $x;
        }

        self::assertEqualsWithDelta($p->lineRect(0)->width, $p->caretX(4), 0.001, '文本末尾的插入点 = 行内容右缘');
    }

    /**
     * 选区**每行一段**：跨两行的选区不是一个大盒子。
     *
     * ★ 反例（用一个大矩形包住）在两行长度差很多时会把行尾空白也涂上。
     */
    public function testSelectionSplitsPerLine(): void
    {
        $p = $this->para("ab\ncd");
        $rects = $p->selectionRects(0, 5);

        self::assertCount(2, $rects, '跨行选区必须断成两段');
        self::assertSame($p->lineRect(0)->top, $rects[0]->top, '第一段在第一行');
        self::assertSame($p->lineRect(1)->top, $rects[1]->top, '第二段在第二行');
    }

    /** 单行选区就是一段，且宽度等于两端插入点之差 */
    public function testSingleLineSelectionWidth(): void
    {
        $p = $this->para('abcd');
        $rects = $p->selectionRects(1, 3);

        self::assertCount(1, $rects);
        self::assertEqualsWithDelta($p->caretX(3) - $p->caretX(1), $rects[0]->width, 0.001, '选区宽度 = 两端插入点之差');
    }

    /** 空选区 / 反向区间 ⇒ 没有矩形（不许画出幽灵高亮） */
    public function testEmptySelectionProducesNothing(): void
    {
        $p = $this->para('abcd');

        self::assertSame([], $p->selectionRects(2, 2));
        self::assertSame([], $p->selectionRects(3, 1));
    }

    /** 空行：`a\n\nb` 是三行，中间那行宽度为 0 但**行框存在** */
    public function testEmptyLineStillOccupiesARow(): void
    {
        $p = $this->para("a\n\nb");

        self::assertSame(3, $p->lineCount());
        self::assertSame(1, $p->lineIndexAt(2), '换行后、第二个换行前的偏移落在空行上');
        self::assertSame(0.0, $p->lineRect(1)->width, '空行没有墨水');
        self::assertGreaterThan(0.0, $p->lineRect(1)->height, '但空行有高度');
        self::assertEqualsWithDelta(
            $p->lineRect(1)->left,
            $p->caretX(2),
            0.001,
            '空行的插入点在本行左缘',
        );
    }

    /** `lineIndexAt` 的边界：换行符属上一行，换行之后属下一行，末尾属最后一行 */
    public function testLineIndexBoundaries(): void
    {
        $p = $this->para("ab\ncd");

        self::assertSame(0, $p->lineIndexAt(0));
        self::assertSame(0, $p->lineIndexAt(2), '换行符本身仍属上一行');
        self::assertSame(1, $p->lineIndexAt(3), '换行之后属于下一行');
        self::assertSame(1, $p->lineIndexAt(5), '文本末尾属于最后一行');
    }

    /** 空文本：不崩，且给出零尺寸 */
    public function testEmptyTextIsSafe(): void
    {
        $p = $this->para('');

        self::assertSame(0.0, $p->caretX(0));
        self::assertSame(0.0, $p->lineRect(0)->width);
        self::assertSame([], $p->selectionRects(0, 0));
    }
}
