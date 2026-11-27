<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Debug;

use Drift\DevTools\View\Align;
use PHPUnit\Framework\TestCase;

/**
 * 对齐视图判据：投影只读事实、stale 显眼、发现可复算。
 *
 * 防的是"看起来正常"的假报告：几何回退到旧帧却印成目标帧、缺域不报原因、
 * 发现不指复核地址。三类都不报错，但读者会据此得出错误结论。
 */
final class AlignViewTest extends TestCase
{
    /** @param array<string, mixed> $over @return array<string, mixed> */
    private function answer(array $over = []): array
    {
        return \array_replace_recursive([
            'node' => 2,
            'frame' => 2,
            'path' => 'PageShell > Card',
            'found' => true,
            'causal' => ['found' => true, 'frame' => 2, 'rebuilt' => true, 'causes' => [['kind' => 'parent']]],
            'geometry' => [
                'found' => true, 'frame' => 1, 'stale' => true,
                'size' => ['w' => 800.0, 'h' => 600.0],
            ],
            'network' => ['found' => false, 'reason' => 'no-landed-request', 'window' => []],
            'windows' => [
                'frames' => 600,
                'geometry' => ['from' => 1, 'to' => 2, 'capacity' => 60],
                'network' => ['from' => 0, 'to' => 0, 'capacity' => 500],
            ],
        ], $over);
    }

    public function testStaleGeometryShowsItsRealFrame(): void
    {
        $lines = Align::lines($this->answer());
        $text = \implode("\n", $lines);
        self::assertStringContainsString('几何  @1', $text);
        self::assertStringContainsString('陈旧', $text);
        self::assertStringNotContainsString('几何  @2  ', $text);
        self::assertStringContainsString('800x600', $text);
        self::assertSame($lines, Align::lines($this->answer()), '纯函数：逐字节稳定');
    }

    public function testNonStaleGeometryDoesNotCryWolf(): void
    {
        $text = \implode("\n", Align::lines($this->answer([
            'geometry' => ['found' => true, 'frame' => 2, 'stale' => false,
                'size' => ['w' => 100.0, 'h' => 20.0]],
        ])));
        self::assertStringContainsString('几何  @2  100x20', $text);
        self::assertStringNotContainsString('陈旧', $text);
        self::assertStringNotContainsString('→', $text, '几何是本帧的，不该出"传播"发现');
    }

    public function testMissingDomainsSayWhy(): void
    {
        $text = \implode("\n", Align::lines($this->answer([
            'causal' => ['found' => false, 'reason' => 'frame-out-of-window'],
            'geometry' => ['found' => false, 'reason' => 'no-render-box'],
        ])));
        self::assertStringContainsString('因果  缺：frame-out-of-window', $text);
        self::assertStringContainsString('几何  缺：no-render-box', $text);
        self::assertStringContainsString('网络  缺：no-landed-request', $text);
    }

    public function testWindowsAreReportedPerDomain(): void
    {
        $text = \implode("\n", Align::lines($this->answer()));
        self::assertStringContainsString('帧 600', $text);
        self::assertStringContainsString('几何 1..2（60 帧）', $text);
        self::assertStringContainsString('网络 0..0（500 条）', $text);
    }

    public function testFindingPointsBackToRecheckAddress(): void
    {
        $text = \implode("\n", Align::lines($this->answer()));
        self::assertStringContainsString('why(2, 2)', $text);
        self::assertStringContainsString('优先查', $text);
    }

    public function testFindingRequiresAllThreeConditions(): void
    {
        // 有网络归因 ⇒ 不该再说"无请求落地"。
        $withNet = \implode("\n", Align::lines($this->answer([
            'network' => ['found' => true, 'frame' => 2,
                'requests' => [['request' => 7, 'method' => 'GET', 'url' => '/x', 'status' => 200]]],
        ])));
        self::assertStringNotContainsString('优先查', $withNet);
        self::assertStringContainsString('#7 GET', $withNet);

        // 没重建 ⇒ 不该出"本帧重建"的发现。
        $noRebuild = \implode("\n", Align::lines($this->answer([
            'causal' => ['found' => true, 'frame' => 2, 'rebuilt' => false, 'causes' => []],
        ])));
        self::assertStringNotContainsString('优先查', $noRebuild);
    }

    public function testUnknownAddressSaysSoWithoutFakeLines(): void
    {
        $lines = Align::lines([
            'node' => 9, 'frame' => 3, 'found' => false, 'reason' => 'unknown-node', 'path' => '',
        ]);
        self::assertCount(2, $lines);
        self::assertStringContainsString('unknown-node', $lines[1]);
        self::assertStringNotContainsString('因果', \implode("\n", $lines));
    }
}
