<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Application;
use Drift\App\FrameClock;
use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Smoke\App\SpinnerApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;

/**
 * 等待层**策略**：`FrameClock::nextWaitMs()` 的三态（无限 / 立刻 / 到点）与卡顿观测。
 *
 * ## 为什么这一组能独立存在（N2 的全部意义）
 *
 * 改动之前，"什么时候醒"不是一个可以**问**的问题 —— 时钟自己开关一个 15ms
 * `SetTimer`：跑完一帧就上膛、`wantsFrame()` 假就落闸。于是唯一的唤醒粒度是
 * "一帧"，`after(400)` 也要被**敲 26 次**才轮到自己（每次还跑一遍完整 `Frame::run`）。
 *
 * 拆开之后，"等多久"变成一个**纯计算**：输入是应用的状态（有没有动画 / 定时器 /
 * 异步在途 / 欠账），输出是一个数字。于是它可以在没有窗口、没有平台、没有真机的
 * 情况下被钉住 —— 这一组测试就是那件事的证据。
 *
 * ## 这一组钉的四条
 *
 * ① **三态各自的触发条件**，尤其是 `> FRAME_MS`（一次睡到点）—— 它是收益本身。
 * ② **"连续帧"永远压过"到点"**：动画在跑时必须保持帧节奏，不能被一个远处的
 *   定时器拖成一次长睡（否则动画在定时器到点前**完全不动**）。
 * ③ **`0` 与 `> 0` 的分界是"只欠一次"**：过期定时器给 `0`（立刻），有动画给 `15`。
 * ④ **卡顿只在"按帧节奏跑"的那一轮才量**：等到 400ms 定时器之后跑帧，间隔本来
 *    就该是 400ms —— 那不是卡。判据是 `advance()` 里对**上一轮等待值**的检查。
 */
final class FrameWaitTest extends SmokeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Errors::reset();
    }

    public function testIdleAppSleepsForever(): void
    {
        $app = self::mount(new EchoApplication());
        $clock = new FrameClock();

        check(
            '等待层 · 没事可做 ⇒ `-1`（无限等，CPU 归零）',
            $clock->nextWaitMs($app, 0.0) === -1.0,
            \sprintf('wait=%.1f', $clock->nextWaitMs($app, 0.0)),
        );

        // ★ 纯计算：问两次不该有副作用（不跑帧、不改时间戳）。
        $frames = $app->frameCount();
        $clock->nextWaitMs($app, 500.0);
        check(
            '等待层 · 问"等多久"不跑帧（纯策略，不动系统）',
            $app->frameCount() === $frames,
            'frames=' . $app->frameCount(),
        );

        $this->flushChecks();
    }

    public function testAnimationKeepsFrameRhythm(): void
    {
        $app = self::mount(new SpinnerApplication());
        $clock = new FrameClock();

        check(
            '等待层 · 动画在跑 ⇒ 帧节奏 16.667ms（不是 `0`：本仓无 vsync，`0` 就是 300fps 空转）',
            $clock->nextWaitMs($app, 0.0) === (float) FrameClock::FRAME_MS,
            \sprintf('wait=%.1f', $clock->nextWaitMs($app, 0.0)),
        );

        // ★★ 这一条挡的是"把定时器的 400ms 当成全局等待"：动画期间那样做，
        //    转圈图会 400ms 才动一格（比 15ms 卡得多，而且肉眼看就是"卡住"）。
        $app->after(400, static function (): void {
        });
        check(
            '等待层 · 动画 + 远处定时器 ⇒ 仍取帧节奏（近者优先，动画不许被拖住）',
            $clock->nextWaitMs($app, 0.0) === (float) FrameClock::FRAME_MS,
            \sprintf('wait=%.1f', $clock->nextWaitMs($app, 0.0)),
        );

        $this->flushChecks();
    }

    public function testTimerSleepsUntilDue(): void
    {
        $app = self::mount(new EchoApplication());
        $clock = new FrameClock();

        // ★★ 本轮收益的**唯一数字证据**：一个 400ms 的延时回调 ⇒ 一觉睡 400ms。
        //    改动之前是 15ms 心跳 × 26 次，每次都跑一遍完整 `Frame::run`。
        $app->after(400, static function (): void {
        });
        check(
            '等待层 · `after(400)` ⇒ 等 400ms（不是帧节奏；旧心跳要敲 26 次）',
            $clock->nextWaitMs($app, 0.0) === 400.0,
            \sprintf('wait=%.1f', $clock->nextWaitMs($app, 0.0)),
        );

        // ★ 短定时器**不与帧间隔对齐**：7ms 就是 7ms。挡的是"把等待取整到整毫秒 /
        //   一帧"那类退化 —— 等待层是高精度定时器（真机 1ms 请求实测 ≈1.9ms），
        //   没有任何量化理由；取整会让 `after(1)` 变成 16ms 而没人报错。
        $short = self::mount(new EchoApplication());
        $short->after(7, static function (): void {
        });
        $shortClock = new FrameClock();
        check(
            '等待层 · `after(7)` ⇒ 等 7ms（短定时器不与帧间隔对齐）',
            $shortClock->nextWaitMs($short, 0.0) === 7.0,
            \sprintf('wait=%.3f', $shortClock->nextWaitMs($short, 0.0)),
        );

        check(
            '等待层 · 时间走过一半 ⇒ 剩下的一半（算的是**到点的绝对时刻**）',
            $clock->nextWaitMs($app, 250.0) === 150.0,
            \sprintf('wait=%.1f', $clock->nextWaitMs($app, 250.0)),
        );

        // 多个定时器：取最早的那个，不看注册顺序。
        $app->after(120, static function (): void {
        });
        check(
            '等待层 · 多个定时器 ⇒ 取**最早**到点的（`after(400)` 之后又排了 120）',
            $clock->nextWaitMs($app, 0.0) === 120.0,
            \sprintf('wait=%.1f', $clock->nextWaitMs($app, 0.0)),
        );

        // ★ 已经过期 ⇒ `0`（下一轮立刻跑），**不是负数**：负数在 `waitEvents()`
        //   那里意味着"无限等"，一个过期定时器会因此**永远不响**。
        check(
            '等待层 · 定时器已过期 ⇒ `0` 立刻跑（负数会被当成"无限等"，定时器永远不响）',
            $clock->nextWaitMs($app, 999.0) === 0.0,
            \sprintf('wait=%.1f', $clock->nextWaitMs($app, 999.0)),
        );

        $this->flushChecks();
    }

    public function testStallOnlyMeasuredOnRhythmRounds(): void
    {
        $app = self::mount(new SpinnerApplication());
        $clock = new FrameClock();
        $c = BoxConstraints::tight(new Size(320.0, 240.0));

        // 第一轮：按帧节奏等 ⇒ 200ms 的间隔**是**卡顿（上一帧里有阻塞调用）。
        check(
            '卡顿 · 前置：动画中 ⇒ 帧节奏',
            $clock->nextWaitMs($app, 0.0) === (float) FrameClock::FRAME_MS,
        );
        $clock->advance($app, 1000.0, $c);

        $clock->nextWaitMs($app, 1000.0);
        $clock->advance($app, 1200.0, $c);
        check(
            '卡顿 · 帧节奏下 200ms 的间隔记账（stalls=1）',
            $clock->stallCount() === 1 && $clock->worstGapMs() >= 199.0,
            \sprintf('stalls=%d worst=%.0f', $clock->stallCount(), $clock->worstGapMs()),
        );

        // ★★ 第二轮：**等了一个 400ms 的定时器**再跑帧 ⇒ 间隔 400ms，但那不是卡。
        //    判据是"上一轮 `nextWaitMs()` 给的值落在 `[0, FRAME_MS]`"。
        //    ★ 这条是防"卡顿观测把正常的长等待全记成卡顿"—— 那样日志会被
        //      Tooltip / Snackbar 刷满，真正的问题反而被埋掉。
        $idle = self::mount(new EchoApplication());
        $idle->after(400, static function (): void {
        });
        $idleClock = new FrameClock();
        $idleClock->nextWaitMs($idle, 0.0);          // 400 ⇒ 不是帧节奏
        $idleClock->advance($idle, 400.0, $c);
        $idleClock->nextWaitMs($idle, 400.0);
        $idleClock->advance($idle, 400.0, $c);
        check(
            '卡顿 · 等到 400ms 定时器之后跑帧**不算**卡顿（stalls=0）',
            $idleClock->stallCount() === 0,
            \sprintf('stalls=%d worst=%.0f', $idleClock->stallCount(), $idleClock->worstGapMs()),
        );

        $this->flushChecks();
    }

    /**
     * **原生模态挂起的时长不计入卡顿**（2026-10-30 真机取证）。
     *
     * ★ 事故形状：用户在原生对话框里停留 8.3s ⇒ 下一帧的"两帧间隔"= 8344ms
     *   ⇒ RECOVERABLE 弹框。模态不是缺陷（"模态期间界面不重绘"是 `Dialog`
     *   的固有语义），这段时间是平台记的账（`Platform::consumeModalBlockedMs()`），
     *   `advance()` 必须把它从判据里剔除。
     * ★ 两条都要有：剔除后不报（反例方向：漏传参数 ⇒ 红）；**没剔干净照样报**
     *   （正向证据：剔除只对模态生效，慢帧 / 慢处理器不受保护）。
     */
    public function testModalSuspensionIsSubtractedFromTheGap(): void
    {
        $app = self::mount(new SpinnerApplication());
        $clock = new FrameClock();
        $c = BoxConstraints::tight(new Size(320.0, 240.0));

        $clock->nextWaitMs($app, 0.0);          // 动画中 ⇒ 帧节奏
        $clock->advance($app, 1000.0, $c);
        $clock->nextWaitMs($app, 1000.0);

        // 用户在对话框里停留了 8344ms，模态账本也记了 8344 ⇒ 扣完剩 0 ⇒ 不是卡
        $clock->advance($app, 9344.0, $c, 8344.0);
        check(
            '卡顿 · 模态挂起的时长被剔除（用户停留 8.3s 不再弹 RECOVERABLE）',
            $clock->stallCount() === 0,
            \sprintf('stalls=%d worst=%.0f', $clock->stallCount(), $clock->worstGapMs()),
        );

        // ★ 正向证据：**账本没记全**（只剔了一半）时照样要报 ——
        //   剔除是"扣账"，不是"豁免"。漏记的 4s 里藏着的真卡顿不该被放过。
        //   ★ 剩余间隔故意压在 FREEZE_MS(500) 以下：再往上就是 RECOVERABLE，
        //     测试环境（debug）会当场中止进程 —— 这里要证的是"记账判据"，
        //     不是"中止序列"（那归 fatal 用例）。
        $clock2 = new FrameClock();
        $clock2->nextWaitMs($app, 0.0);
        $clock2->advance($app, 1000.0, $c);
        $clock2->nextWaitMs($app, 1000.0);
        $clock2->advance($app, 9400.0, $c, 8000.0);
        check(
            '卡顿 · 账本没记全 ⇒ 剩下的间隔**照报**（剔除不是豁免）',
            $clock2->stallCount() === 1 && $clock2->worstGapMs() >= 399.0,
            \sprintf('stalls=%d worst=%.0f', $clock2->stallCount(), $clock2->worstGapMs()),
        );

        $this->flushChecks();
    }

    private static function mount(Application $app): Application
    {
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));

        return $app;
    }
}
