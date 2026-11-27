<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Debug;

use Drift\DevTools\View\Async;
use Drift\DevTools\View\Fit;
use PHPUnit\Framework\TestCase;

/**
 * 异步时间线的表格面：列宽按显示宽度算，任务名超宽要截。
 *
 * ## 为什么这条判据值得单独写
 *
 * 这张表原先自己 `sprintf('  #%-3d %-8s %-22s …')`。纯 ASCII 的数字与后端名看不出
 * 问题，一旦状态列出现中文（`在飞` / `已完成`），`%-8s` 按字节补，整行右移 —— 而
 * 屏幕上只会觉得"这一行有点长"。现在列宽由 `Table` 统一按 `mb_strwidth` 算，判据
 * 就钉**毫秒列的右边界**：它错不错位，是这张表唯一会悄悄坏掉的地方。
 */
final class AsyncViewTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private static function answer(array $rows, bool $captured = true): array
    {
        return [
            'captured' => $captured,
            'total' => \count($rows),
            'window' => ['from' => 1, 'to' => 4, 'capacity' => 200, 'inFlight' => 0],
            'rows' => $rows,
        ];
    }

    /**
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private static function row(int $id, string $state, array $over = []): array
    {
        return \array_merge([
            'id' => $id,
            'backend' => 'curl',
            'job' => 'fetch',
            'submittedAtFrame' => 1,
            'startedAtFrame' => 2,
            'settledAtFrame' => 3,
            'queueFrames' => 0,
            'pendingFrames' => 0,
            'state' => $state,
            'elapsedMs' => 12.5,
            'initiator' => 'src/Page.php:42',
        ], $over);
    }

    /** 中文状态不会把后面的列推走：所有数据行的毫秒值右边界相同。 */
    public function testWideStateDoesNotShiftTheLatencyColumn(): void
    {
        $lines = Async::timeline(self::answer([
            self::row(1, 'done'),
            self::row(2, '在飞'),
            self::row(3, '已完成'),
            self::row(4, 'cancelled'),
        ]));

        $ends = [];
        foreach ($lines as $line) {
            $at = \strpos($line, 'ms');
            if ($at === false) {
                continue;
            }
            $ends[] = Fit::width(\substr($line, 0, $at + 2));
        }

        $this->assertCount(4, $ends, '四条任务各一行');
        $this->assertCount(1, \array_unique($ends), '毫秒列错位了：' . \implode(' / ', $lines));
    }

    public function testLongJobNamesAreClippedAndMarked(): void
    {
        $lines = Async::timeline(self::answer([
            self::row(1, 'done', ['job' => 'a-very-long-background-job-name-that-does-not-fit']),
        ]));

        $this->assertStringContainsString('…', \implode("\n", $lines));
    }

    /** 没有采集就明说，别让空表冒充"没有任务"。 */
    public function testUncapturedTimelineSaysSo(): void
    {
        $text = \implode("\n", Async::timeline(self::answer([], false)));

        $this->assertStringContainsString('未开启异步采集', $text);
    }

    public function testEmptyButCapturedTimelineDistinguishesItself(): void
    {
        $text = \implode("\n", Async::timeline(self::answer([])));

        $this->assertStringContainsString('没有后端调度过任务', $text);
        $this->assertStringNotContainsString('未开启异步采集', $text);
    }
}
