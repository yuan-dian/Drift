<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Debug;

use Drift\DevTools\View\FrameTable;
use PHPUnit\Framework\TestCase;

/**
 * 帧表：墙钟来源的优先级，与"挑最慢帧"的口径。
 *
 * 这一层存在的理由是**消灭两份实现**，所以判据也照着那件事写：
 *
 * - 墙钟三个来源（调用方喂的 / 帧自带的 / `cost`）必须按固定优先级取。
 *   写错顺序不会崩，只会让报告里的毫秒数与面板上的对不上 —— 而两边各自看
 *   都正常，这正是最该被钉住的那类偏差。
 * - "挑最慢的**有重建**的帧"是因果树选帧的唯一口径，挑不到必须回 `0` 而不是
 *   替调用方选一个：面板要"当下"、报告要"为什么慢"，兜底不是同一件事。
 */
final class FrameTableTest extends TestCase
{
    /**
     * @param list<int>        $ids
     * @param list<int>        $rebuilt
     * @param list<float>      $cost
     *
     * @return list<array<string, mixed>>
     */
    private function frames(array $ids, array $rebuilt, array $cost): array
    {
        $out = [];
        foreach ($ids as $i => $id) {
            $out[] = [
                'id' => $id,
                't' => (float) $i,
                'cost' => $cost[$i],
                'rebuilt' => $rebuilt[$i],
                'causes' => $rebuilt[$i],
            ];
        }

        return $out;
    }

    /** 调用方喂的墙钟优先于帧自带的，帧自带的优先于 `cost`。 */
    public function testWallPrefersFedThenOwnThenCost(): void
    {
        $frames = $this->frames([1, 2], [1, 1], [5.0, 6.0]);
        // 第 1 帧自带 wall，第 2 帧没有
        $frames[0]['wall'] = 7.0;

        $table = FrameTable::of($frames, [1 => 9.0]);

        $this->assertSame(9.0, $table->wallOf($frames[0]), '喂进来的最优先');
        $this->assertSame(6.0, $table->wallOf($frames[1]), '都没有时用 cost');

        // 不喂时，帧自带的次优先
        $this->assertSame(7.0, FrameTable::of($frames)->wallOf($frames[0]));
        $this->assertSame(6.0, FrameTable::of($frames)->wallOf($frames[1]));
    }

    /**
     * 三条墙钟来源缺一两条时，`merged()` 出来的帧表必须逐帧带 `wall`。
     *
     * 这条钉的是"上游已经合并过"那种情形：`Report::lines()` 拿到的帧表就是
     * 已经被 `ReportRunner` 合并过的，再合并一次不能把量好的值覆盖回 `cost`。
     */
    public function testMergedKeepsUpstreamWallInsteadOfOverwritingWithCost(): void
    {
        $frames = $this->frames([1, 2, 3], [1, 0, 2], [5.0, 6.0, 7.0]);
        $frames[1]['wall'] = 40.0;

        $merged = FrameTable::of($frames)->merged();

        $this->assertSame([5.0, 40.0, 7.0], \array_column($merged, 'wall'));
        // 不改入参：同一份帧表被二次合并时还得看到原值
        $this->assertArrayNotHasKey('wall', $frames[0], 'merged() 不能就地改入参');
    }

    /** 只挑重建过的帧：挂载帧常常最慢却零重建，拿它做因果树只会得到空树。 */
    public function testSlowestRebuiltSkipsFramesWithoutRebuild(): void
    {
        // 帧 1 最慢但零重建；帧 3 次慢但有重建
        $frames = $this->frames([1, 2, 3], [0, 0, 4], [100.0, 10.0, 50.0]);
        $table = FrameTable::of($frames);

        $this->assertSame(1, $table->slowest(), '不过滤时就是最慢的那帧');
        $this->assertSame(3, $table->slowest(true), '过滤后挑最慢的有重建帧');
    }

    /**
     * 挑不到就回 `0`，不替调用方兜底。
     *
     * 面板会退回最后一帧、报告会退回最慢帧 —— 两种兜底都对，但不能由这一层
     * 统一决定，否则总有一边看到不是自己想要的那一帧。
     */
    public function testSlowestReturnsZeroWhenNothingMatches(): void
    {
        $this->assertSame(0, FrameTable::of([])->slowest());
        $this->assertSame(0, FrameTable::of([])->slowest(true));
        $this->assertSame(0, FrameTable::of([])->last());

        $noRebuild = FrameTable::of($this->frames([1, 2], [0, 0], [3.0, 4.0]));
        $this->assertSame(0, $noRebuild->slowest(true), '全都没重建时回 0');
        $this->assertSame(2, $noRebuild->last(), 'last() 照常给最后一帧');
    }

    /**
     * 墙钟相等时取**先出现**的那一帧。
     *
     * 严格大于才换人，所以并列时保持帧表顺序。这条不是随便定的：面板初始帧与
     * 报告 `slowestFrame` 曾各自实现同一循环，只有比较符一致才挑出同一帧。
     */
    public function testTiesKeepTheEarlierFrame(): void
    {
        $table = FrameTable::of($this->frames([1, 2, 3], [1, 1, 1], [10.0, 10.0, 10.0]));

        $this->assertSame(1, $table->slowest());
        $this->assertSame(1, $table->slowest(true));
    }

    /** `ids()` / `walls()` 与帧表同序；`find()` 找不到回 `null`。 */
    public function testIdsWallsAndFindAgreeWithTheTable(): void
    {
        $frames = $this->frames([4, 5], [1, 2], [1.5, 2.5]);
        $table = FrameTable::of($frames, [5 => 20.0]);

        $this->assertSame([4, 5], $table->ids());
        $this->assertSame([1.5, 20.0], $table->walls());
        $this->assertSame(5, (int) $table->find(5)['id']);
        $this->assertNull($table->find(99));
    }
}
