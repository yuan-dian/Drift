<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\DevTools\App\DebugSession;
use Drift\DevTools\Session\Digest;
use Drift\DevTools\Session\History;
use Drift\Debug\Session\Recorder;
use Drift\DevTools\Host\Runner;
use Drift\DevTools\Host\Target;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;

/**
 * S2 前置判据：**录制器属于应用，不属于进程**。
 *
 * ## 这条判据守的是什么
 *
 * 多窗口是常态，而"一个进程一份录制器"在多窗口下不是"不支持"，是**静默串证据**：
 * 第二个窗口一挂上，它首帧的全量 `build` 就把自己的节点写进第一个窗口的节点表、
 * 帧号也被推走、`digest` 跟着变 —— 而两份报告各自看起来都正常。实测两个
 * `PageHostApplication` 交错推帧：第一个会话的节点表 361 → 449。
 *
 * 所以这里不验"第二个会话被拒绝"（拒绝只是没做，不是做对了），验的是三条**逐值**：
 * 交错跑的每一份记录与它单独跑逐值相同；第二个窗口的帧不进第一个窗口的任何账；
 * 老的无主路径（测试 / bench 手工装录制器）逐值不变。
 *
 * @see \Drift\Debug\Session\Recorder::bindTo()
 */
final class MultiWindowTest extends SmokeTestCase
{
    private const float W = 400.0;
    private const float H = 300.0;

    /** @var list<DebugSession> 本用例开的会话，`tearDown` 统一关 */
    private array $sessions = [];

    protected function tearDown(): void
    {
        foreach ($this->sessions as $session) {
            $session->close();
        }
        $this->sessions = [];
        Recorder::uninstall();
        parent::tearDown();
    }

    /** @return array{session: DebugSession, surface: SurfaceProbe} */
    private function window(string $route): array
    {
        $opened = Runner::open($route, self::W, self::H, ['page' => 1]);
        $this->sessions[] = $opened['session'];

        return $opened;
    }

    /**
     * 一份记录的**可比值**：帧键、节点表、两种指纹。
     *
     * 不含 `costMs` / 时间戳：那两个是这台机器这一秒的运气，判据拿它们比会偶发假红。
     *
     * @return array<string, mixed>
     */
    private function shots(DebugSession $session): array
    {
        $recorder = $session->recorder();
        $history = new History($recorder);

        return [
            'frames' => \array_keys($recorder->frames()),
            'nodes' => $history->nodeIndex(),
            'digest' => Digest::of($recorder)['digest'],
            'causal' => Digest::ofCausal($recorder)['digest'],
        ];
    }

    /** 推 n 帧（时间戳脚本化，两次跑喂同一串数字）。 */
    private function push(DebugSession $session, int $frames): void
    {
        for ($i = 1; $i <= $frames; ++$i) {
            $session->pump(16.0 * $i);
        }
    }

    public function testTwoWindowsInterleavedRecordExactlyAsIfAlone(): void
    {
        // ① 单独跑：两份基线
        $aloneA = $this->window('table');
        $this->push($aloneA['session'], 3);
        $baseA = $this->shots($aloneA['session']);
        $aloneA['session']->close();

        $aloneB = $this->window('list');
        $this->push($aloneB['session'], 3);
        $baseB = $this->shots($aloneB['session']);
        $aloneB['session']->close();

        check(
            '两个页面确实不是同一棵（否则这条判据什么都证明不了）',
            $baseA['digest'] !== $baseB['digest'] && $baseA['nodes'] !== $baseB['nodes'],
            'A 节点 ' . \count($baseA['nodes']) . ' / B 节点 ' . \count($baseB['nodes']),
        );

        // ② 交错跑：两个会话同时开着，逐帧交替推
        $a = $this->window('table');
        $b = $this->window('list');
        check(
            '同进程里两个会话可以同时开着',
            $a['session']->sessionToken() !== $b['session']->sessionToken(),
            $a['session']->sessionToken() . ' vs ' . $b['session']->sessionToken(),
        );

        for ($i = 1; $i <= 3; ++$i) {
            $a['session']->pump(16.0 * $i);
            $b['session']->pump(16.0 * $i);
        }

        $gotA = $this->shots($a['session']);
        $gotB = $this->shots($b['session']);

        // ③ 逐值相同 —— 这一条把"谁的帧进了谁的账"钉死
        check(
            'A 交错跑 == A 单独跑（帧键 / 节点表 / 两份指纹）',
            $gotA === $baseA,
            '帧 ' . \implode(',', $gotA['frames']) . ' / 节点 ' . \count($gotA['nodes']),
        );
        check(
            'B 交错跑 == B 单独跑（帧键 / 节点表 / 两份指纹）',
            $gotB === $baseB,
            '帧 ' . \implode(',', $gotB['frames']) . ' / 节点 ' . \count($gotB['nodes']),
        );
        check(
            '两个窗口的帧号各自从 1 数起，互不推进',
            $gotA['frames'] === $baseA['frames'] && $gotB['frames'] === $baseB['frames'],
            'A=' . \implode(',', $gotA['frames']) . ' B=' . \implode(',', $gotB['frames']),
        );
    }

    public function testForeignWindowFramesDoNotTouchAnOpenSession(): void
    {
        $opened = $this->window('table');
        $this->push($opened['session'], 2);
        $before = $this->shots($opened['session']);

        // 第二个窗口**没有会话**，只是挂了表面、跑两帧（真实的多窗口就是这样：
        // 用户只对其中一个窗口开调试面，另一个窗口照常在画）。
        $constraints = BoxConstraints::tight(new Size(self::W, self::H));
        [$other] = Target::app('list', true, 0);
        $surface = new SurfaceProbe();
        $surface->resize((int) self::W, (int) self::H, 1.0);
        $other->attach($surface, $constraints);
        $other->frame(16.0, $constraints);
        $other->frame(32.0, $constraints);

        $after = $this->shots($opened['session']);

        check(
            '第二窗口的帧不推进第一会话的帧号',
            $after['frames'] === $before['frames'],
            '前 ' . \implode(',', $before['frames']) . ' 后 ' . \implode(',', $after['frames']),
        );
        check(
            '第二窗口的节点不进第一会话的节点表',
            $after['nodes'] === $before['nodes'],
            '前 ' . \count($before['nodes']) . ' 后 ' . \count($after['nodes']),
        );
        check(
            '第一会话的两份指纹都没变',
            $after['digest'] === $before['digest'] && $after['causal'] === $before['causal'],
        );
    }

    public function testUnownedRecorderStillTakesEveryFrame(): void
    {
        // 老路径（测试 / bench 手工装录制器，没有会话、没有绑定）：帧照样写进去。
        // 这一条守的是"这次修复没有把兼容面一起收掉"。
        $constraints = BoxConstraints::tight(new Size(self::W, self::H));
        [$app] = Target::app('table', true, 0);
        $surface = new SurfaceProbe();
        $surface->resize((int) self::W, (int) self::H, 1.0);
        $app->attach($surface, $constraints);
        $recorder = Recorder::install();
        $history = new History($recorder);

        $app->frame(0.0, $constraints);
        $app->frame(16.0, $constraints);

        check('无主录制器照样收到两帧', \count($recorder->frames()) === 2, '实际 ' . \count($recorder->frames()));
        check('无主录制器没有主人', $recorder->ownerId() === null);
    }

    public function testClosingOneWindowLeavesTheOtherRecording(): void
    {
        $a = $this->window('table');
        $b = $this->window('list');
        $this->push($a['session'], 1);
        $this->push($b['session'], 1);

        $a['session']->close();

        check('关掉 A 之后 B 仍握着录制器', $b['session']->recorder() === Recorder::current());
        $this->push($b['session'], 1);
        check(
            'B 关掉 A 之后还能继续录',
            \count($b['session']->recorder()->frames()) === 2,
            '实际 ' . \count($b['session']->recorder()->frames()),
        );

        $b['session']->close();
        check('全关之后没有当前录制器', Recorder::current() === null);
        check('全关之后 installed() 为假', !Recorder::installed());
    }

    /**
     * `reset()` 会换会话代号（旧号在新会话里指别的节点），而"这份录制器还活着"
     * 跟换没换号无关。登记表若按代号记，`reset()` 之后解绑就删不掉自己那一行 ——
     * 表现是"关掉会话后 `installed()` 仍是 true"，而 `$current` 指着一份已经关掉的记录。
     */
    public function testResetDoesNotStrandTheRecorderInTheLiveRegistry(): void
    {
        $opened = $this->window('table');
        $this->push($opened['session'], 1);
        $opened['session']->recorder()->reset();
        $opened['session']->close();

        check('reset 过之后关会话，当前录制器仍会被清掉', Recorder::current() === null);
        check('reset 过之后关会话，installed() 为假', !Recorder::installed());
    }

    public function testSecondSessionOnTheSameWindowIsStillRejected(): void
    {
        $opened = $this->window('table');

        $caught = null;
        try {
            DebugSession::open(
                // 同一个应用再开一次：两路写进同一份记录，必须拒。
                $this->sameApplication($opened['session']),
                BoxConstraints::tight(new Size(self::W, self::H)),
            );
        } catch (\LogicException $e) {
            $caught = $e->getMessage();
        }

        check('同一个窗口重复开会话被拒', $caught !== null, '没抛异常');
        check(
            '拒绝理由说的是"这个窗口"而不是"这个进程"',
            $caught !== null && \str_contains($caught, '这个窗口'),
            (string) $caught,
        );
    }

    /** 从会话里把它挂着的那个应用拿回来（拒绝判据要的正是同一个实例）。 */
    private function sameApplication(DebugSession $session): \Drift\App\Application
    {
        $property = new \ReflectionProperty(DebugSession::class, 'app');

        /** @var \Drift\App\Application $app */
        $app = $property->getValue($session);

        return $app;
    }
}
