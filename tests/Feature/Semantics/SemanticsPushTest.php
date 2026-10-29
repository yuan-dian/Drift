<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Semantics;

use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Smoke\App\FocusTwoApplication;
use Drift\Dev\Smoke\App\GestureApplication;
use Drift\Dev\Smoke\App\LazyApplication;
use Drift\Dev\Smoke\App\SpinnerApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\ScrollEvent;
use Drift\Widget\Element;

/**
 * 语义推送两档（W7 L6-6，`07` §6.2）。
 *
 * 结构变了 ⇒ `full`；动画中（结构没变）⇒ `coalesce`（每 4 帧一次）；焦点变 ⇒ 立即 `full`。
 * ★ `delta` 档**诚实降级没做**（§6.4：没有字段表就只能是"每帧仍遍历全树、少一次序列化"的半成品）。
 *
 * ★ 反例方向：若 `updateChildren()` 无条件 `bumpStructure()`（每次 rebuild 都 +1），
 *   则"结构没变"永远判不出来 ⇒ 动画中会变成每帧推（8 次而不是 2 次）、版本号也会涨。
 *   所以"推了几次"必须和"版本号动没动"一起看 —— 单看推的次数分不出
 *   "coalesce 生效" 与 "结构判定失准"。
 *
 * 与 `SemanticsTest` 的分工：那边判**建树**（Element 层的语义数据），这边判**推送节流**
 * （Application 层每帧决定推哪一档）。共用判据只有 `SemanticsBuilder` 的产物，不共享夹具。
 */
final class SemanticsPushTest extends SmokeTestCase
{
    // ㉑ 结构版本号随挂载增长（`mount()` 里 +1）
    public function testStructureVersionGrowsOnMount(): void
    {
        $sv0 = Element::structureVersion();
        $app = new EchoApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(120.0, 120.0)));
        $sv1 = Element::structureVersion();
        check(
            '语义两档 · 结构版本号随挂载增长（mount() 里 +1）',
            $sv1 > $sv0,
            $sv0 . ' -> ' . $sv1,
        );
    }

    // ㉒㉓㉔ 首帧推 full；随后动画 8 帧只推 2 次（每 4 帧一次），且结构版本号不动
    public function testFullThenCoalesce(): void
    {
        $app = new SpinnerApplication();
        $pushes = 0;
        $app->setSemanticsSink(function (array $nodes) use (&$pushes): void {
            ++$pushes;
        });
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(120.0, 120.0)));
        check(
            '语义两档 · 首帧结构变了 ⇒ 推 full',
            $pushes === 1 && $app->semanticsMode() === 'full',
            'pushes=' . $pushes . ' mode=' . $app->semanticsMode(),
        );

        $pushes = 0;
        $svBefore = Element::structureVersion();
        $ts = 0.0;
        for ($i = 0; $i < 8; $i++) {
            $ts += 16.0;
            $app->frame($ts, BoxConstraints::tight(new Size(120.0, 120.0)));
        }
        $svAfter = Element::structureVersion();
        check(
            '语义两档 · coalesce：动画中 8 帧 ⇒ 推 2 次（每 4 帧一次）',
            $pushes === 2 && $app->semanticsMode() === 'coalesce',
            'pushes=' . $pushes . ' mode=' . $app->semanticsMode(),
        );
        check(
            '语义两档 · 动画每帧重建但结构没变 ⇒ 版本号不动（updateChildren 不无条件 +1）',
            $svAfter === $svBefore,
            $svBefore . ' -> ' . $svAfter,
        );
    }

    // ㉕ coalesce 的第 1 帧**不推**（攒着）—— 证明它真在攒，不是"没走这条路"
    public function testCoalesceHoldsFirstFrame(): void
    {
        $app = new SpinnerApplication();
        $pushes = 0;
        $app->setSemanticsSink(function (array $nodes) use (&$pushes): void {
            ++$pushes;
        });
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(120.0, 120.0)));
        $pushes = 0;
        $app->frame(16.0, BoxConstraints::tight(new Size(120.0, 120.0)));
        check(
            '语义两档 · coalesce 第 1 帧不推（攒着），档位记的是 coalesce',
            $pushes === 0 && $app->semanticsMode() === 'coalesce',
            'pushes=' . $pushes . ' mode=' . $app->semanticsMode(),
        );
    }

    // ㉖ 虚拟列表滚动换了行 ⇒ **结构变** ⇒ full（不是 coalesce）
    //    这条钉的是"滚动不是一种可以攒的变化"：行换了就必须整棵推，攒着会让读屏
    //    的树与真实树错位（增量表达不了"多了几个节点"）。
    public function testLazyScrollIsStructural(): void
    {
        $app = new LazyApplication();
        $pushes = 0;
        $app->setSemanticsSink(function (array $nodes) use (&$pushes): void {
            ++$pushes;
        });
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(400.0, 200.0)));
        $pushes = 0;
        $verBefore = Element::structureVersion();
        $app->dispatchInput(ScrollEvent::make(200.0, 100.0, 3.0)); // 3 格 = 144px ⇒ 窗口换行

        // ★ 滚轮是**带动画**的（`glideByAxis` 摊成若干帧）⇒ 结构变发生在**换行那一帧**，
        //   不是 dispatch 后的第一帧。所以要逐帧推进到"结构真的变了"再断言。
        // ★★ 不能一口气 `pumpScroll(60)`：动画期间 `animating=true` ⇒ 语义走 `coalesce`，
        //   节流会额外推几次，`pushes === 1` 就变成假红 —— 那条断言的本意是
        //   "**换行这一帧**只推一次且是 full"，所以判据必须锚在"结构变的那一帧"。
        $c = BoxConstraints::tight(new Size(400.0, 200.0));
        $ts = 16.0;
        $deltaAtChange = null;
        $modeAtChange = '';
        for ($i = 0; $i < 60 && $deltaAtChange === null; ++$i) {
            $prev = $pushes;
            $ts += 16.0;
            $app->frame($ts, $c);
            if (Element::structureVersion() > $verBefore) {
                $deltaAtChange = $pushes - $prev;
                $modeAtChange = $app->semanticsMode();
            }
        }

        check(
            '语义两档 · 虚拟列表滚动换了行 ⇒ 结构变 ⇒ 立即 full（不攒）',
            $deltaAtChange === 1 && $modeAtChange === 'full',
            'ver=' . $verBefore . '->' . Element::structureVersion()
                . ' delta=' . ($deltaAtChange ?? -1) . ' mode=' . $modeAtChange,
        );
    }

    // ㉗ **纯焦点变 ⇒ 立即 full**（2026-10-24 补，`pumpSemantics` 的 `|| $focusChanged`）
    //
    // ★ 这条与"节流期间焦点仍能穿过去"的区别（也是它存在的理由）：那条里 `overBudget`
    //   是混进来的（skip=3 已经在）。
    // ★★ **必须带动画**，否则证伪不了（2026-10-24 实测）：不带动画时，删掉
    //   `|| $focusChanged` 后焦点变的帧会落到"无条件落地推"，仍然推 1 次、mode 仍是
    //   `full` ⇒ 断言分不出两条路径。带动画就不一样了：焦点变 ⇒ `full`；
    //   只走动画 ⇒ `coalesce`。**这就是能区分两种实现的唯一形状**
    //   —— 前提不成立的断言比没有断言更坏。
    public function testFocusChangeBypassesCoalesce(): void
    {
        $app = new FocusTwoApplication();
        $pushes = 0;
        $app->setSemanticsSink(function (array $nodes) use (&$pushes): void {
            ++$pushes;
        });
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 200.0)));
        $app->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $app->frame(16.0, BoxConstraints::tight(new Size(320.0, 200.0)));
        $pushes = 0;
        $labelBefore = $app->focusedLabel();
        $verBefore = Element::structureVersion();
        $app->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $labelAfter = $app->focusedLabel();
        $app->frame(16.0, BoxConstraints::tight(new Size(320.0, 200.0)));
        check(
            '语义两档 · 焦点移动（甲→乙）+ 动画中 ⇒ 仍立即 full（绕过 coalesce）',
            $pushes === 1
                && $app->semanticsMode() === 'full'
                && $labelBefore === '甲'
                && $labelAfter === '乙'
                && Element::structureVersion() === $verBefore,
            'pushes=' . $pushes . ' mode=' . $app->semanticsMode()
                . ' focus=' . $labelBefore . '->' . $labelAfter
                . ' ver=' . $verBefore . '->' . Element::structureVersion(),
        );
    }

    /**
     * **sink 收到的载荷形状**（不是"推了几次"，是"交出去的是什么"）。
     *
     * ★ 与 `SemanticsTest` 分工：那边判 `SemanticsBuilder` 产出的树（Element 层
     *   的数据结构），这边判**过完序列化之后平台侧看到的数组** —— 形状错了
     *   （比如 `role` 写成对象、`name` 空了）平台侧就是一块读屏黑洞，而头侧
     *   一切正常。
     * ★ 断言 `count === 2` 而不是 `>= 1`：`GestureApplication` 恰好两个按钮，
     *   `>= 1` 在"只推出第一个节点"的实现下也绿（原来的 smoke 版本就是 `>= 1`，
     *   与它自己下一条 `=== 2` 自相矛盾）。
     */
    public function testSinkPayloadShape(): void
    {
        $app = new GestureApplication();
        $nodes = [];
        $app->setSemanticsSink(function (array $pushed) use (&$nodes): void {
            $nodes = $pushed;
        });
        $app->attach(new SurfaceProbe(), BoxConstraints::loose(new Size(300.0, 200.0)));

        $roles = \array_map(static fn(array $n): string => (string) ($n['role'] ?? ''), $nodes);
        $names = \array_map(static fn(array $n): string => (string) ($n['name'] ?? ''), $nodes);
        check(
            '语义推送 · attach 首帧 ⇒ sink 收到完整语义树（两个 Button 各一个节点）',
            \count($nodes) === 2,
            'nodes=' . \count($nodes) . ' roles=' . \implode(',', $roles),
        );
        check(
            '语义推送 · 载荷里 role = button、name = 标签（平台侧直接可读）',
            $roles === ['button', 'button'] && $names === ['拖我', '点我'],
            'roles=' . \implode(',', $roles) . ' names=' . \implode(',', $names),
        );
    }
}
