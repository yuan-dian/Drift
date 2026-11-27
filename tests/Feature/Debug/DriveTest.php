<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\DevTools\App\DebugSession;
use Drift\Debug\Session\Script;
use Drift\Debug\Session\Step;
use Drift\DevTools\Host\Drive;
use Drift\DevTools\Host\Plan;
use Drift\DevTools\Host\Runner;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 驱动本体：推一步、停下、接着跑。
 *
 * ## 为什么这些能单测，而 `LiveHostTest` 那几条不能
 *
 * 它们判的是**驱动自己的账**：`--at` 停在第几帧、单步之后 `reason` 是什么、
 * 限速锚点有没有重置。这三件事与"消息怎么送到"无关，起子进程只会让一次红
 * 分不清是驱动算错还是协议走错。
 *
 * 端到端的部分（暂停之后帧真的不动了）留在 `LiveHostTest` —— 那一条恰恰是
 * "宿主有没有认真读状态"，在单测里测不到。
 */
final class DriveTest extends SmokeTestCase
{
    /** @var list<DebugSession> 每个用例造的会话，收尾时卸录制器 */
    private array $sessions = [];

    protected function tearDown(): void
    {
        foreach ($this->sessions as $session) {
            $session->close();
        }
        $this->sessions = [];

        parent::tearDown();
    }

    /** 造一个会话。`$script` 的步骤是驱动要推的东西，会话只认它的目标与视口。 */
    private function open(Script $script): DebugSession
    {
        $opened = Runner::open($script->target, $script->width, $script->height, $script->meta(), $script->fork());
        $this->sessions[] = $opened['session'];

        return $opened['session'];
    }

    /**
     * 帧耗时约 0.16ms 的夹具。
     *
     * 不能用 `SlowFrameApplication`：它一帧 30ms，比限速的 16ms 间隔还长 ——
     * 用它测限速等于测不出来（帧本身就把间隔吃掉了）。
     */
    private function frames(int $count): Script
    {
        return Plan::build('EchoApplication', 320.0, 240.0, [], $count, 1, []);
    }

    /** 模拟宿主循环：一直推，直到会话不再是 `running`。 */
    private function drain(Drive $drive, DebugSession $session, int $max = 4000): void
    {
        for ($i = 0; $i < $max && $session->canAdvance(); ++$i) {
            $drive->advance($session);
        }
    }

    private function reason(DebugSession $session): mixed
    {
        return $session->query('state')['reason'];
    }

    /** `--at` 停在那帧，原因说得出是"观察点"而不是"有人按了暂停"。 */
    public function testAtStopsOnTheGivenFrame(): void
    {
        $script = $this->frames(5);
        $session = $this->open($script);
        $drive = new Drive($script, 3);

        $this->drain($drive, $session);

        check('停在第 3 帧', $session->lastFrameId() === 3, (string) $session->lastFrameId());
        check('状态是 paused', $session->stateName() === 'paused', $session->stateName());
        check('原因是 at', $this->reason($session) === 'at', \json_encode($this->reason($session)));
    }

    /**
     * 观察点只生效一次。
     *
     * 不这样的话 `resume` 一步都走不动（`pushed >= at` 恒成立），而表现是
     * "按了继续它却立刻又停住" —— 看不出是观察点在挡。
     */
    public function testAtDoesNotBlockAfterResume(): void
    {
        $script = $this->frames(5);
        $session = $this->open($script);
        $drive = new Drive($script, 3);

        $this->drain($drive, $session);
        $drive->resume($session);
        $this->drain($drive, $session);

        check('越过观察点跑到 finished', $session->stateName() === 'finished', $session->stateName());
        check('五帧全部推完', $session->lastFrameId() === 5, (string) $session->lastFrameId());
        check('finished 没有原因', $this->reason($session) === null, \json_encode($this->reason($session)));
    }

    /** 单步：走一帧就停，而且停的原因与"有人按了暂停"分开。 */
    public function testStepAdvancesExactlyOneFrame(): void
    {
        $script = $this->frames(10);
        $session = $this->open($script);
        $drive = new Drive($script);

        $drive->advance($session);
        $drive->pause($session);
        $before = $session->lastFrameId();

        $drive->resume($session, 1);
        $this->drain($drive, $session);

        check('只多了一帧', $session->lastFrameId() === $before + 1, $before . ' → ' . $session->lastFrameId());
        check('走完又停下', $session->stateName() === 'paused', $session->stateName());
        check('原因说得出是单步', $this->reason($session) === 'step', \json_encode($this->reason($session)));
    }

    /** 单步是 `frames` 的特例，不是另一条路：给 3 就走 3 帧。 */
    public function testResumeWithFrameLimitAdvancesThatMany(): void
    {
        $script = $this->frames(20);
        $session = $this->open($script);
        $drive = new Drive($script);

        $drive->advance($session);
        $drive->pause($session);
        $before = $session->lastFrameId();

        $drive->resume($session, 3);
        $this->drain($drive, $session);

        check('走了三帧', $session->lastFrameId() === $before + 3, $before . ' → ' . $session->lastFrameId());
        check('三帧之后停下', $session->stateName() === 'paused', $session->stateName());
    }

    /** 单步完成之后再 `resume` 不受上一次限额影响（限额必须清掉）。 */
    public function testFrameLimitDoesNotLeakIntoTheNextResume(): void
    {
        $script = $this->frames(20);
        $session = $this->open($script);
        $drive = new Drive($script);

        $drive->advance($session);
        $drive->pause($session);
        $drive->resume($session, 1);
        $this->drain($drive, $session);
        $afterStep = $session->lastFrameId();

        $drive->resume($session);
        $this->drain($drive, $session);

        check('单步之后无条件恢复能跑到底', $session->stateName() === 'finished', $session->stateName());
        check('而且从单步那一帧接着走', $session->lastFrameId() === 20, $afterStep . ' → ' . $session->lastFrameId());
    }

    /** 客户端暂停：立刻生效，原因与观察点分开。 */
    public function testPauseStopsAndSaysItWasRequested(): void
    {
        $script = $this->frames(50);
        $session = $this->open($script);
        $drive = new Drive($script);

        $drive->advance($session);
        $drive->advance($session);
        $drive->pause($session);
        $stopped = $session->lastFrameId();

        $this->drain($drive, $session);

        check('暂停之后一帧都不再推', $session->lastFrameId() === $stopped, $stopped . ' → ' . $session->lastFrameId());
        check('原因是 requested', $this->reason($session) === 'requested', \json_encode($this->reason($session)));
    }

    /**
     * 已经停在观察点上时再按暂停，不改原因。
     *
     * `at` 说"这儿有东西给你看"，`requested` 说"我按了停" —— 后者会把前者的信息
     * 抹掉，而用户下一个问题恰恰是"我设的那个观察点呢"。
     */
    public function testPauseDoesNotOverwriteTheAtReason(): void
    {
        $script = $this->frames(5);
        $session = $this->open($script);
        $drive = new Drive($script, 2);

        $this->drain($drive, $session);
        $drive->pause($session);

        check('原因还是 at', $this->reason($session) === 'at', \json_encode($this->reason($session)));
        check('帧号没退', $session->lastFrameId() === 2, (string) $session->lastFrameId());
    }

    /** 崩了要留下会话，还要说得出崩在哪一步。 */
    public function testFailureIsCrashedWithAReason(): void
    {
        $script = new Script('EchoApplication', 320.0, 240.0, []);
        $script->add(Step::frame(0.0));
        $script->add(Step::effect('没有注册的效果', [], 16.0));

        $session = $this->open($script);
        $drive = new Drive($script);
        $this->drain($drive, $session);

        check('状态是 crashed', $session->stateName() === 'crashed', $session->stateName());
        check('原因是 exception', $this->reason($session) === 'exception', \json_encode($this->reason($session)));
        check(
            '崩在第几步说得出来',
            \is_string($drive->failure()) && \str_contains($drive->failure(), '没有注册的效果'),
            \json_encode($drive->failure()),
        );
        check('崩之前那一帧还在', $session->lastFrameId() === 1, (string) $session->lastFrameId());
    }

    /**
     * 一次 `advance` 只推一步。
     *
     * 这条钉的是"可中断"的地基：一口气推到底的驱动在默认的 `--speed=0` 下从开局到
     * `finished` 读不到任何消息，于是暂停要等脚本跑完才生效（等于没有）——实测
     * 2000 帧的脚本，观察者连上时已经是 `finished`。
     */
    public function testOneAdvancePushesExactlyOneStep(): void
    {
        $script = $this->frames(30);
        $session = $this->open($script);
        $drive = new Drive($script);

        $drive->advance($session);
        check('第一次只推一帧', $session->lastFrameId() === 1, (string) $session->lastFrameId());

        $drive->advance($session);
        check('第二次多一帧', $session->lastFrameId() === 2, (string) $session->lastFrameId());

        check('而且还在 running（没顺手跑完）', $session->canAdvance(), $session->stateName());
    }

    /**
     * 恢复时重置限速锚点：暂停期间时钟照走，旧锚点会让恢复后连推几十帧去"补账"。
     *
     * 这个错特别隐蔽 —— `t` 序列与 `digest` 都不显形（`t` 来自脚本），观察者只看到
     * "恢复之后突然多了一堆帧"，然后归因到"暂停期间还在跑"。
     */
    public function testResumeResetsTheSpeedAnchor(): void
    {
        $script = $this->frames(60);
        $session = $this->open($script);
        $drive = new Drive($script, null, 1.0);

        // 推到第 2 帧（真实等待，锚点建立起来）
        $this->advanceOneFrame($drive, $session);
        $this->advanceOneFrame($drive, $session);
        $drive->pause($session);
        $paused = $session->lastFrameId();

        // 暂停 120ms：旧锚点下这是 7 帧多的"欠账"
        \usleep(120_000);

        $drive->resume($session);
        $t0 = \hrtime(true);
        while ((\hrtime(true) - $t0) / 1e6 < 40.0 && $session->canAdvance()) {
            $drive->advance($session);
        }
        $grew = $session->lastFrameId() - $paused;

        // 40ms 按实时速度最多两三帧；补账的话会一次推七八帧。
        check('恢复后 40ms 内不该补跑', $grew <= 4, "暂停在 {$paused}，40ms 后涨了 {$grew} 帧");
        check('但确实在往前走', $grew >= 1, "涨了 {$grew} 帧");
    }

    /**
     * 停着的时候 `advance` 必须什么都不做。
     *
     * 这是"不推"的**唯一**一道守卫（宿主只负责"要不要零超时轮询"，不负责拦），
     * 所以它自己要能站起来：删掉守卫，端到端那条也会红 —— 但那需要起子进程才看得出，
     * 而这条在本地就把话说清楚。
     */
    public function testAdvanceDoesNothingWhilePaused(): void
    {
        $script = $this->frames(10);
        $session = $this->open($script);
        $drive = new Drive($script);

        $drive->advance($session);
        $drive->pause($session);
        $before = $session->lastFrameId();

        $drive->advance($session);
        $drive->advance($session);

        check('暂停时 advance 一帧都不推', $session->lastFrameId() === $before, $before . ' → ' . $session->lastFrameId());
        check('状态还是暂停', $session->stateName() === 'paused', $session->stateName());
    }

    /** 一直推直到帧号真的 +1（限速会空转好几次才推一帧）。 */
    private function advanceOneFrame(Drive $drive, DebugSession $session): void
    {
        $target = $session->lastFrameId() + 1;
        for ($i = 0; $i < 2000 && $session->lastFrameId() < $target && $session->canAdvance(); ++$i) {
            $drive->advance($session);
        }
    }
}
