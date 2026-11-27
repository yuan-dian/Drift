<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Debug;

use Drift\DevTools\View\Fit;
use PHPUnit\Framework\TestCase;

/**
 * 终端宽度：中文占两列，截断不能劈开宽字符。
 *
 * 这几条看着琐碎，但"表头和数据列错开一列"是那种**看起来只是有点歪**的 bug：
 * 没人会去查，而它会让整张面板的可读性掉一档。所以按宽度算、按宽度截，都钉住。
 */
final class FitTest extends TestCase
{
    public function testWideCharactersCountAsTwoColumns(): void
    {
        $this->assertSame(4, Fit::width('因果'));
        $this->assertSame(2, Fit::width('ab'));
        $this->assertSame(4, Fit::width('a果b'));
    }

    public function testClipLeavesShortLinesUntouched(): void
    {
        $this->assertSame('abc', Fit::clip('abc', 3));
        $this->assertSame('abc', Fit::clip('abc', 10));
        $this->assertSame('', Fit::clip('abc', 0));
    }

    public function testClipNeverExceedsTheWidthAndMarksTheCut(): void
    {
        foreach ([1, 2, 3, 4, 5, 6, 7] as $width) {
            $clipped = Fit::clip('重建构成（帧 #12）', $width);
            $this->assertLessThanOrEqual($width, Fit::width($clipped), "width={$width}");
            if ($width >= 2) {
                $this->assertStringEndsWith('…', $clipped, "width={$width}");
            }
        }
    }

    /** 宽字符卡在边界上时宁可少放一个，也不能把字节序列劈成半个。 */
    public function testClipDoesNotSplitAWideCharacter(): void
    {
        $clipped = Fit::clip('因果树', 4); // 4 列 = 两个汉字；再加 `…` 会超，所以只放一个

        $this->assertSame('因…', $clipped);
        $this->assertSame(3, Fit::width($clipped));
    }

    public function testPadFillsToTheGivenWidth(): void
    {
        $this->assertSame('ab  ', Fit::pad('ab', 4));
        $this->assertSame('因果', Fit::pad('因果', 2));
        $this->assertSame(1, Fit::width(Fit::pad('a', 1)), '已经够宽就不补');
    }

    /** 右对齐：数字列的个位要能竖着扫（`12` 与 `3` 左对齐就扫不了）。 */
    public function testPadStartRightAlignsNumbers(): void
    {
        $this->assertSame('  12', Fit::padStart('12', 4));
        $this->assertSame('   3', Fit::padStart('3', 4));
        $this->assertSame('因果', Fit::padStart('因果', 2), '按显示宽度算：中文两个字的宽度正好 2');
        $this->assertSame('  因果', Fit::padStart('因果', 6));
        $this->assertSame('12345', Fit::padStart('12345', 4), '超宽就原样，不截');
    }

    /**
     * 按**段数**裁尾部，与按列宽的 {@see Fit::tailPath()} 是两件事。
     *
     * 行内只要"认出是哪一支"：完整链路已经在别处摊开了。用列宽去算，短路径一行不裁、
     * 长路径裁到只剩一个节点，反而看不出结构。
     */
    public function testTailSegmentsKeepsTheRequestedNumberOfSegments(): void
    {
        $path = 'PageShell > Card > Column > Expanded > Table';
        $this->assertSame('Expanded > Table', Fit::tailSegments($path));
        $this->assertSame('Column > Expanded > Table', Fit::tailSegments($path, 3));
        $this->assertSame($path, Fit::tailSegments($path, 9), '段数够就原样');
        $this->assertSame('Table', Fit::tailSegments($path, 0), '至少留一段：空串在行里等于没说话');
    }

    /** 左右两段拼一行；放不下时**先丢右段**，左段是"我在看什么"，不能截一半。 */
    public function testSpreadDropsTheRightPartBeforeCuttingTheLeft(): void
    {
        $this->assertSame('ab    cd', Fit::spread('ab', 'cd', 8));
        $this->assertSame('abcdef', Fit::spread('abcdef', 'gh', 6), '放不下 ⇒ 右段整个丢掉');

        $wide = Fit::spread('abcdef', 'gh', 20);
        $this->assertSame(20, Fit::width($wide));
        $this->assertStringStartsWith('abcdef', $wide);
        $this->assertStringEndsWith('gh', $wide);
    }

    public function testRuleHasExactlyTheRequestedWidth(): void
    {
        $this->assertSame(4, Fit::width(Fit::rule(4)));
        $this->assertSame('', Fit::rule(0));
    }
}
