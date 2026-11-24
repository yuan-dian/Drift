<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Debug;

use Drift\Dev\Debug\View\Props;
use PHPUnit\Framework\TestCase;

/**
 * 属性视图判据：纯函数 + 投影自带预算 + 查不到时说人话。
 *
 * 这里防的是"看起来正常的假报告"：把 10000 行数据铺满终端、把裁剪过的字段
 * 说成完整、查不到时输出一片空白 —— 三种都不报错，但读者会据此下结论。
 */
final class PropsViewTest extends TestCase
{
    public function testSameAnswerGivesByteIdenticalLines(): void
    {
        $answer = [
            'found' => true,
            'node' => 4,
            'widget' => 'Text',
            'element' => 'StatelessElement',
            'state' => null,
            'liveFrame' => 2,
            'stored' => true,
            'fields' => [
                ['owner' => 'Text', 'name' => 'content', 'value' => ['kind' => 'string', 'value' => 'hi']],
            ],
            'omitted' => 0,
            'cut' => 0,
            'cycles' => 0,
            'costMs' => 0.125,
            'window' => ['from' => 4, 'to' => 4, 'capacity' => 200, 'nodes' => 1],
        ];

        self::assertSame(Props::lines($answer), Props::lines($answer));
        $text = \implode("\n", Props::lines($answer));
        self::assertStringContainsString('节点 #4', $text);
        self::assertStringContainsString('Text（StatelessElement）', $text);
        self::assertStringContainsString('快照于第 2 帧', $text);
        self::assertStringContainsString('"hi"', $text);
    }

    public function testDetailBudgetIsReported(): void
    {
        $items = [];
        for ($i = 0; $i < 40; ++$i) {
            $items[] = ['key' => (string) $i, 'value' => ['kind' => 'scalar', 'value' => $i]];
        }
        $answer = [
            'found' => true,
            'node' => 1,
            'widget' => 'X',
            'element' => 'StatelessElement',
            'state' => null,
            'liveFrame' => 1,
            'stored' => true,
            'fields' => [
                [
                    'owner' => 'X',
                    'name' => 'rows',
                    'value' => ['kind' => 'list', 'count' => 40, 'items' => $items, 'omitted' => 0],
                ],
            ],
            'omitted' => 3,
            'cut' => 2,
            'cycles' => 1,
            'costMs' => 1.0,
            'window' => ['from' => 1, 'to' => 1, 'capacity' => 200, 'nodes' => 1],
        ];

        $lines = Props::lines($answer);
        $text = \implode("\n", $lines);
        self::assertLessThanOrEqual(30, \count($lines), '投影必须有明细预算');
        self::assertStringContainsString('明细另省略 28 项', $text);
        self::assertStringContainsString('省略 3', $text);
        self::assertStringContainsString('截断 2 处', $text);
        self::assertStringContainsString('循环引用 1 处', $text);
    }

    public function testMissNamesReasonAndWindow(): void
    {
        $answer = [
            'found' => false,
            'node' => 99,
            'reason' => 'reflection-unavailable',
            'window' => ['from' => 0, 'to' => 0, 'capacity' => 200],
        ];
        $lines = Props::lines($answer);

        self::assertCount(1, $lines);
        $text = $lines[0];
        self::assertStringContainsString('reflection-unavailable', $text);
        self::assertStringContainsString('容量 200', $text);
    }
}
