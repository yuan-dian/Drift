<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\Application;
use Drift\App\EventLoop;
use Drift\Dev\Smoke\App\CloseGuardApplication;
use Drift\Dev\Smoke\App\CursorApplication;
use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
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
        EventLoop::run($platform, $app, $handle);

        check(
            '主循环 · `quit` 直接跳出：不再派发、不再跑帧',
            $app->frameCount() === $frames,
            'frames=' . $app->frameCount() . '（之前 ' . $frames . '）',
        );
        check(
            '主循环 · 空闲应用**一次 `SetTimer` 都不发**（旧心跳是每 15ms 一次）',
            winTimerCalls() === [],
            \sprintf('%d 次：%s', \count(winTimerCalls()), \json_encode(winTimerCalls())),
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
        EventLoop::run($platform, $app, $handle);

        check(
            '主循环 · 一条消息 ⇒ 恰好一帧（`quit` 那一轮不跑）',
            $app->frameCount() === $frames + 1,
            \sprintf('frames=%d → %d', $frames, $app->frameCount()),
        );
        check(
            '主循环 · 事件被抽干（`pumpEvents()` 在 `poll()` 之前，队列不残留）',
            $platform->input()->poll() === null,
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
            ['type' => 'timer', 'id' => 'armed'],   // 我们的闹钟（到点）
            ['type' => 'quit'],
        ]);
        EventLoop::run($platform, $app, $handle);

        // ★ 两轮 = 两次等待（第一轮等到点、第二轮被 `quit` 打断）—— 关键是**时长**：
        //   每一次都是 400，而不是 15。旧心跳下同一个 400ms 定时器要 27 次 15ms。
        $starts = \array_values(\array_filter(winTimerCalls(), static fn (array $c): bool => $c[0] === 'start'));
        $allFar = $starts !== [];
        foreach ($starts as $c) {
            $allFar = $allFar && $c[2] === 400;
        }
        check(
            '主循环 · `after(400)` ⇒ 每轮都是 400ms 的等待（旧心跳是 27 次 15ms）',
            $allFar && \count($starts) === 2,
            \json_encode($starts),
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
            ['type' => 'timer', 'id' => 'armed'],
            ['type' => 'quit'],
        ]);
        EventLoop::run($platform, $app, $handle);

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
        EventLoop::run($platform, $app, $handle);

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
        EventLoop::run($platform, $app, $handle);

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
        EventLoop::run($platform, $app, $handle);

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
        EventLoop::run($platform, $app, $handle);

        check(
            '关闭 · 事件被循环自己消费掉，不留在 `input()` 队列里',
            $platform->input()->poll() === null,
            '队列里还有事件',
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
