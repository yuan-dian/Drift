<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Input\CloseRequestedEvent;
use Drift\Platform\Platform;
use Drift\Platform\WakeReason;

/**
 * **框架侧的主循环**：等 → 派发 → 跑帧。组合根只剩一句 `EventLoop::run($platform, [$handle => $app])`。
 * 本类只编排：等靠 `Platform::waitEvents()`、派发靠 `Platform::pumpEvents()`、`quit` 由平台给出，一行 OS 调用都没有（不违反"循环归平台"）。
 * 多窗口：`$apps` 是 `array<int, Application>`，**键即窗口句柄**；每窗一个 `FrameClock`，`nextWaitMs` 取最小（最急的窗口决定睡多久），关掉一个窗不退出循环、表空才走。
 * `CloseRequestedEvent` 不进 `dispatchInput()`：关闭无命中目标，是窗口级生命周期（与 `WakeReason::Quit` 同族），应用同意 ⇒ 框架 `destroy()` 并立刻收工。
 * @api 组合根一行：`EventLoop::run($platform, [$handle => $app])`
 */
final class EventLoop
{
    /**
     * dev-only 的调试桥工厂；`null` = 不装桥（发布态路径，与加桥前一字节不差）。
     *
     * 唯一安装点是 `apps/dev/dev-entry.php`：它比组合根先跑，而 `AppEntry::run()`
     * 是 `final`（不能让每个应用各自改一行）。工厂收到窗口表，返回的桥由本循环
     * 在 `finally` 里关 —— 谁装谁不管收尾，就会有"关窗后发现文件留僵尸"。
     *
     * @var null|\Closure(array<int, Application>): ?DebugTicker
     */
    private static ?\Closure $tickerFactory = null;

    /** @param null|\Closure(array<int, Application>): ?DebugTicker $factory */
    public static function setTickerFactory(?\Closure $factory): void
    {
        self::$tickerFactory = $factory;
    }

    /**
     * 跑到**窗口表空了**为止（单窗口时与 `WakeReason::Quit` 等价）。
     * @param Platform $platform 任意平台实现（架构无关：本类不认识 Win32）
     * @param array<int, Application> $apps **键 = 窗口句柄**，值 = 已 `attach()` 过**该窗口**表面的应用。单窗口也走数组（不留"单窗重载"：两套写法即两条路径，只有一条会被真机跑到）。
     */
    public static function run(Platform $platform, array $apps): void
    {
        /** @var array<int, FrameClock> $clocks 每窗一个：帧记账 / 卡顿判据按窗口算 */
        $clocks = [];
        $window = $platform->window();

        // 桥只在 dev 壳里装。工厂在循环开始前调用一次：窗口表此刻才齐。
        $factory = self::$tickerFactory;
        $ticker = $factory === null ? null : $factory($apps);
        $ticker?->bind($apps);

        try {
            while (true) {
                // ① 睡多久：**最急的窗口**说了算（两窗节奏不同，取最小不是取平均）。
                //   ★ `-1` 是"无限等"哨兵，**不能参与取最小**：`min(4000, -1)` 是 -1 ⇒ 有定时器的窗口被空闲窗口拖成永远睡。
                $now = $platform->now();
                $wait = -1.0;
                foreach ($apps as $handle => $app) {
                    $clocks[$handle] ??= new FrameClock();
                    $ms = $clocks[$handle]->nextWaitMs($app, $now);
                    if ($ms < 0.0) {
                        continue;   // 这个窗口没事可做 ⇒ 不投票（全部如此才是 -1）
                    }
                    $wait = $wait < 0.0 ? $ms : \min($wait, $ms);
                }

                // ★ 调试桥把"无限等"改成"最多等 hint"：否则空闲窗口没消息 ⇒ 永远不醒 ⇒
                //   挂在 socket 上的观察者一条消息都送不进来。没有桥时 hint 为 null，
                //   `$wait` 原样交给平台（老路径：空闲时零次限时等待）。
                $hint = $ticker?->waitHintMs();
                if ($hint !== null) {
                    $wait = $wait < 0.0 ? $hint : \min($wait, $hint);
                }

                // ② 等
                $why = $platform->waitEvents($wait);

                // ③ 退出：**先走再派发** —— 与拆分前的 `pump()` 同口径（quit 不投事件）
                if ($why === WakeReason::Quit) {
                    break;
                }

                // ③′ 调试消息先于原生派发：暂停时这一轮没有帧，但消息仍必须被收走 ——
                //   否则 `resume` 自己就被卡在门外，永远没人能解除暂停。
                $ticker?->poll();

                // ★ `WakeReason::Woken` 不需要额外分支：它是"被 IO 线程叫醒"、无载荷，`pumpEvents()` 空转一次后照常跑帧，那一帧里 `pumpTasks()` → `drain()` 取走结果。
                //   "唤醒"与"超时到点"在这里是同一条路（都"该跑一帧了"），只有 `Quit` 是另一条。别为它加 `continue`：跳过帧 = 被叫醒却什么都不干、回去接着睡 ⇒ 结果永远取不走。

                // ④ 平台暂存的原始事件 → **各自窗口**的队列（`translate()` 按事件自带的 hwnd 投）。
                //   ★ ④ 必须在 ⑤ 之前：`input()` 是队列，`pumpEvents()` 往里投、`poll()` 往外取。反了 = "取到空队列、帧里再无事件"（不报错，窗口就是不动）。
                $platform->pumpEvents();

                // 模态时长读一次、逐窗都扣，不放在 `advance()` 实参里：同一次原生模态把整个循环挂起，对每个窗口都是真实发生的阻塞；读两次只会让第二个窗口拿到 0 ⇒ 白弹一次 RECOVERABLE（读后即清）。
                $modal = $platform->consumeModalBlockedMs();

                $done = [];

                // ⑤ 逐窗逐条派发
                foreach ($apps as $handle => $app) {
                    // 壳直接销毁的窗口（无关闭守卫 ⇒ 用户点 X 就没了）：框架收不到事件，只能显式查询存活。
                    //   不摘掉会对着死句柄跑一帧 ⇒ 客户区 0×0 紧约束 ⇒ 布局硬校验 Fatal（点 X 后闪错误框、时好时坏）。判据必须是显式存活查询，不能用"客户区 0×0"—— 最小化也是 0×0。
                    if (!$window->isAlive($handle)) {
                        $done[] = $handle;

                        continue;
                    }

                    // 调试桥按下暂停：连输入一起不派发，队列留在平台里等恢复。
                    // 只挡帧不挡输入会让"暂停"这句话撒谎 —— 状态照变，只是没画。
                    if ($ticker !== null && !$ticker->canAdvance()) {
                        continue;
                    }

                    $input = $platform->input($handle);

                    while (($event = $input->poll()) !== null) {
                        // 关闭请求**不进命中链**：它不是"点在某控件上"，无命中目标、也不该被浮层拦下，是窗口级生命周期（与 `WakeReason::Quit` 同族）。此分支在 `dispatchInput()` 之前并 `continue`（IME 位置不因"有人按了 X"而变）。
                        if ($event instanceof CloseRequestedEvent) {
                            // 应用说"可以"⇒ **框架**执行销毁（不是应用自己 close —— 那会再发一条 WM_CLOSE，自己问自己）。
                            if ($app->closeRequested()) {
                                // 销毁之后**这一窗立刻收工**：再跑一帧 = 对着死句柄求客户区 ⇒ 0×0 紧约束 ⇒ 布局硬校验 Fatal（点 X 后闪错误框）。不退出循环，其余窗口继续跑，表空才走。
                                $window->destroy($handle);
                                $done[] = $handle;

                                continue 2;   // 跳过这个窗口剩下的动作，直接看下一个窗
                            }

                            continue;   // 应用拒绝：窗口原样留着，什么都不做
                        }

                        $shape = $app->dispatchInput($event, $platform->now());
                        if ($shape !== null) {
                            // 光标由**这里**喂给 `WindowHost`：应用它需要平台类型，而框架不持平台 ⇒ 组合根是唯一合法位置。
                            $window->setCursor($handle, $shape);
                        }
                        // IME 时序双保险：组字可能发生在同帧的下一个输入事件（`TranslateMessage` 触发 WM_IME_STARTCOMPOSITION）⇒ 事件处理后立即同步 caret，不等帧末（帧末版会晚到，输入法已按旧位置放过一次候选框）。与帧末那次同口径。
                        $platform->imeCaret($handle, $app->imeCaretRect());
                    }

                    // ⑥ 帧（顺序不可换：`wantsFrame()` 读的是跑完这一帧之后的状态）。
                    //   第 ④ 个参数 = 这轮被原生模态挂起的时长（对话框 / fatalBox）；不剔除的话用户在对话框里停留几秒，下一帧就报一次"两帧间隔 N ms"的 RECOVERABLE。
                    //   派发**期间**弹的模态必须在这里补读：上面那次读发生在派发之前，而原生模态几乎总发生在派发里（`Dialog::alert()` 同步阻塞到用户关框）。漏读 = 关掉系统提示窗后弹一条"两帧间隔 2297ms"，像框架自己出了问题。
                    //   累加到同一个量上、逐窗都扣：这段挂起对每个窗都真实发生（循环串行，它们那一刻都没跑帧），与上面"读一次、逐窗都扣"同口径。
                    $modal += $platform->consumeModalBlockedMs();

                    $clocks[$handle]->advance(
                        $app,
                        $platform->now(),
                        $platform->screen($handle)->viewport(),
                        $modal,
                    );

                    // 单步预算在**帧真的跑完**之后扣：暂停请求本身不能算一帧。
                    $ticker?->afterFrame();

                    // ⑦ IME 定位：框架算几何（逻辑像素）、平台做量化。
                    //   组合根不调这一行 = 输入法候选框贴在窗口左上角（能打字、候选框位置错）。
                    $platform->imeCaret($handle, $app->imeCaretRect());
                }

                // 摘掉已关掉的窗口：在 `foreach` 里改 `$apps` 会破坏遍历 ⇒ 循环后统一摘。
                foreach ($done as $handle) {
                    unset($apps[$handle], $clocks[$handle]);
                }

                // 表空 ⇒ 收工，**不等壳的 `Quit`**：此时 `nextWaitMs` 无输入，下一轮算 -1（无限等）而无窗口能再叫醒它 —— 那是挂死，不是退出。
                if ($apps === []) {
                    break;
                }
            }
        } finally {
            // 关窗、Quit、异常都走这里：发现文件里的条目不能比窗口活得久。
            $ticker?->close();
        }
    }
}
