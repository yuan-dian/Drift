<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Application;
use Drift\App\FrameClock;
use Drift\Core\Pipeline;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * **帧调度：从"每轮问状态"改成"变更点举手"**（技术债 D1 的判据）。
 *
 * ## 这条测试要守的三件事
 *
 * 1. **帧外 `setState()` 必须叫醒帧时钟**。这条在 D1 之前**不成立** ——
 *    `needsFrameNow()` 的三条判据（焦点环 / 像素脏 / 面板落后）一条都不看
 *    `markedCount`，所以"帧外改状态"只在"恰好还有别的东西欠帧"时生效，是
 *    **巧合正确**。推模型把它变成机制：`Pipeline::markDirty()` 帧外自己举手。
 * 2. **一笔请求恰好被一帧消费**：帧开始快照 ⇒ 不会累积到"每帧都跑"，
 *    也不会被帧内的新请求吞掉。
 * 3. **两档各司其职**：一次性欠账给 `0`（马上去跑），动画 / 轮询给帧间隔。
 *    这个分界一旦破了，要么动画迟滞一帧、要么空闲时 300fps 空转。
 *
 * ## 为什么判据形态是"交错"而不是"各测各的"
 *
 * 单看"`setState()` 之后要帧"在两种实现下都可能绿（因为真实路径上总有人顺带
 * 跑帧）。必须显式摆出"稳态落闸 → 只做这一次写 → 立刻问"，才分得开。
 *
 * ★ 与 `PaintArmsFrameTest` 的分工：那边钉的是**像素通道**
 *   （`setHovered()` / `setBarOpacity()` → `bumpPaintEpoch()`），这边钉的是
 *   **状态通道**（`setState()` → `markDirty()`）。两条路各有一个写点、
 *   各有一处可能漏调 `scheduleFrame()`。
 */
final class FrameScheduleTest extends SmokeTestCase
{
    private static function constraints(): BoxConstraints
    {
        return BoxConstraints::tight(new Size(200.0, 80.0));
    }

    private function counterApp(): Application
    {
        $app = new class extends Application {
            public function buildRoot(): Widget
            {
                return CounterWidget::make();
            }
        };

        $app->attach(new SurfaceProbe(), self::constraints());
        $app->frame(0.0, self::constraints());

        return $app;
    }

    /** 帧外 `setState()` ⇒ 帧时钟必须自己上膛（D1 之前这条是"巧合正确"） */
    public function testOffFrameSetStateArmsTheClock(): void
    {
        $app = $this->counterApp();
        $state = CounterState::$last;

        check(
            'D1 · 计数夹具挂上了（前置：没有它后面全是假绿）',
            $state instanceof CounterState,
        );
        check(
            'D1 · 稳态 wantsFrame() = false（前置：此刻帧时钟是落闸的）',
            $app->wantsFrame() === false,
            'wantsFrame=true ⇒ 前置不成立，后面的断言没有意义',
        );

        $state?->bump();

        check(
            'D1 · 帧外 setState() ⇒ wantsFrame() = true（旧模型这里恒为 false）',
            $app->wantsFrame() === true,
            'wantsFrame=false ⇒ 改了状态、画面不动、不报错',
        );

        check(
            'D1 · 它欠的是"立刻"档（等待层给 0，不是 15ms）',
            $app->needsFrameImmediately() === true,
            'immediately=false ⇒ setState 之后还要等一个帧间隔才画',
        );

        $this->flushChecks();
    }

    /** 一笔请求恰好被一帧消费：跑完那一帧就落闸，不会自我续命 */
    public function testOneRequestIsServedByExactlyOneFrame(): void
    {
        $app = $this->counterApp();
        CounterState::$last?->bump();

        $app->frame(16.0, self::constraints());

        check(
            'D1 · 消费掉那一帧之后 wantsFrame() 回落 false（不许自我续命）',
            $app->wantsFrame() === false,
            'wantsFrame=true ⇒ 每帧都在要帧 ⇒ 空闲 60fps 空转',
        );
        check(
            'D1 · setState() 真的生效了（值变了 ⇒ 那一帧确实重建过）',
            CounterState::$last?->n === 1,
            'n=' . (string) CounterState::$last?->n,
        );

        $this->flushChecks();
    }

    /**
     * 帧**内**新增的请求留给下一帧（不被本帧吞掉）。
     *
     * ★ 这条是 `State::requestFrame()` 的存在理由：动画在 settle 期间起飞时，
     *   这一帧已经推进过动画了 ⇒ 再 tick 一次就是"一帧走两步"（动画快进）；
     *   但**不能因此丢掉"下一帧还要"这个意图**。
     * ★ 直接钉 `Pipeline` 而不是 `Application`：这是"快照时机"的纯粹断言，
     *   端到端版本（"首帧之后连续几帧值真的动了"）在 `ImplicitAnimationClockTest`。
     */
    public function testRequestInsideFrameSurvivesToNextFrame(): void
    {
        $pipeline = new Pipeline();

        $pipeline->scheduleFrame();
        $pipeline->beginFrame();            // 帧开始：清掉上面那一笔
        check(
            'D1 · 帧开始清账后 wantsFrame() = false',
            $pipeline->wantsFrame() === false,
        );

        $pipeline->scheduleNextFrame();     // 帧内新增（settle 期间起飞的动画）

        check(
            'D1 · 帧内新增的请求留给下一帧（不被本帧的清账吞掉）',
            $pipeline->wantsFrame() === true,
            'wantsFrame=false ⇒ settle 期间起飞的动画会停在起点',
        );
        check(
            'D1 · 帧内新增的是"节奏"档而不是"立刻"档',
            $pipeline->wantsFrameImmediately() === false,
            'immediately=true ⇒ 动画期间会退化成 300fps 空转',
        );

        $pipeline->endFrame();
        $this->flushChecks();
    }

    /**
     * **键盘模态已经开着**时换焦点 ⇒ 仍要一帧（焦点变化自己举手）。
     *
     * ★ 为什么这条必须单独存在：焦点环被**两处**覆盖 —— `dispatchInput()` 里
     *   `keyboardFocusVisible` 翻转时举手，`FocusManager::focus()` 里焦点变化时
     *   举手。第一次 Tab 两处同时真 ⇒ 拆掉任意一处测试都还是绿（变异实测如此）。
     *   只有"第二次 Tab"（模态早已是键盘，不再翻转）才能单独验后者。
     * ★ 真机症状（拆掉它才会出现）：在键盘操作下反复 Tab，焦点环**停在第一个
     *   控件上不动**，直到用户动一下鼠标。
     */
    public function testTabBetweenTwoControlsArmsTheClock(): void
    {
        $app = new class extends Application {
            public function buildRoot(): Widget
            {
                return Column::make()->children([
                    Button::make('第一个'),
                    Button::make('第二个'),
                ]);
            }
        };
        $app->attach(new SurfaceProbe(), self::constraints());
        $app->frame(0.0, self::constraints());

        // 第一次 Tab：模态翻转 + 焦点落到第一个控件（两处同时举手）
        $app->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $app->frame(16.0, self::constraints());

        check(
            'D1 · 第一次 Tab 之后落闸（前置：否则后面测不出"第二次"）',
            $app->wantsFrame() === false,
            'wantsFrame=true ⇒ 前置不成立',
        );

        // 第二次 Tab：模态已经是键盘 ⇒ 只有焦点变化能举手
        $app->dispatchInput(KeyEvent::down(KeyChord::tab()));

        check(
            'D1 · 键盘模态下换焦点 ⇒ wantsFrame() = true（环要跟到新控件）',
            $app->wantsFrame() === true,
            'wantsFrame=false ⇒ 焦点环停在旧控件上，直到晃一下鼠标',
        );

        $this->flushChecks();
    }

    /** 两档的等待时长不同：一次性欠账 `0`，节奏档 = 帧间隔 */
    public function testWaitDurationDistinguishesTheTwoTiers(): void
    {
        $app = $this->counterApp();
        $clock = new FrameClock();

        check(
            'D1 · 无事可做 ⇒ 无限等（-1）',
            $clock->nextWaitMs($app, 0.0) === -1.0,
            'wait=' . (string) $clock->nextWaitMs($app, 0.0),
        );

        // ① 一次性欠账（帧外标脏像素）⇒ 0
        CounterState::$last?->bump();
        $urgent = $clock->nextWaitMs($app, 0.0);
        check(
            'D1 · 一次性欠账 ⇒ 等 0（马上去跑）',
            $urgent === 0.0,
            'wait=' . (string) $urgent . '（>0 ⇒ 状态变化之后画面还停在上一帧）',
        );

        // ② 节奏档：先把上一笔清掉，再模拟"动画还在跑"的续跑请求
        $app->frame(16.0, self::constraints());
        $app->pipeline()->scheduleNextFrame();
        $rhythm = $clock->nextWaitMs($app, 16.0);
        check(
            'D1 · 节奏档 ⇒ 等一个帧间隔（不是 0，否则能跑多快跑多快）',
            $rhythm === (float) FrameClock::FRAME_MS,
            'wait=' . (string) $rhythm . '（期望 ' . FrameClock::FRAME_MS . '）',
        );

        $this->flushChecks();
    }
}

/** 一个最小的有态控件：只有它能证明"帧外 setState 会不会叫醒帧时钟" */
final class CounterWidget extends StatefulWidget
{
    public static function make(): self
    {
        return new self();
    }

    public function createState(): State
    {
        return new CounterState();
    }
}

/** @internal 见 `CounterWidget`；`$last` 是夹具取实例的唯一途径 */
final class CounterState extends State
{
    public static ?CounterState $last = null;

    public int $n = 0;

    public function initState(): void
    {
        self::$last = $this;

        parent::initState();
    }

    public function build(BuildContext $ctx): Widget
    {
        return Text::make('n=' . $this->n);
    }

    /** 夹具用：模拟"帧外发生了一次状态变化" */
    public function bump(): void
    {
        $this->setState(function (): void {
            ++$this->n;
        });
    }
}
