<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Debug;

use Drift\Dev\Debug\View\Finding;
use Drift\Dev\Debug\View\Rules\Severity;
use Drift\Dev\Debug\View\Targets;
use Drift\Dev\Debug\View\TargetsText;
use PHPUnit\Framework\TestCase;

/**
 * 跨窗口投影：并列、不 join、不把不可达说成没问题。
 *
 * 这里刻意让两个会话用**同一个指纹、同一批帧号、同样的节点号**——若实现按这些
 * 数字合并，两行会变成一行，而报告看起来完全正常（A59 防的就是这一形状）。
 */
final class TargetsViewTest extends TestCase
{
    /** @param list<Finding> $findings */
    private function row(string $id, string $target, string $startedAt, array $findings): array
    {
        return [
            'id' => $id,
            'target' => $target,
            'pid' => 1234,
            'port' => 5000,
            'startedAt' => $startedAt,
            'state' => 'ok',
            'error' => null,
            'facts' => [
                'label' => $target,
                'window' => ['from' => 1, 'to' => 12, 'count' => 12, 'capacity' => 600],
                'digest' => ['digest' => 'same-digest-for-both'],
                'frames' => [
                    ['id' => 1, 'cost' => 5.0],
                    ['id' => 7, 'cost' => 24.3],
                ],
            ],
            'brief' => [
                'findings' => $findings,
                'coverage' => ['complete' => true],
            ],
        ];
    }

    private function finding(string $rule, Severity $severity, string $address): Finding
    {
        return new Finding(
            $rule,
            $rule,
            $address,
            'metric',
            'threshold',
            '重跑一次',
            $severity,
        );
    }

    public function testTwoSessionsStaySeparateEvenWithIdenticalIdsAndFrames(): void
    {
        $first = $this->row('aaa11111', 'table', '2026-01-01T00:00:00+00:00', [
            $this->finding('slow-frame', Severity::Error, 'frame:7'),
        ]);
        $second = $this->row('bbb22222', 'table', '2026-01-01T00:00:01+00:00', [
            $this->finding('repeat-rebuild', Severity::Warning, 'node:7'),
            $this->finding('clean', Severity::Info, 'window:1-12'),
        ]);

        $answer = Targets::project([$first, $second]);

        self::assertCount(2, $answer['targets']);
        self::assertSame(2, $answer['summary']['connected']);
        self::assertSame(3, $answer['summary']['findings']);
        self::assertSame(['error' => 1, 'warning' => 1, 'info' => 1], $answer['summary']['bySeverity']);
        self::assertSame('aaa11111', $answer['targets'][0]['id']);
        self::assertSame(1, $answer['targets'][0]['findings']);
        self::assertSame('bbb22222', $answer['targets'][1]['id']);
        self::assertSame(2, $answer['targets'][1]['findings']);
        // 最慢帧各自从自己的 frames 算，不是一个窗口的聚合。
        self::assertSame(['frame' => 7, 'cost' => 24.3], $answer['targets'][0]['worst']);
    }

    public function testUnreachableSessionDoesNotEraseTheHealthyOne(): void
    {
        $good = $this->row('aaa11111', 'table', '2026-01-01T00:00:00+00:00', [
            $this->finding('slow-frame', Severity::Error, 'frame:7'),
        ]);
        $bad = $this->row('deadbeef', 'ghost', '2026-01-01T00:00:01+00:00', []);
        $bad['state'] = 'unreachable';
        $bad['error'] = '宿主已退出（pid 9999）';
        $bad['facts'] = null;
        $bad['brief'] = null;

        $answer = Targets::project([$good, $bad]);

        self::assertSame(1, $answer['summary']['connected']);
        self::assertSame(1, $answer['summary']['unreachable']);
        self::assertSame(1, $answer['summary']['findings']);
        self::assertSame('unreachable', $answer['targets'][1]['state']);
        self::assertSame('宿主已退出（pid 9999）', $answer['targets'][1]['error']);
        self::assertNull($answer['targets'][1]['worst']);
        self::assertSame(1, $answer['targets'][0]['findings']);
    }

    public function testOrderingIsIndependentOfInputOrder(): void
    {
        $early = $this->row('aaa11111', 'table', '2026-01-01T00:00:00+00:00', []);
        $late = $this->row('bbb22222', 'table', '2026-01-01T00:00:01+00:00', []);

        self::assertSame(
            Targets::project([$early, $late]),
            Targets::project([$late, $early]),
        );
    }

    public function testIncompleteCoverageIsReportedNotHidden(): void
    {
        $row = $this->row('aaa11111', 'table', '2026-01-01T00:00:00+00:00', []);
        $row['brief']['coverage'] = ['complete' => false];

        $answer = Targets::project([$row]);

        self::assertSame(1, $answer['summary']['incomplete']);
        self::assertFalse($answer['targets'][0]['complete']);
    }

    public function testTextKeepsUnreachableRowsWhenBudgetIsTight(): void
    {
        $rows = [
            $this->row('aaa11111', 'first', '2026-01-01T00:00:00+00:00', []),
            $this->row('bbb22222', 'second', '2026-01-01T00:00:01+00:00', []),
            $this->row('ccc33333', 'third', '2026-01-01T00:00:02+00:00', []),
        ];
        $bad = $this->row('deadbeef', 'ghost', '2026-01-01T00:00:03+00:00', []);
        $bad['state'] = 'unreachable';
        $bad['error'] = '宿主已退出（pid 9999）';
        $bad['facts'] = null;
        $bad['brief'] = null;
        $rows[] = $bad;

        $projection = Targets::project($rows);
        $first = TargetsText::render($projection, 4, 100);

        // 表头 + 2 行会话 + 裁剪账。不可达那行不能被预算挤掉。
        self::assertCount(4, $first['lines']);
        self::assertStringContainsString('不可达：宿主已退出', \implode("\n", $first['lines']));
        self::assertSame(2, $first['omitted']['targets']);
        self::assertSame($first, TargetsText::render($projection, 4, 100));
    }
}
