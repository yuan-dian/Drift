<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Platform;

use Drift\Platform\Win32\Win32Dialog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `Win32Dialog::filterText()`（2026-10-30，A5-①）。
 *
 * ★ **这个纯函数是整条链上唯一有分支的地方**：桥的 C 侧只做"分隔符 → 双 NUL"
 *   的机械转换（`buildFilterWide()`），Win32 只认双 NUL，而**双 NUL 无法断言**
 *   （字符串里全是 0x00，断错了看不出来）⇒ 判据只能落在这一层。
 *
 * ★ **不继承 `SmokeTestCase`**：纯函数、无夹具、无帧；`check()` 那套要
 *   `flushChecks()`，直接 `assert*` 会被判"一条断言都没跑"。
 *
 * ## 三条口径
 *
 * | # | 口径 | 漏了会怎样 |
 * |---|---|---|
 * | ① | 行格式是 `显示名 \t 通配符`，行间 `\n` | C 侧按错的分隔符切，筛选框里会出现一整条长名字，**不报错** |
 * | ② | **空表要给一行"所有文件"** | `lpstrFilter = NULL` 时 Win32 对话框**不显示筛选行**，用户以为对话框坏了 |
 * | ③ | 坏项**跳过**而不是 Fatal | 一处文案写错拖垮整个应用（§1.2 的中止序列不成比例） |
 */
final class Win32DialogTest extends TestCase
{
    #[Test]
    public function testEncodesEachFilterAsTabSeparatedLine(): void
    {
        $text = Win32Dialog::filterText([
            ['Markdown 文件 (*.md)', '*.md'],
            ['文本文件 (*.txt)', '*.txt'],
        ]);

        $this->assertSame("Markdown 文件 (*.md)\t*.md\n文本文件 (*.txt)\t*.txt", $text);
    }

    #[Test]
    public function testEmptyTableFallsBackToAllFiles(): void
    {
        $text = Win32Dialog::filterText([]);

        // ① 不是空串（空串会让 C 侧传 NULL ⇒ 筛选行整行消失）
        $this->assertNotSame('', $text);
        // ② 兜底行的形状与真筛选行**逐字同构**（名 + \t + 通配符）
        $this->assertSame('所有文件 (*.*)' . "\t" . '*.*', $text);
        $this->assertStringNotContainsString("\n", $text);
    }

    #[Test]
    public function testSkipsMalformedEntriesInsteadOfThrowing(): void
    {
        $text = Win32Dialog::filterText([
            ['好的一条', '*.ok'],
            'not-an-array',                 // 不是数组
            ['只有名字'],                    // 缺通配符
            ['', '*.empty'],                // 名字是空串
            ['空通配符', ''],                // 通配符是空串
            ['好的第二条', '*.ok2'],
        ]);

        $this->assertSame("好的一条\t*.ok\n好的第二条\t*.ok2", $text);
    }

    #[Test]
    public function testWholeTableMalformedStillFallsBack(): void
    {
        // 全坏 ⇒ 兜底那一行必须还在（否则用户看到的是"没有筛选"的对话框）
        $this->assertSame('所有文件 (*.*)' . "\t" . '*.*', Win32Dialog::filterText(['x', []]));
    }

    #[Test]
    public function testNoTrailingSeparator(): void
    {
        // 末尾多一个 \n 会让 C 侧多切一行空行（切成空 label + 空 pattern 的筛选项，
        // Win32 上表现为筛选框里多一条空白项）
        $text = Win32Dialog::filterText([['甲', '*.a'], ['乙', '*.b']]);

        $this->assertSame("甲\t*.a\n乙\t*.b", $text);
        $this->assertStringEndsNotWith("\n", $text);
        $this->assertStringStartsNotWith("\n", $text);
    }

    // ══════════ 多选：`\n` 连接串 → 路径表（2026-11-02，A5-① 续） ══════════
    //
    // 判据都在"形状"上，因为壳侧的拼接（`php_drift_win_open_files`）与这里的
    // 拆分是一对 —— 改一边不改另一边，症状是**多选拿到一个目录 + 若干裸文件名**，
    // 而对话框本身"成功"了。

    #[Test]
    public function testEmptyStringMeansCancelled(): void
    {
        // ★ 空串 ⇒ **空表**，不是 `['']`。后者会让调用方以为"用户选了一个叫空的
        //   文件"，接着 `fopen('')` 失败，归因跑到文件系统上。
        $this->assertSame([], Win32Dialog::splitPathList(''));
    }

    #[Test]
    public function testSinglePathIsStillAList(): void
    {
        // ★ 选一个文件时**不退化成裸字符串**（端口签名是 `array`，形状恒定）
        $this->assertSame(['C:\\pics\\a.png'], Win32Dialog::splitPathList('C:\\pics\\a.png'));
    }

    #[Test]
    public function testKeepsDialogOrder(): void
    {
        $paths = Win32Dialog::splitPathList("C:\\a.png\nD:\\b.jpg\nE:\\c.webp");

        $this->assertSame(['C:\\a.png', 'D:\\b.jpg', 'E:\\c.webp'], $paths);
    }

    #[Test]
    public function testDropsEmptyLinesButKeepsDuplicates(): void
    {
        // ① 空行丢弃（拼接边界会多出一个 `\n`）
        // ② **不去重** —— 用户两次选到同一个文件是合法的，去重是业务决定
        $paths = Win32Dialog::splitPathList("C:\\a.png\n\nC:\\a.png\n");

        $this->assertSame(['C:\\a.png', 'C:\\a.png'], $paths);
    }

    #[Test]
    public function testStripsCarriageReturnOnly(): void
    {
        // ① CRLF 的两行都要正确拆开
        // ② ★ **不 trim 整行** —— 路径尾部的空格是路径的一部分（Windows 上确实
        //    存在这种文件名，命令行工具能创建）。变异成 `trim($line)` ⇒ 第二条
        //    会丢掉尾空格，判据红。
        $paths = Win32Dialog::splitPathList("C:\\a.png\r\nC:\\b.png ");

        $this->assertSame(['C:\\a.png', 'C:\\b.png '], $paths);
    }

    #[Test]
    public function testKeepsNonAsciiAndSpacesIntact(): void
    {
        // 壳侧 `toUtf8()` 之后原样传过来 ⇒ 这里一个字节都不许动
        $one = 'C:\\用户\\我的 图片\\截图 2024.png';

        $this->assertSame([$one], Win32Dialog::splitPathList($one));
    }
}
