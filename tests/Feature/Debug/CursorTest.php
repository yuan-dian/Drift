<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\Dev\Debug\Cursor;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 游标：会话 + 帧号，两项缺一不可。
 *
 * 核心判据只有一条，但它是一条**安全**判据而不是功能判据：宿主重启后帧号从 1 重发，
 * 拿老帧号去查新会话 ⇒ 所有帧都被当已读 ⇒ 空结果。那与「真的没有新帧」长得一样，
 * 而后果是画面永远停在旧内容上。所以这里的用例几乎都在盯「会话换了会怎样」。
 */
final class CursorTest extends SmokeTestCase
{
    /** @param list<int> $ids */
    private function response(string $session, array $ids): array
    {
        return [
            'frames' => \array_map(static fn(int $id): array => ['id' => $id], $ids),
            'session' => $session,
        ];
    }

    public function testFreshStartsAtZeroWhichMeansGiveEverything(): void
    {
        $cursor = Cursor::fresh('s1');

        check('新游标从头开始', $cursor->frame === 0, (string) $cursor->frame);
        check('并记住了会话', $cursor->session === 's1', $cursor->session);
    }

    public function testAdvanceMovesToTheLastFrameOfTheResponse(): void
    {
        $cursor = Cursor::fresh('s1')->advance($this->response('s1', [7, 8, 9]));

        check('推进到响应末帧', $cursor->frame === 9, (string) $cursor->frame);
        check('会话不变', $cursor->session === 's1', $cursor->session);
    }

    public function testAdvanceNeverGoesBackwards(): void
    {
        $cursor = new Cursor('s1', 20);
        $after = $cursor->advance($this->response('s1', [5, 6]));

        check('较小的一批不把位置拉回去', $after->frame === 20, (string) $after->frame);
        check('同一个值就原样返回（省一次分配，也让 == 比较说得通）', $after === $cursor);
    }

    public function testSessionChangeSendsTheCursorBackToTheStart(): void
    {
        $cursor = new Cursor('s1', 590);
        $response = $this->response('s2', [1, 2, 3]);

        check('先能判出「这不是我的会话」', !$cursor->isFreshFor($response));

        $after = $cursor->advance($response);
        check('换会话后回到起点，而不是接着 590 往下数', $after->frame === 0, (string) $after->frame);
        check('并跟到新会话上', $after->session === 's2', $after->session);
    }

    public function testMissingSessionKeyKeepsTheOldPositionWise(): void
    {
        // 旧宿主没有会话概念。此时重置会白丢一遍历史，裸帧号逻辑仍然成立。
        $cursor = new Cursor('s1', 42);
        $response = ['frames' => [['id' => 43], ['id' => 44]]];

        check('没有 session 键时按同会话处理', $cursor->isFreshFor($response));
        check('照常往后推进', $cursor->advance($response)->frame === 44);
    }

    public function testEmptyResponseLeavesTheCursorAlone(): void
    {
        $cursor = new Cursor('s1', 12);
        $after = $cursor->advance($this->response('s1', []));

        check('没有新帧时位置不动', $after->frame === 12, (string) $after->frame);
    }

    public function testFrameNumberAloneIsAmbiguousAcrossSessions(): void
    {
        // 同一个数字 590，在两个会话里含义相反：在一场 590 帧的会话里是「读到这里」，
        // 在只有 3 帧的新会话里是「全都读过了」。`frames(since: 590)` 对后者返回空，
        // 与「真的没有新帧」完全同形 —— 这就是必须带会话令牌的理由。
        $old = new Cursor('old', 590);
        $newSession = $this->response('new', [1, 2, 3]);

        check('老帧号在新会话里是「超过一切」', 590 > \max([1, 2, 3]));
        check('令牌是唯一能判出时间线换了的东西', !$old->isFreshFor($newSession));
        check('归零后重取才知道要重新读', $old->advance($newSession)->frame === 0);
        check('原地不改 —— 重试时不该一蹦两帧', $old->frame === 590, (string) $old->frame);
    }
}
