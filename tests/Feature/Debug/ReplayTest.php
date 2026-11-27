<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Application;
use Drift\DevTools\App\DebugSession;
use Drift\DevTools\App\Replay;
use Drift\DevTools\Session\Digest;
use Drift\Debug\Session\Script;
use Drift\Debug\Session\Step;
use Drift\Dev\Smoke\App\PageHostApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;

/**
 * D2 判据：**同一份脚本跑两遍，因果记录必须逐值相同**。
 *
 * ## 为什么用真页面（表格页）而不是夹具
 *
 * 重放要验证的是"引擎确定"，而不是"我刚写的那个 fixture 确定"。夹具里没有
 * 焦点、虚拟化、单元格选中、异步——那些正是会把不确定偷偷带进来的地方。
 * 用示例台的真页面，判据才覆盖真实路径（代价是挂载 ~100ms，付得起）。
 *
 * ## 为什么每条判据都要有"区分度"对照
 *
 * "两次跑出来一样"在**什么都没发生时也成立**（0 帧 0 重建也能相等）。所以：
 * 先断言这一次确实重建了节点，再断言去掉一条输入后摘要**必须变**——
 * 后者证明摘要不是恒等函数，前者证明它有东西可守。
 */
final class ReplayTest extends SmokeTestCase
{
    private function constraints(): BoxConstraints
    {
        return BoxConstraints::tight(new Size(800.0, 600.0));
    }

    /** @return array{0: Application, 1: BoxConstraints} */
    private function boot(): array
    {
        $app = PageHostApplication::forRoute('table');
        $c = $this->constraints();
        $app->attach(new SurfaceProbe(), $c);

        return [$app, $c];
    }

    /** 现场驱动一次，返回录下来的脚本（点一下表格中间，两帧之后结束）。 */
    private function record(): Script
    {
        [$app, $c] = $this->boot();
        $session = DebugSession::open($app, $c, script: new Script('table', 800.0, 600.0));

        try {
            $session->pump(0.0);
            // 按下 + 抬起一次成型：焦点与选中在前者，激活在后者（与 --click 同形状）。
            $session->send(PointerEvent::down(400.0, 300.0), 16.0);
            $session->send(PointerEvent::up(400.0, 300.0), 16.0);
            $session->pump(16.0);

            return $session->script();
        } finally {
            $session->close();
        }
    }

    /**
     * @return array{digest: array<string, mixed>, frames: int, script: Script, canonical: array<int, array<string, mixed>>}
     */
    private function replay(Script $script, ?int $at = null): array
    {
        [$app, $c] = $this->boot();
        // 与 CLI 同形：会话拿到脚本的**分叉**（同目标同视口、步骤为空）。
        // 不这么做时录制会落进默认骨架（target = 应用类名），重录出的脚本
        // 与原脚本"内容一样、目标名不同"—— 那种差异来自测试写法，不是引擎。
        $session = DebugSession::open($app, $c, script: $script->fork());

        try {
            $frames = Replay::apply($session, $script, [], $at);

            $canonical = [];
            foreach ($session->recorder()->frames() as $id => $frame) {
                $canonical[(int) $id] = Digest::ofFrame($frame);
            }

            return [
                'digest' => Digest::of($session->recorder()),
                'frames' => $frames,
                'script' => $session->script(),
                'canonical' => $canonical,
            ];
        } finally {
            $session->close();
        }
    }

    public function testSameScriptReplaysToIdenticalCausality(): void
    {
        $script = $this->record();
        check('脚本记到了输入', $script->count(Step::POINTER) === 2, (string) $script->count(Step::POINTER));
        check('脚本记到了帧', $script->count(Step::FRAME) === 2, (string) $script->count(Step::FRAME));

        $a = $this->replay($script);
        $b = $this->replay($script);

        check('两次都跑了两帧', $a['frames'] === 2 && $b['frames'] === 2, $a['frames'] . ' / ' . $b['frames']);
        check('节点表逐值相同', $a['digest']['nodes'] === $b['digest']['nodes'], 'id 分配顺序不稳定');
        check(
            '逐帧因果逐值相同',
            $a['digest']['frames'] === $b['digest']['frames'],
            '第一个不同的帧：' . self::firstDifferentFrame($a['digest'], $b['digest']),
        );
        check('总摘要相同', $a['digest']['digest'] === $b['digest']['digest']);

        // ★ 区分度：点击帧确实重建了一批节点。没有这条，"两次都是空"也能全绿。
        $rebuilt = $a['canonical'][2]['rebuilt'] ?? [];
        check(
            '重放的点进帧确实重建了节点（不是空跑的假绿）',
            \count($rebuilt) > 50,
            '重建数=' . \count($rebuilt),
        );
        check(
            '两次重放的重建节点集合逐值相同',
            ($a['canonical'][2]['rebuilt'] ?? []) === ($b['canonical'][2]['rebuilt'] ?? []),
            '重建集合不同 ⇒ id 分配顺序或调和行为不确定',
        );

        // 驱动侧也要逐值相同：脚本→重放→脚本，内容不该漂。
        check(
            '重放重录出的脚本与原脚本逐值相同',
            $a['script']->digest() === $script->digest(),
            '重放过程中脚本被改写了（步数 ' . $a['script']->count() . ' vs ' . $script->count() . '）',
        );
    }

    public function testDroppingAnInputChangesTheDigest(): void
    {
        $script = $this->record();

        // 去掉两条指针事件：同一个目标、同一串帧，只是"没人点"。
        $withoutClick = $script->fork();
        foreach ($script->steps() as $step) {
            if ($step->kind !== Step::POINTER) {
                $withoutClick->add($step);
            }
        }
        check('对照脚本少了输入', $withoutClick->count(Step::POINTER) === 0);
        check('对照脚本帧数不变', $withoutClick->count(Step::FRAME) === $script->count(Step::FRAME));

        $a = $this->replay($script);
        $b = $this->replay($withoutClick);

        check(
            '去掉输入后摘要必须变',
            $a['digest']['digest'] !== $b['digest']['digest'],
            '摘要对输入不敏感 ⇒ 它守不住"同一脚本"这件事',
        );
    }

    public function testUntilFrameStopsBeforeLaterInputs(): void
    {
        $script = $this->record();

        $one = $this->replay($script, 1);

        check('只推进到第 1 帧', $one['frames'] === 1, (string) $one['frames']);
        check('记录里也只有 1 帧', \count($one['digest']['frames']) === 1, (string) \count($one['digest']['frames']));

        // 第 1 帧是挂载后的静止帧：输入还没派发，所以不该有重建。
        $full = $this->replay($script);
        check(
            '第 1 帧与跑完整份脚本的第 1 帧一致',
            $one['digest']['frames'][1] === $full['digest']['frames'][1],
            '把 --at 之后的输入提前派发了',
        );
    }

    public function testOpaqueInputIsRefusedByName(): void
    {
        $script = new Script('table', 800.0, 600.0);
        $script->add(Step::frame(0.0));
        $script->add(Step::opaque('Drift\\Input\\ThemeChangedEvent', 16.0));
        $script->add(Step::frame(16.0));

        $message = '';
        try {
            $this->replay($script);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        check('重放拒绝不可重放的输入', \str_contains($message, 'ThemeChangedEvent'), $message);
        check('错误带步号（脚本是给人改的）', \str_contains($message, '第 2 步'), $message);
    }

    public function testTruncatedScriptIsRefused(): void
    {
        $script = new Script('table', 800.0, 600.0);
        for ($i = 0; $i < Script::MAX_STEPS + 1; ++$i) {
            $script->add(Step::frame((float) $i));
        }

        $message = '';
        try {
            $this->replay($script);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        check('截断脚本被拒绝重放', \str_contains($message, '截断'), $message);
    }

    public function testUnregisteredEffectFailsLoudly(): void
    {
        $script = new Script('table', 800.0, 600.0);
        $script->add(Step::frame(0.0));
        $script->add(Step::effect('nope', [], 0.0));
        $script->add(Step::frame(16.0));

        $message = '';
        try {
            $this->replay($script);
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
        }

        check('未注册的效果明确报错', \str_contains($message, '未注册的驱动效果'), $message);
        check('错误带步号', \str_contains($message, '第 2 步'), $message);
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private static function firstDifferentFrame(array $a, array $b): string
    {
        foreach ($a['frames'] as $id => $hash) {
            if (($b['frames'][$id] ?? null) !== $hash) {
                return '#' . $id;
            }
        }

        return '（没有不同的帧，可能是帧数不同）';
    }
}
