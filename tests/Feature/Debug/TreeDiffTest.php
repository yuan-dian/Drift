<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\Debug\Session\EvidenceBag;
use Drift\Debug\Session\FrameRecord;
use Drift\DevTools\Session\Query;
use Drift\Debug\Session\Recorder;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * A70 判据：`treeDiff(base, frame)` 给的是 **(base, frame] 的结构事件**，
 * 不是"两次 tree() 相减"，也不是"现在注册表里谁 s 落在这个区间"。
 *
 * 与 A67 共用同一份生命周期两端（`f` / `g`），但多了一层左开右闭的区间语义：
 * `diff(A,B)` 与 `diff(B,C)` 必须不重不漏。缺出生帧 / 早于保留期的对比必须自报
 * 不完整，而不是交一张看起来很干净的空表。
 */
final class TreeDiffTest extends SmokeTestCase
{
    /**
     * ```
     * 1 Page ─┬─ 2 Column ─┬─ 3 Text  活到第 3 帧，第 4 帧起不在
     *         │            └─ 4 Text  第 4 帧才出生
     *         └─ 5 Text    第 2 帧出生、第 3 帧离开（已回收）
     * ```
     */
    private static function recorder(): Recorder
    {
        $frames = [];
        foreach ([1 => [1, 2, 3], 2 => [], 3 => [], 4 => [4], 5 => [], 6 => []] as $id => $rebuilt) {
            $frames[] = new FrameRecord($id, (float) $id * 16.0, 1.0, [], $rebuilt, [], [], false, false);
        }

        return Recorder::hydrate(new EvidenceBag(
            nodes: [
                1 => ['p' => -1, 'c' => 'Page', 'x' => false, 's' => 6, 'f' => 1],
                2 => ['p' => 1, 'c' => 'Column', 'x' => true, 's' => 6, 'f' => 1],
                3 => ['p' => 2, 'c' => 'Text', 'x' => false, 's' => 3, 'f' => 1],
                4 => ['p' => 2, 'c' => 'Text', 'x' => false, 's' => 4, 'f' => 4],
            ],
            evicted: [5 => ['p' => 2, 'c' => 'Text', 'x' => false, 'f' => 2, 's' => 2, 'g' => 3]],
            gone: [3 => 4],
            frames: $frames,
        ));
    }

    /** @return list<array{int, string, int}> id / change / at */
    private static function events(array $answer): array
    {
        $out = [];
        foreach ($answer['rows'] ?? [] as $row) {
            $out[] = [(int) $row['id'], (string) $row['change'], (int) $row['at']];
        }
        usort($out, static fn(array $a, array $b): int => $a <=> $b);

        return $out;
    }

    private static function diff(int $base, ?int $frame = null, int $limit = 200): array
    {
        return (new Query(self::recorder()))->treeDiff($base, $frame, $limit);
    }

    public function testBirthUsesLifetimeNotLastSeen(): void
    {
        $answer = self::diff(2, 4);
        $born = \array_values(\array_filter(
            self::events($answer),
            static fn(array $e): bool => $e[1] === 'born',
        ));
        check(
            '只有第 4 帧出生的节点算新增',
            $born === [[4, 'born', 4]],
            'events=' . \json_encode(self::events($answer)),
        );
        check('第 2 帧出生的临时节点不在 (2,4] 里', !\in_array([5, 'born', 2], $born, true));
        check('第 1 帧就在的节点不算新增', !\in_array([1, 'born', 1], $born, true));
        $this->flushChecks();
    }

    public function testGoneEventsComeFromTheLeaveFrame(): void
    {
        $answer = self::diff(2, 4);
        $events = self::events($answer);
        check('第 3 帧离开的临时节点给了离开事件', \in_array([5, 'gone', 3], $events, true), \json_encode($events));
        check('第 4 帧离开的节点也在', \in_array([3, 'gone', 4], $events, true), \json_encode($events));
        check('离开帧恰在边界内，没被右端点漏掉', ($answer['coverage']['gone'] ?? 0) === 2);
        $this->flushChecks();
    }

    public function testAdjacentIntervalsPartitionTheLongOne(): void
    {
        $long = self::events(self::diff(1, 5));
        $left = self::events(self::diff(1, 3));
        $right = self::events(self::diff(3, 5));
        $joined = \array_merge($left, $right);
        sort($long);
        sort($joined);
        check('diff(1,5) 与两段相邻区间拼起来逐值相同', $long === $joined, \json_encode([$long, $joined]));
        check('第 3 帧的事件只算在左段', \in_array([5, 'gone', 3], $left, true) && !\in_array([5, 'gone', 3], $right, true));
        $this->flushChecks();
    }

    public function testRebuildCountsComeFromFramesInTheInterval(): void
    {
        $answer = self::diff(3, 4);
        $rows = [];
        foreach ($answer['rows'] as $row) {
            if (($row['change'] ?? '') === 'rebuilt') {
                $rows[(int) $row['id']] = $row;
            }
        }
        check('第 4 帧重建的节点聚合出一条事件', isset($rows[4]), 'rows=' . \json_encode($answer['rows']));
        check('次数与首尾帧一致', ($rows[4]['count'] ?? 0) === 1 && ($rows[4]['at'] ?? 0) === 4 && ($rows[4]['lastAt'] ?? 0) === 4);
        check('重建次数进了 coverage', ($answer['coverage']['rebuilt'] ?? 0) === 1);
        check('窗口内没有缺帧', ($answer['coverage']['rebuiltComplete'] ?? false) === true);
        $this->flushChecks();
    }

    public function testMissingFrameRecordsAreReportedInsteadOfAssumingNoRebuilds(): void
    {
        // 第 3 帧不在窗口里：它可能是空帧，也可能是被裁掉的历史，两种都不能当成
        // "没有重建"。区间 (2,5] 缺这一帧，必须把 complete 拉下来。
        $frames = [];
        foreach ([1, 2, 4, 5] as $id) {
            $frames[] = new FrameRecord($id, (float) $id * 16.0, 1.0, [], [], [], [], false, false);
        }
        $recorder = Recorder::hydrate(new EvidenceBag(
            nodes: [1 => ['p' => -1, 'c' => 'Page', 'x' => false, 's' => 5, 'f' => 1]],
            frames: $frames,
        ));
        $answer = (new Query($recorder))->treeDiff(2, 5);
        check('缺帧数如实给出', ($answer['coverage']['framesMissing'] ?? 0) === 1, \json_encode($answer['coverage']));
        check('重建覆盖自报不完整', ($answer['coverage']['rebuiltComplete'] ?? true) === false);
        check('整份对比自报不完整', ($answer['coverage']['complete'] ?? true) === false);
        check('实际查到的帧数只算窗口里的', ($answer['coverage']['framesChecked'] ?? 0) === 2);
        $this->flushChecks();
    }

    public function testRebuiltNodeWithoutIdentityKeepsItsId(): void
    {
        $recorder = Recorder::hydrate(new EvidenceBag(
            nodes: [1 => ['p' => -1, 'c' => 'Page', 'x' => false, 's' => 2, 'f' => 1]],
            frames: [
                new FrameRecord(1, 16.0, 1.0, [], [], [], [], false, false),
                new FrameRecord(2, 32.0, 1.0, [], [9], [], [], false, false),
            ],
        ));
        $answer = (new Query($recorder))->treeDiff(1, 2);
        $row = null;
        foreach ($answer['rows'] as $candidate) {
            if ((int) $candidate['id'] === 9) {
                $row = $candidate;
                break;
            }
        }
        check('帧记录里的节点没有被丢掉', $row !== null, \json_encode($answer['rows']));
        check('不编造类型', ($row['type'] ?? 'x') === '');
        check('标出名字未知', ($row['known'] ?? true) === false);
        check('未知条数进了 coverage', ($answer['coverage']['unknownRebuilt'] ?? 0) === 1);
        $this->flushChecks();
    }

    public function testUnknownBirthIsCountedInsteadOfGuessed(): void
    {
        $recorder = Recorder::hydrate(new EvidenceBag(
            nodes: [
                1 => ['p' => -1, 'c' => 'Page', 'x' => false, 's' => 5],
                2 => ['p' => 1, 'c' => 'Column', 'x' => true, 's' => 5, 'f' => 4],
            ],
            frames: [new FrameRecord(5, 80.0, 1.0, [], [], [], [], false, false)],
        ));
        $answer = (new Query($recorder))->treeDiff(2, 5);
        $events = self::events($answer);
        check('缺出生帧的节点没被当成新增', !\in_array([1, 'born', 0], $events, true), \json_encode($events));
        check('出生帧已知的节点照常被计入', \in_array([2, 'born', 4], $events, true), \json_encode($events));
        check('未知出生帧有条数', ($answer['coverage']['unknownBirth'] ?? 0) === 1);
        check('整份对比自报不完整', ($answer['coverage']['complete'] ?? true) === false);
        $this->flushChecks();
    }

    public function testRangeOutsideTheRecordingIsRefused(): void
    {
        $future = self::diff(2, 99);
        check('未来帧不是空结果而是明确拒绝', ($future['reason'] ?? '') === 'frame-not-recorded');
        check('拒绝时不给行', ($future['rows'] ?? [1]) === []);

        $reversed = (new Query(self::recorder()))->treeDiff(5, 3);
        check('起点晚于终点报区间无效', ($reversed['reason'] ?? '') === 'invalid-range');

        $latest = self::diff(2, 0);
        check('显式 0 与 tree() 一样取最新帧', ($latest['frame'] ?? 0) === 6, \json_encode($latest['frame'] ?? null));
        $this->flushChecks();
    }

    public function testOlderThanRetentionIsMarkedIncomplete(): void
    {
        $frames = [];
        for ($i = 1; $i <= 130; ++$i) {
            $frames[] = new FrameRecord($i, (float) $i * 16.0, 1.0, [], [], [], [], false, false);
        }
        $recorder = Recorder::hydrate(new EvidenceBag(
            nodes: [1 => ['p' => -1, 'c' => 'Page', 'x' => false, 's' => 130, 'f' => 1]],
            frames: $frames,
        ));
        $answer = (new Query($recorder))->treeDiff(1, 130);
        check('起点早于节点记录保留期', ($answer['coverage']['withinRetention'] ?? true) === false);
        check('保留期给出的是实际帧号', ($answer['coverage']['retentionFloor'] ?? 0) === 10);
        check('整份对比自报不完整', ($answer['coverage']['complete'] ?? true) === false);
        $this->flushChecks();
    }

    public function testLimitReportsOmissionWithoutPretendingCompleteness(): void
    {
        $answer = self::diff(1, 4, 1);
        check('只列一行', \count($answer['rows'] ?? []) === 1);
        check('总数仍给全', ($answer['coverage']['events'] ?? 0) > 1, 'events=' . \json_encode($answer['coverage']['events'] ?? null));
        check('省掉多少行明说', ($answer['coverage']['omitted'] ?? 0) === ($answer['coverage']['events'] ?? 0) - 1);
        $this->flushChecks();
    }
}
