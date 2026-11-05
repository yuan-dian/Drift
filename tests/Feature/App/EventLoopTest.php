<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Application;
use Drift\App\EventLoop;
use Drift\Dev\Smoke\App\CloseGuardApplication;
use Drift\Dev\Smoke\App\CursorApplication;
use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Smoke\App\ModalOnClickApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\CursorShape;
use Drift\Platform\Win32\Win32Platform;

/**
 * 主循环（`EventLoop::run()`）：**等 → 派发 → 跑帧 → 同步 IME**，一轮一次。
 *
 * ## 这一组钉什么（每条都对应一次真实事故）
 *
 * | 契约 | 漏掉的后果（真机现象，均不报错） |
 * |---|---|
 * | `Quit` **先于**派发 | 关窗那一轮还派发一次事件、跑一帧（对着死句柄画） |
 * | `pumpEvents()` 先于 `poll()` | 事件永不进队列 ⇒ 窗口**完全不动** |
 * | 每个事件后同步 IME | 输入法候选框晚一步，贴在旧位置 |
 * | 每轮跑一帧（含 Timeout） | 定时器到点了但没人消费 ⇒ Tooltip 不消失 |
 * | 帧末同步 IME | 候选框贴在窗口左上角（能打字、位置错） |
 * | 光标由循环喂 `WindowHost` | 指针形状永远不变（`dispatchInput` 的返回值被丢掉） |
 *
 * ## 为什么用**真** `Win32Platform` + 假桥
 *
 * `waitEvents()` 里那段"取一条 → 判断是不是我那个闹钟 → 暂存或退出"是本轮唯一
 * 新增的**分支逻辑**，它只存在于此。把它绕过去（自己写个假平台）等于只测了
 * `EventLoop` 的 for 循环。假桥（`tools/fakes/win32.php`）按剧本回事件，于是
 * 整段能无头跑 —— 这与 `tools/smoke.php` 一直以来的口径相同（桥可假、策略不可假）。
 *
 * ## 剧本的写法
 *
 * `winPumpScript([...])` 每调一次 `drift_win_pump()` 消费一条；剧本空了回 `quit`
 * ⇒ **测试不可能挂死**（哪怕断言写错、循环少了一个 break）。
 */
final class EventLoopTest extends SmokeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        winLoopReset();
    }

    public function testQuitStopsBeforeDispatchAndFrame(): void
    {
        [$platform, $app, $handle] = self::mount(new EchoApplication());
        $frames = $app->frameCount();

        winPumpScript([['type' => 'quit']]);
        EventLoop::run($platform, [$handle => $app]);

        check(
            '主循环 · `quit` 直接跳出：不再派发、不再跑帧',
            $app->frameCount() === $frames,
            'frames=' . $app->frameCount() . '（之前 ' . $frames . '）',
        );
        // `-1`（无限等）走 `drift_win_pump()`，不落 `drift_win_wait()` ⇒ "零次限时等待"
        // 就是"无限等"在外面看得见的形态。旧实现是每 15ms 一次 `SetTimer` 心跳。
        check(
            '主循环 · 空闲应用 ⇒ **零次限时等待**（无限等，空闲 CPU 归零）',
            winWaitCalls() === [],
            \sprintf('%d 次限时等待：%s', \count(winWaitCalls()), \json_encode(winWaitCalls())),
        );

        $this->flushChecks();
    }

    public function testMessageIsDispatchedThenOneFrameRuns(): void
    {
        [$platform, $app, $handle] = self::mount(new EchoApplication());
        $frames = $app->frameCount();

        winPumpScript([
            ['type' => 'key', 'key' => 'A'],
            ['type' => 'quit'],
        ]);
        EventLoop::run($platform, [$handle => $app]);

        check(
            '主循环 · 一条消息 ⇒ 恰好一帧（`quit` 那一轮不跑）',
            $app->frameCount() === $frames + 1,
            \sprintf('frames=%d → %d', $frames, $app->frameCount()),
        );
        check(
            '主循环 · 事件被抽干（`pumpEvents()` 在 `poll()` 之前，队列不残留）',
            $platform->input($handle)->poll() === null,
        );
        // ★ 两条 IME 上报 = 每条事件后一次 + 帧末一次。少了任一条都是真机上
        //   "候选框位置慢半拍"的来源，而它们在无头环境下**完全看不出来**。
        check(
            '主循环 · IME 报两次（事件后 + 帧末）',
            \count(winImeCalls()) === 2,
            \sprintf('%d 次：%s', \count(winImeCalls()), \json_encode(winImeCalls())),
        );

        $this->flushChecks();
    }

    public function testTimerWaitIsOneSleepNotManyWakes(): void
    {
        [$platform, $app, $handle] = self::mount(new EchoApplication());
        $frames = $app->frameCount();
        $fired = 0;

        $app->after(400, static function () use (&$fired): void {
            ++$fired;
        });

        winPumpScript([
            ['type' => 'timeout'],
            ['type' => 'quit'],
        ]);
        EventLoop::run($platform, [$handle => $app]);

        // ★ 两轮 = 两次等待（第一轮等到点、第二轮被 `quit` 打断）—— 关键是**时长**：
        //   每一次都是 400，而不是 15。旧心跳下同一个 400ms 定时器要 27 次 15ms。
        $waits = \array_map(static fn (int $ms): int => $ms, winWaitCalls());
        $allFar = $waits !== [];
        foreach ($waits as $ms) {
            $allFar = $allFar && $ms === 400;
        }
        check(
            '主循环 · `after(400)` ⇒ 每轮都是 400ms 的等待（旧心跳是 27 次 15ms）',
            $allFar && \count($waits) === 2,
            \json_encode($waits),
        );
        check(
            '主循环 · 等到点的那一轮照样跑帧（定时器要在帧里消费）',
            $app->frameCount() === $frames + 1,
            \sprintf('frames=%d → %d', $frames, $app->frameCount()),
        );
        check(
            '主循环 · 等到点（时间戳没变）⇒ 回调**还没**触发（帧里按到点时刻判）',
            $fired === 0,
            'fired=' . $fired,
        );

        $this->flushChecks();
    }

    public function testDueTimerFiresInsideFrameAndThenSleepsAgain(): void
    {
        [$platform, $app, $handle] = self::mount(new EchoApplication());
        $fired = 0;

        $app->after(0, static function () use (&$fired): void {
            ++$fired;
        });

        winPumpScript([
            ['type' => 'timeout'],
            ['type' => 'quit'],
        ]);
        EventLoop::run($platform, [$handle => $app]);

        check(
            '主循环 · 到点的定时器在帧里触发（一次性消费，不重复）',
            $fired === 1,
            'fired=' . $fired,
        );
        check(
            '主循环 · 定时器消费完 ⇒ 应用不再要帧（下一轮可以无限等）',
            $app->wantsFrame() === false,
        );

        $this->flushChecks();
    }

    public function testCursorFromDispatchReachesWindowHost(): void
    {
        [$platform, $app, $handle] = self::mount(new CursorApplication());

        // 视口 320×240（`mount()` 挂载时用的约束），dpr=1 ⇒ (160,120) 落在 `Center` 上，
        // 根声明了 `CursorShape::PointingHand` ⇒ 循环必须把它喂给 `WindowHost`。
        winPumpScript([
            ['type' => 'pointer', 'x' => 160, 'y' => 120, 'button' => 0, 'phase' => 1],
            ['type' => 'quit'],
        ]);
        EventLoop::run($platform, [$handle => $app]);

        $calls = winCursorCalls();
        check(
            '主循环 · `dispatchInput()` 给的光标形状被喂给 `WindowHost`（漏了指针形状永不变）',
            \count($calls) === 1
                && $calls[0][0] === $handle
                && $calls[0][1] === CursorShape::PointingHand->value,
            \json_encode($calls),
        );

        $this->flushChecks();
    }

    /**
     * 挂一个真 `Win32Platform`（假桥）+ 已 attach 的应用。
     *
     * @return array{0: Win32Platform, 1: Application, 2: int}
     */
    private static function mount(Application $app): array
    {
        $platform = new Win32Platform();
        $handle = $platform->window()->createWindow('主循环', 640, 480);

        // ★ 表面用探针而不是真表面：本组断言的是**循环**，不是光栅。
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));

        return [$platform, $app, $handle];
    }

    /**
     * **派发输入时弹的原生模态不算卡顿**（2026-10-30 真机，第二次取证）。
     *
     * ★ 事故形状：showcase「Dialog」页点「弹一个原生提示」⇒ `Dialog::alert()` 是
     *   **同步原生模态**，而它发生在**派发输入**里。模态账本原来只在派发**之前**
     *   读一次 ⇒ 这段时间没被剔除 ⇒ 关掉框之后弹一条
     *   「帧时钟：两帧间隔 N ms」的 RECOVERABLE。
     *   ⇒ 上一轮那次修复只覆盖了「帧**之间**的模态」，漏了「帧**内**的模态」。
     * ★ 判据必须让**平台时钟真的往前走**：`winDialogLatencyFake()` 既睡真实时间
     *   （记账）、也推 `DRIFT_SMOKE_NOW`（间隔）。只睡不推 ⇒ 间隔没变 ⇒ 假绿。
     */
    public function testModalOpenedWhileDispatchingIsNotAStall(): void
    {
        Errors::reset();
        winDialogLatencyFake(80.0);   // > STALL_MS(60)：不剔除就一定报

        $diag = [];

        try {
            $platform = new Win32Platform();
            $handle = $platform->window()->createWindow('模态', 640, 480);

            $dialog = $platform->dialog();
            check('夹具 · 平台给得出 `Dialog`', $dialog !== null, 'dialog=null');

            if ($dialog !== null) {
                $app = new ModalOnClickApplication($dialog);
                $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));

                winPumpScript([
                    ['type' => 'timeout'],   // 轮 1：先有一帧，帧时钟才有"上一帧"
                    ['type' => 'pointer', 'x' => 160, 'y' => 120, 'button' => 0, 'phase' => 1],
                    ['type' => 'timeout'],
                    ['type' => 'quit'],
                ]);
                EventLoop::run($platform, [$handle => $app]);

                $diag = Errors::recentDiagnostics();
            }
        } finally {
            winDialogLatencyFake(0.0);
            Errors::reset();
        }

        check(
            '主循环 · 派发里弹的原生框确实弹过（不是没走到这条分支）',
            winDialogCalls() === [['alert', '提示']],
            \json_encode(winDialogCalls()),
        );
        check(
            '主循环 · 派发中弹窗的停留时长被剔除（关掉系统提示窗不再报"两帧间隔"）',
            $diag === [],
            'diag=' . \count($diag) . ' ' . \implode(' | ', \array_slice($diag, 0, 2)),
        );

        $this->flushChecks();
    }

    /**
     * 关闭请求 · **应用同意** ⇒ 框架执行销毁，并立刻收工。
     *
     * ★ 为什么"立刻收工"值得单独断言：销毁之后如果还跑一帧，那一帧是对着死句柄
     *   求客户区 ⇒ 0×0 紧约束 ⇒ 布局硬校验 Fatal（真机上就是"点 X 之后闪一下错误框"）。
     *   判据落在"帧数没涨"上 —— 它是这个后果的**唯一**可见痕迹。
     */
    public function testCloseRequestedIsHonouredAndStopsTheLoop(): void
    {
        $app = new CloseGuardApplication(allow: true);
        [$platform, $app, $handle] = self::mount($app);
        $frames = $app->frameCount();

        winPumpScript([['type' => 'close-requested']]);
        EventLoop::run($platform, [$handle => $app]);

        $calls = winCloseCalls();
        check(
            '关闭 · 应用同意 ⇒ 框架执行 `destroy()`（而不是让应用自己 close）',
            self::closeCall($calls, 'destroy', $handle),
            \json_encode($calls),
        );
        check(
            '关闭 · 销毁之后不再跑帧（对着死句柄求客户区 ⇒ 0×0 ⇒ 布局 Fatal）',
            $app->frameCount() === $frames,
            \sprintf('frames=%d（之前 %d）', $app->frameCount(), $frames),
        );
        check(
            '关闭 · 只问一次（问两次会弹两次确认框）',
            $app->asked === 1,
            'asked=' . $app->asked,
        );

        $this->flushChecks();
    }

    /**
     * 关闭请求 · **应用拒绝** ⇒ 什么都不发生（窗口原样留着）。
     *
     * ★ 反证方向与上一个用例相反，两条合起来才锁住 `if ($app->closeRequested())`：
     *   恒真 ⇒ 本条红（执行了销毁）；恒假 ⇒ 上一条红。
     */
    public function testCloseRequestedRefusedLeavesWindowAlone(): void
    {
        $app = new CloseGuardApplication(allow: false);
        [$platform, $app, $handle] = self::mount($app);

        winPumpScript([
            ['type' => 'close-requested'],
            ['type' => 'quit'],   // 剧本空了本来也回 quit，这里写出来是为了让"继续跑"这件事可见
        ]);
        EventLoop::run($platform, [$handle => $app]);

        check(
            '关闭 · 应用拒绝 ⇒ **不执行**销毁（窗口留着）',
            !self::closeCall(winCloseCalls(), 'destroy', $handle),
            \json_encode(winCloseCalls()),
        );
        check(
            '关闭 · 应用拒绝 ⇒ 循环继续（不是"拒绝即退出"）',
            $app->asked === 1 && $app->wantsFrame() === false,
            'asked=' . $app->asked,
        );

        $this->flushChecks();
    }

    /**
     * 关闭请求**不进命中链**：它不该被 `dispatchInput()` 当成输入事件。
     *
     * ★ 判据不能只看"销毁了没有"（那由上面两条覆盖）—— 要看**事件真的没进路由**：
     *   `dispatchInput()` 会刷新 `lastInputMs`，而关闭请求不该算"用户动了一下"
     *   （`after()` 的基准取它）。这里用"帧内没收到任何输入"的副作用来证：
     *   拒绝之后 `wantsFrame()` 必须仍是 false（没有事件引起重建）。
     */
    public function testCloseRequestedDoesNotEnterDispatchChain(): void
    {
        $app = new CloseGuardApplication(allow: false);
        [$platform, $app, $handle] = self::mount($app);

        winPumpScript([
            ['type' => 'close-requested'],
            ['type' => 'quit'],
        ]);
        EventLoop::run($platform, [$handle => $app]);

        check(
            '关闭 · 事件被循环自己消费掉，不留在 `input()` 队列里',
            $platform->input($handle)->poll() === null,
            '队列里还有事件',
        );

        $this->flushChecks();
    }

    /**
     * **多窗口**（2026-10-30，M1-b）：事件按 `hwnd` 投进各自的队列，关一个窗不影响另一个。
     *
     * ## 这一条钉什么
     *
     * | 契约 | 漏掉的后果（不报错） |
     * |---|---|
     * | `pumpEvents()` 按事件自带的 hwnd 投队列 | 两个应用抢同一条事件（点一下，两个窗都响应） |
     * | 关窗只销毁**那个**窗口 | 关窗 2 把窗 1 关了（`destroy()` 拿错句柄） |
     * | 关掉一个窗**不退出循环** | 窗 2 关了 ⇒ 窗 1 跟着停摆（画面冻结，日志无异常） |
     *
     * ★ 判据落在 **IME 的句柄**上：每条事件后一次 + 每窗帧末一次。串窗的话
     *   第一个窗会多一次、第二个窗少一次 —— 帧数看不出来（两窗都会跑），
     *   句柄看得出来。
     */
    public function testTwoWindowsRouteEventsByHandleAndCloseIndependently(): void
    {
        $platform = new Win32Platform();
        $window = $platform->window();
        $one = $window->createWindow('窗 1', 640, 480);
        $two = $window->createWindow('窗 2', 320, 200);

        $busy = new EchoApplication();
        $closing = new CloseGuardApplication(allow: true);
        $busy->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
        $closing->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(160.0, 120.0)));

        winPumpScript([
            ['type' => 'key', 'key' => 'A', 'hwnd' => $one],
            ['type' => 'key', 'key' => 'B', 'hwnd' => $two],
            ['type' => 'close-requested', 'hwnd' => $two],
            ['type' => 'quit'],
        ]);

        $framesBefore = $busy->frameCount();
        $closingBefore = $closing->frameCount();   // `attach()` 已经跑过首帧
        EventLoop::run($platform, [$one => $busy, $two => $closing]);

        check(
            '双窗 · 两条队列都抽干了（事件没有留在队列里）',
            $platform->input($one)->poll() === null && $platform->input($two)->poll() === null,
        );
        check(
            '双窗 · 关窗请求落到**窗 2**（消毁的是它，窗 1 不动）',
            self::closeCall(winCloseCalls(), 'destroy', $two)
            && !self::closeCall(winCloseCalls(), 'destroy', $one),
            \json_encode(winCloseCalls()),
        );
        check(
            '双窗 · 关掉窗 2 之后循环**继续**跑窗 1（三帧：key A / key B / 关窗那轮）',
            $busy->frameCount() === $framesBefore + 3,
            \sprintf('frames=%d（之前 %d）', $busy->frameCount(), $framesBefore),
        );
        check(
            '双窗 · 窗 2 收到关闭请求后**不再跑帧**',
            $closing->frameCount() === $closingBefore + 2,
            \sprintf('frames=%d（之前 %d）', $closing->frameCount(), $closingBefore),
        );

        // ★ IME 句柄账：窗 1 = 事件 1 次 + 帧末 3 次；窗 2 = 事件 1 次 + 帧末 2 次
        //   （它在第 3 轮被关掉，那一轮不再跑帧）。串窗时两侧数字都会不对。
        $byHandle = [0, 0];
        foreach (winImeCalls() as $call) {
            if ($call[0] === $one) {
                ++$byHandle[0];
            } elseif ($call[0] === $two) {
                ++$byHandle[1];
            }
        }
        check(
            '双窗 · IME 上报各归各窗（窗 1 = 4 次，窗 2 = 3 次）',
            $byHandle === [4, 3],
            \json_encode($byHandle),
        );

        $this->flushChecks();
    }

    /**
     * **空闲的窗口不能把有活干的窗口拖去长睡**。
     *
     * ★ `nextWaitMs()` 的 `-1` 是"无限等"的哨兵，**不是最小的那个数**：
     *   `min(4000, -1)` = -1 ⇒ 有定时器的窗口被空闲窗口拖成永远睡（定时器再也不响）。
     *   单窗口永远碰不到这一支 —— 这是双窗口才暴露的类型错误（数值哨兵当极值用）。
     */
    public function testIdleWindowDoesNotDragTheBusyOneToSleep(): void
    {
        $platform = new Win32Platform();
        $window = $platform->window();
        $busyHandle = $window->createWindow('有活', 640, 480);
        $idleHandle = $window->createWindow('空闲', 320, 200);

        $busy = new EchoApplication();
        $idle = new CloseGuardApplication(allow: false);
        $busy->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
        $idle->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(160.0, 120.0)));
        $busy->after(500, static function (): void {});

        winPumpScript([
            ['type' => 'timeout'],
            ['type' => 'quit'],
        ]);
        EventLoop::run($platform, [$busyHandle => $busy, $idleHandle => $idle]);

        $waits = \array_map(static fn (int $ms): int => $ms, winWaitCalls());
        check(
            '双窗 · 等待时长取"最急"的那个窗口（500ms，不是无限等）',
            \in_array(500, $waits, true),
            \json_encode($waits),
        );
        check(
            '双窗 · 空闲窗口不产生额外等待轮次（两轮：等到点 + quit）',
            \count($waits) === 2,
            \sprintf('%d 次：%s', \count($waits), \json_encode($waits)),
        );

        $this->flushChecks();
    }

    /**
     * **壳直接销毁的窗口要被摘掉，且一帧都不跑**（2026-10-30，M1-b 真机探针发现）。
     *
     * ★ 这条分支真实存在：**没有声明关闭守卫的窗口**（hello / tutorial）是壳在
     *   `WM_CLOSE` 里直接 `DestroyWindow` 的 —— 框架收不到任何事件。不查存活就会
     *   对着死句柄求客户区 ⇒ 0×0 紧约束 ⇒ 布局硬校验 Fatal。
     * ★ 真机上**时好时坏**：`WM_QUIT` 前面排着几条 `WM_KILLFOCUS` / `WM_ACTIVATE`
     *   时才触发 —— 所以必须有无头用例钉住，靠真机探针复现是碰运气
     *   （本轮探针就是这样：第一次跑通过、第二次红）。
     */
    public function testWindowDestroyedByShellIsDroppedWithoutAFrame(): void
    {
        $platform = new Win32Platform();
        $window = $platform->window();
        $alive = $window->createWindow('活着', 640, 480);
        $dead = $window->createWindow('已销毁', 320, 200);

        $keep = new EchoApplication();
        $gone = new EchoApplication();
        $keep->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 240.0)));
        $gone->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(160.0, 120.0)));

        // ★ 模拟"用户点了没守卫那个窗口的 X"：壳直接销毁 —— 没有事件、没有 destroy 调用
        winDestroyedByShell($dead);

        winPumpScript([
            ['type' => 'key', 'key' => 'A', 'hwnd' => $alive],
            ['type' => 'quit'],
        ]);

        $keepBefore = $keep->frameCount();
        $goneBefore = $gone->frameCount();
        EventLoop::run($platform, [$alive => $keep, $dead => $gone]);

        check(
            '壳销毁 · 死窗口**一帧都不跑**（对着死句柄跑帧 = 0×0 紧约束 Fatal）',
            $gone->frameCount() === $goneBefore,
            \sprintf('frames=%d（之前 %d）', $gone->frameCount(), $goneBefore),
        );
        check(
            '壳销毁 · 活窗口照常跑帧（摘掉的是一个，不是整张表）',
            $keep->frameCount() === $keepBefore + 1,
            \sprintf('frames=%d（之前 %d）', $keep->frameCount(), $keepBefore),
        );

        $this->flushChecks();
    }

    /**
     * @param list<array{0: string, 1: int, 2?: bool}> $calls
     */
    private static function closeCall(array $calls, string $kind, int $handle): bool
    {
        foreach ($calls as $call) {
            if ($call[0] === $kind && $call[1] === $handle) {
                return true;
            }
        }

        return false;
    }
}
