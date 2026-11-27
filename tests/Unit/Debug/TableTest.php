<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Debug;

use Drift\DevTools\View\Fit;
use Drift\DevTools\View\Table;
use PHPUnit\Framework\TestCase;

/**
 * 列规格排版：定宽 / 自适应 / 右对齐，全部按显示列宽算。
 *
 * ## 为什么这些判据落在"列边界"上，而不是逐字节比字符串
 *
 * 表格里唯一会出错又看不出来的东西是**列边界**：补空格补错一两格，屏幕上看就是
 * "有点歪"，没人会去查。所以判据用 `Fit::width` 量显示宽度，而不是比字节 ——
 * 写死整行字符串则会在每次调列宽时全红，反而逼人去改判据。
 *
 * ## 一个真实标本
 *
 * `debug-report.php` 原先的「耗时分布」表是 `sprintf('%-22s %7.2fms')`。`settle`
 * 六字节补到 22，`（段外）` 十二字节也补到 22 —— 但显示宽度一个是 22、一个是 18，
 * 于是毫秒列差了 4 列。这条判据就是为它写的。
 */
final class TableTest extends TestCase
{
    public function testNumericColumnsAreRightAligned(): void
    {
        $lines = Table::render(
            [['t' => 'n', 'w' => 3, 'r' => true]],
            [['1'], ['12'], ['123']],
        );

        $this->assertSame('    n', $lines[0], '表头跟着数据走同一侧，否则第一眼会认错列');
        $this->assertSame('    1', $lines[1]);
        $this->assertSame('   12', $lines[2]);
        $this->assertSame('  123', $lines[3]);
    }

    /** 宽字符不会让后面的列提前或推迟开始 —— 这正是 `%-Ns` 做不到的那件事。 */
    public function testWideCharactersDoNotShiftTheNextColumn(): void
    {
        $lines = Table::render(
            [['t' => '阶段', 'w' => 10], ['t' => '耗时', 'w' => 7, 'r' => true]],
            [['settle', '27.50ms'], ['（段外）', '0.04ms'], ['tickAnimations', '0.01ms']],
        );

        // 每行总宽 = 缩进 2 + 10 + 1 + 7。宽字符占两列，所以「列数相同」与「字节数
        // 相同」在这里是两件事 —— 只有前者能证明没歪。
        foreach ($lines as $i => $line) {
            $this->assertSame(20, Fit::width($line), "第 {$i} 行的显示宽度");
        }
        $this->assertStringContainsString('（段外）', $lines[2]);
        $this->assertStringEndsWith('0.04ms', $lines[2]);
    }

    /** 自适应列不截断：调用点 / 路径这类列被截了，就等于把"该改哪一行"删了。 */
    public function testAdaptiveColumnsKeepEverything(): void
    {
        $long = 'PageShell > Card > Column > Expanded > Table > TableBox > Column > SizedBox';
        $lines = Table::render(
            [['t' => 'n', 'w' => 3, 'r' => true], ['t' => '路径']],
            [['1', $long], ['2', 'a']],
        );

        $this->assertStringContainsString($long, $lines[1]);
        $this->assertStringEndsWith('a', $lines[2], '短的那行结束在最后一格');
    }

    /** 定宽列超宽就截，且截出来的宽度不超过声明值（含 `…`）。 */
    public function testFixedColumnsAreClippedToTheirWidth(): void
    {
        $lines = Table::render(
            [['t' => '类型', 'w' => 6]],
            [['Text'], ['非常长的控件类型名']],
        );

        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(6 + 2, Fit::width($line), '缩进 2 + 列宽 6');
        }
        $this->assertStringEndsWith('…', $lines[2]);
    }

    public function testHeaderIsSkippedWhenNoColumnHasATitle(): void
    {
        $lines = Table::render(
            [['w' => 0], ['w' => 0]],
            [['settle', '27.50ms']],
        );

        $this->assertSame(['  settle 27.50ms'], $lines, '没有标题就不该凭空多一行');
    }

    public function testLinesHaveNoTrailingSpaces(): void
    {
        $lines = Table::render(
            [['t' => 'a', 'w' => 6], ['t' => 'b', 'w' => 4]],
            [['x', '']],
        );

        foreach ($lines as $line) {
            $this->assertSame(\rtrim($line), $line);
        }
    }

    /**
     * 列声明写少了，多出来的格子**原样挂在行尾**而不是被吞掉。
     *
     * 静默丢掉会让"这张表少了一列"看起来像"数据本来就没有" —— 证据面最不能出的错。
     */
    public function testExtraCellsAreShownNotSwallowed(): void
    {
        $lines = Table::render(
            [['t' => 'a', 'w' => 2]],
            [['x', '多余']],
        );

        $this->assertStringContainsString('多余', $lines[1]);
    }
}
