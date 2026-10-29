<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Input;

use Drift\Input\FilesDroppedEvent;
use Drift\Input\InputEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `FilesDroppedEvent` 的载荷形状（A5-②，§27）。
 *
 * ★ 用 `TestCase` 而不是 `SmokeTestCase`：纯值对象，没有帧、没有夹具，
 *   直接 `assert*` 最直白（同 `AlignmentTest` 的裁决）。
 *
 * ## 为什么值得单测
 * 载荷跨 ABI 只以 `'\n'` 分隔的**字符串**形态存在（数组字段是 AOT 坑），
 * 所以"字符串 → 列表"这一步是唯一有分支的地方，必须钉死：空串行为、多路径
 * 切分、count 与列表一致、UTF-8 不受损。
 */
final class FilesDroppedEventTest extends TestCase
{
    #[Test]
    public function testSplitsPathsOnNewlineAndKeepsRawText(): void
    {
        $raw = "C:/a.md\nD:/b.txt\nE:/c.log";
        $e = new FilesDroppedEvent($raw, 12.5, 34.0);

        self::assertSame(['C:/a.md', 'D:/b.txt', 'E:/c.log'], $e->paths());
        self::assertSame(3, $e->fileCount());
        self::assertSame($raw, $e->pathsText, '原始串必须原样保留，供跨 ABI 之后排查');
        self::assertSame(12.5, $e->x);
        self::assertSame(34.0, $e->y);
    }

    #[Test]
    public function testEmptyPayloadMeansNoFiles(): void
    {
        $e = new FilesDroppedEvent('', 0.0, 0.0);

        self::assertSame([], $e->paths(), '空串不能炸出 [""]，否则"零个文件"会被当成"一个空路径"');
        self::assertSame(0, $e->fileCount());
    }

    #[Test]
    public function testSinglePathHasNoSeparator(): void
    {
        $e = new FilesDroppedEvent('C:/only.md', 5.0, 6.0);

        self::assertSame(['C:/only.md'], $e->paths());
        self::assertSame(1, $e->fileCount());
    }

    #[Test]
    public function testUtf8PathsSurviveTheSplit(): void
    {
        // 壳用 WideCharToMultiByte(CP_UTF8) 转换，中文路径必须整段透传
        $e = new FilesDroppedEvent("D:/文档/合同.md\nD:/图片/a.png", 1.0, 2.0);

        self::assertSame(['D:/文档/合同.md', 'D:/图片/a.png'], $e->paths());
        self::assertSame(2, $e->fileCount());
    }

    #[Test]
    public function testItIsAnInputEvent(): void
    {
        self::assertInstanceOf(InputEvent::class, new FilesDroppedEvent('x', 0.0, 0.0));
    }
}
