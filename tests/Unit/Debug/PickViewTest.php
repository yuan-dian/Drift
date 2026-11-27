<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Debug;

use Drift\DevTools\View\Pick;
use PHPUnit\Framework\TestCase;

/** 点选视图是纯函数：同一份答案逐字节同输出，且把无主盒与 miss 原因照实说出。 */
final class PickViewTest extends TestCase
{
    public function testHitChainLinesAreDeterministic(): void
    {
        $answer = [
            'found' => true,
            'x' => 150.0,
            'y' => 100.0,
            'liveFrame' => 3,
            'roots' => 2,
            'tree' => 0,
            'scope' => 'main',
            'ownerless' => 1,
            'hit' => ['node' => 7, 'widget' => 'Text', 'box' => 'RenderText', 'size' => ['width' => 40.0, 'height' => 16.0], 'state' => null, 'path' => 'Center > Text'],
            'chain' => [
                ['node' => 2, 'widget' => 'Center', 'box' => 'RenderProxy', 'size' => ['width' => 300.0, 'height' => 200.0], 'state' => null, 'path' => 'Center'],
                ['node' => null, 'widget' => null, 'box' => 'RenderDecor', 'size' => null, 'state' => null, 'path' => null],
                ['node' => 7, 'widget' => 'Text', 'box' => 'RenderText', 'size' => ['width' => 40.0, 'height' => 16.0], 'state' => null, 'path' => 'Center > Text'],
            ],
        ];

        $first = Pick::lines($answer);
        $second = Pick::lines($answer);
        self::assertSame($first, $second);
        self::assertSame(5, \count($first));
        self::assertStringContainsString('树 #0/2', $first[0]);
        self::assertStringContainsString('RenderProxy', $first[1]);
        self::assertStringContainsString('无 Element 认领', $first[2]);
        self::assertStringContainsString('← 命中', $first[3]);
        self::assertStringContainsString('其中 1 层无 Element 认领', $first[4]);
    }

    public function testMissReasonIsShown(): void
    {
        $lines = Pick::lines([
            'found' => false,
            'x' => 0.0,
            'y' => 0.0,
            'liveFrame' => 1,
            'roots' => 3,
            'reason' => 'no-hit',
        ]);

        self::assertCount(1, $lines);
        self::assertStringContainsString('no-hit', $lines[0]);
        self::assertStringContainsString('3 棵树', $lines[0]);
    }
}
