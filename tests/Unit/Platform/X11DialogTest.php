<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Platform;

use Drift\Platform\X11\X11Dialog;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * X11 对话框的纯逻辑判据：筛选表、单选行尾与多选拆行。
 *
 * ★ 不碰 proc_open / X server：真接线由 probes/linux_x11 的确定性假助手验。
 */
final class X11DialogTest extends TestCase
{
    #[Test]
    public function testFilterTextKeepsDisplayAndPattern(): void
    {
        $this->assertSame(
            "Markdown 文件 (*.md)\t*.md\n所有文件 (*.*)\t*.*",
            X11Dialog::filterText([
                ['Markdown 文件 (*.md)', '*.md'],
                ['坏项'],
                ['所有文件 (*.*)', '*.*'],
            ]),
        );
    }

    #[Test]
    public function testFilterArgsDoNotDuplicatePattern(): void
    {
        $args = X11Dialog::filterArgs("Markdown 文件 (*.md)\t*.md\n文本\t*.txt");

        $this->assertSame([
            '--file-filter=Markdown 文件 (*.md)',
            '--file-filter=文本 (*.txt)',
        ], $args);
    }

    #[Test]
    public function testNormalizeSinglePathStripsOnlyTrailingLineEndings(): void
    {
        $this->assertSame(
            '/tmp/a b.txt',
            X11Dialog::normalizeSinglePath("/tmp/a b.txt\r\n"),
        );
        $this->assertSame('', X11Dialog::normalizeSinglePath("\n"));
    }

    #[Test]
    public function testSplitPathListKeepsOrderAndDoesNotDeduplicate(): void
    {
        $this->assertSame(
            ['/tmp/a.txt', '/tmp/a.txt', '/tmp/b.txt'],
            X11Dialog::splitPathList("/tmp/a.txt\n/tmp/a.txt\r\n/tmp/b.txt\n"),
        );
    }
}
