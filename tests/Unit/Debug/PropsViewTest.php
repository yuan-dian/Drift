<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Debug;

use Drift\DevTools\View\Props;
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
            'fields' => [
                ['owner' => 'Text', 'name' => 'content', 'value' => ['kind' => 'string', 'value' => 'hi']],
            ],
            'omitted' => 0,
            'cut' => 0,
            'cycles' => 0,
            'costMs' => 0.125,
        ];

        self::assertSame(Props::lines($answer), Props::lines($answer));
        $text = \implode("\n", Props::lines($answer));
        self::assertStringContainsString('节点 #4', $text);
        self::assertStringContainsString('Text（StatelessElement）', $text);
        self::assertStringContainsString('第 2 帧现读', $text);
        self::assertStringContainsString('不进证据包', $text);
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
        ];

        $lines = Props::lines($answer);
        $text = \implode("\n", $lines);
        self::assertLessThanOrEqual(30, \count($lines), '投影必须有明细预算');
        self::assertStringContainsString('明细另省略 28 项', $text);
        self::assertStringContainsString('省略 3', $text);
        self::assertStringContainsString('截断 2 处', $text);
        self::assertStringContainsString('循环引用 1 处', $text);
    }

    public function testMissNamesReason(): void
    {
        $answer = [
            'found' => false,
            'node' => 99,
            'reason' => 'reflection-unavailable',
        ];
        $lines = Props::lines($answer);

        self::assertCount(1, $lines);
        $text = $lines[0];
        self::assertStringContainsString('reflection-unavailable', $text);
        self::assertStringNotContainsString('窗口', $text);
    }

    /**
     * 终端与 Web 是同一份行模型的两个出口，不是两套说法。
     *
     * 变异：在 `lines()` 里加一行只给终端看的内容 ⇒ 条数对不上，必红。
     */
    public function testLinesAreOnlyARenderingOfRows(): void
    {
        $answer = [
            'found' => true,
            'node' => 2,
            'widget' => 'Table',
            'element' => 'StatefulElement',
            'state' => 'TableState',
            'liveFrame' => 5,
            'fields' => [
                [
                    'owner' => 'Table',
                    'name' => 'rows',
                    'value' => [
                        'kind' => 'list',
                        'count' => 2,
                        'items' => [
                            ['key' => '0', 'value' => ['kind' => 'scalar', 'value' => 1]],
                            ['key' => '1', 'value' => ['kind' => 'scalar', 'value' => 2]],
                        ],
                        'omitted' => 0,
                    ],
                ],
                ['owner' => 'TableState', 'name' => 'page', 'value' => ['kind' => 'scalar', 'value' => 3]],
            ],
            'omitted' => 1,
            'cut' => 0,
            'cycles' => 0,
            'costMs' => 0.5,
        ];

        $view = Props::rows($answer);
        self::assertCount(2, $view['rows']);

        $details = 0;
        foreach ($view['rows'] as $row) {
            $details += \count($row['detail']);
        }
        $lines = Props::lines($answer);
        self::assertCount(1 + \count($view['rows']) + $details + \count($view['notes']), $lines);

        $text = \implode("\n", $lines);
        foreach ($view['rows'] as $row) {
            self::assertStringContainsString($row['owner'] . '::' . $row['name'], $text);
            self::assertStringContainsString($row['value'], $text);
        }
    }
}
