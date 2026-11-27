<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\Debug\Session\EvidenceBag;
use Drift\Debug\Session\FrameRecord;
use Drift\DevTools\Session\Query;
use Drift\Debug\Session\Recorder;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * A67 判据：`tree(frame)` 是**那一帧的树**，不是"现在的注册表 + 那帧的几何"。
 *
 * 旧口径下 `--frame=40` 会给出「现在所有节点」并只把几何对齐到 40 帧：第 40 帧之后
 * 才出生的节点照样列出来（几何一栏空着），第 40 帧还在、之后被卸载的节点却一个都
 * 看不到。读者由此得出的结论是"那会儿树就长这样"，而它既不是那会儿的，也不是现在
 * 的 —— 是两者的交集，且没有任何一栏说自己不可信。
 *
 * 这组判据只钉一件事：**节点在不在，由它自己的生命周期说了算**。
 * 出生帧 / 离开帧各一条，两个方向都要咬住；`rebuilt` 必须来自该帧的记录，不能从
 * "最近见到帧"倒推（两者在"这帧没重建但它上一帧刚重建过"时正好相撞）。
 */
final class TreeReplayTest extends SmokeTestCase
{
    /**
     * 一棵会变的树：
     *
     * ```
     * 1 Page ─┬─ 2 Column ─┬─ 3 Text「启动项」  活到第 3 帧，第 4 帧起不在
     *         │            └─ 4 Text「后来的」  第 4 帧才出生
     *         └─ 5 Text「临时」  第 2 帧出生、第 3 帧离开，之后被回收
     * ```
     *
     * 帧账：第 1 帧建 1/2/3，第 4 帧建 4，其余帧什么都不建。
     */
    private static function recorder(): Recorder
    {
        $frames = [];
        foreach ([1 => [1, 2, 3], 2 => [], 3 => [], 4 => [4], 5 => [], 6 => []] as $id => $rebuilt) {
            $frames[] = new FrameRecord(
                $id,
                (float) $id * 16.0,
                1.0,
                [],
                $rebuilt,
                [],
                [],
                false,
                false,
            );
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

    /** @param array<string, mixed> $answer @return list<int> */
    private static function ids(array $answer): array
    {
        return \array_map(static fn(array $r): int => (int) $r['id'], $answer['rows']);
    }

    public function testReplayExcludesNodesNotYetBorn(): void
    {
        $query = new Query(self::recorder());

        check('第 1 帧只有最先建的那三个', self::ids($query->tree(frame: 1)) === [1, 2, 3], 'ids=' . \json_encode(self::ids($query->tree(frame: 1))));
        check(
            '第 1 帧看不到第 4 帧才出生的节点',
            !\in_array(4, self::ids($query->tree(frame: 1)), true),
            'ids=' . \json_encode(self::ids($query->tree(frame: 1))),
        );
        check('第 1 帧也看不到第 2 帧才出生的临时节点', !\in_array(5, self::ids($query->tree(frame: 1)), true));
        check(
            '到了第 4 帧，新节点才出现',
            \in_array(4, self::ids($query->tree(frame: 4)), true),
            'ids=' . \json_encode(self::ids($query->tree(frame: 4))),
        );

        $this->flushChecks();
    }

    public function testReplayExcludesNodesAlreadyGone(): void
    {
        $query = new Query(self::recorder());

        check('第 3 帧「启动项」还在', \in_array(3, self::ids($query->tree(frame: 3)), true));
        check(
            '第 4 帧它已经不在树上了',
            !\in_array(3, self::ids($query->tree(frame: 4)), true),
            'ids=' . \json_encode(self::ids($query->tree(frame: 4))),
        );
        check('第 2 帧临时节点还在', \in_array(5, self::ids($query->tree(frame: 2)), true));
        check('第 3 帧临时节点已离开', !\in_array(5, self::ids($query->tree(frame: 3)), true));

        $this->flushChecks();
    }

    /**
     * 已回收节点在它活着的那几帧必须画得出来，否则回放早期帧时会凭空少一大块，
     * 而缺的那一块正好是"后来消失了的东西" —— 回放最想看的恰恰是它。
     */
    public function testRecycledNodesAreStillDrawnInTheFramesTheyLived(): void
    {
        $answer = (new Query(self::recorder()))->tree(frame: 2);

        $row = null;
        foreach ($answer['rows'] as $candidate) {
            if ((int) $candidate['id'] === 5) {
                $row = $candidate;
                break;
            }
        }

        check('已回收节点出现在它活着的帧里', $row !== null, 'ids=' . \json_encode(self::ids($answer)));
        check('并且标出它是回收节点', ($row['evicted'] ?? false) === true);
        check('它照常挂在父下，不是被扔到根上', ($row['depth'] ?? -1) === 2 && ($row['parent'] ?? 0) === 2);
        check('它的离开帧也一并给出', ($row['goneAt'] ?? null) === 3, 'goneAt=' . \json_encode($row['goneAt'] ?? null));

        $this->flushChecks();
    }

    /**
     * `rebuilt` 必须来自该帧的记录。
     *
     * 反例：用"最近见到帧 == 目标帧"倒推。节点 2 活到第 6 帧（`s=6`），而第 6 帧
     * 一个节点都没重建 —— 倒推会说它重建了，实际没有。第 4 帧相反：节点 4 的
     * `s=4` 且该帧真的建了它，倒推歪打正着。
     */
    public function testRebuildFactsComeFromTheFrameNotFromLastSeen(): void
    {
        $query = new Query(self::recorder());

        $byId = [];
        foreach ($query->tree(frame: 6)['rows'] as $row) {
            $byId[(int) $row['id']] = $row;
        }
        check('第 6 帧什么都没建，活到最后的节点也不算重建', ($byId[2]['rebuilt'] ?? null) === false, 'rebuilt=' . \json_encode($byId[2]['rebuilt'] ?? null));
        check('同一帧里所有节点一致', ($byId[1]['rebuilt'] ?? null) === false && ($byId[4]['rebuilt'] ?? null) === false);

        $byId = [];
        foreach ($query->tree(frame: 4)['rows'] as $row) {
            $byId[(int) $row['id']] = $row;
        }
        check('第 4 帧确实建了新节点', ($byId[4]['rebuilt'] ?? null) === true);
        check('没被建的节点是 false 而不是 null', ($byId[2]['rebuilt'] ?? null) === false, 'rebuilt=' . \json_encode($byId[2]['rebuilt'] ?? null));

        $this->flushChecks();
    }

    /**
     * 出生帧未知（旧证据包、或元数据已被超出 TTL 的回收抹掉）时不许猜。
     *
     * 无法证明"那时它不存在"，所以仍然列出来；但整张表的可回放性必须自报为不完整，
     * 否则读者会把"我猜的"当成"记录里写的"。
     */
    public function testUnknownBirthIsReportedInsteadOfGuessed(): void
    {
        $recorder = Recorder::hydrate(new EvidenceBag(
            nodes: [
                1 => ['p' => -1, 'c' => 'Page', 'x' => false, 's' => 5],
                2 => ['p' => 1, 'c' => 'Column', 'x' => true, 's' => 5, 'f' => 4],
            ],
            frames: [new FrameRecord(5, 80.0, 1.0, [], [], [], [], false, false)],
        ));
        $answer = (new Query($recorder))->tree(frame: 2);

        $byId = [];
        foreach ($answer['rows'] as $row) {
            $byId[(int) $row['id']] = $row;
        }
        check(
            '出生帧未知的节点仍然列出，标 null',
            \array_key_exists('bornAt', $byId[1]) && $byId[1]['bornAt'] === null,
            'bornAt=' . \json_encode($byId[1]['bornAt'] ?? 'missing'),
        );
        check('出生帧已知的节点照常被帧过滤掉', !isset($byId[2]), '第 2 帧时它还没出生');
        check('可回放性自报为不完整', ($answer['coverage']['lifecycle']['complete'] ?? true) === false);
        check(
            '并且说清有几条出生帧未知',
            ($answer['coverage']['lifecycle']['unknownBirth'] ?? 0) === 1,
            'lifecycle=' . \json_encode($answer['coverage']['lifecycle'] ?? null),
        );

        $this->flushChecks();
    }

    public function testFrameOutsideTheRecordingIsRefusedNotClamped(): void
    {
        $query = new Query(self::recorder());

        $future = $query->tree(frame: 99);
        check('未来帧不是"空树"而是明确拒绝', ($future['reason'] ?? '') === 'frame-not-recorded', 'reason=' . \json_encode($future['reason'] ?? null));
        check('拒绝时不给行', ($future['rows'] ?? [1]) === []);

        $this->flushChecks();
    }

    /**
     * 不给帧时行为不变：仍取几何窗口最新帧（没有几何则退化成当前帧）。
     * 这条是**兼容判据** —— 升级 `frame` 语义不能顺手改掉默认路径。
     */
    public function testDefaultFrameStillResolvesToTheLatestRecordedFrame(): void
    {
        $answer = (new Query(self::recorder()))->tree();

        check('默认落在最后一帧', ($answer['frame'] ?? 0) === 6, 'frame=' . \json_encode($answer['frame'] ?? null));
        check('树是第 6 帧的样子', self::ids($answer) === [1, 2, 4], 'ids=' . \json_encode(self::ids($answer)));

        $this->flushChecks();
    }
}
