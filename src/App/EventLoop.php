<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Input\CloseRequestedEvent;
use Drift\Platform\Platform;
use Drift\Platform\WakeReason;

/**
 * **框架侧的主循环**（§3.4 / §3.5 轴 B1+B2）：等 → 派发 → 跑帧。
 *
 * ## 它为什么存在（不是"把四行搬进一个类"）
 *
 * 改动之前，四个应用的组合根各自写着**同一段 12 行**的循环：
 *
 * ```php
 * $clock = new FrameClock($platform->timers());
 * $clock->pace($app->wantsFrame());          // 进循环前先上膛
 * while ($platform->pump()) {                // ← 阻塞，没有超时
 *     while (($event = $input->poll()) !== null) { ...光标 / IME... }
 *     $clock->tick($app, $platform->now(), $screen->viewport());
 *     $platform->imeCaret($app->imeCaretRect());
 * }
 * $clock->stop();
 * ```
 *
 * 这 12 行里有四条**互不相关**的契约（先上膛、每事件同步 IME、每帧同步 IME、
 * 退出停表），任何一条漏掉都表现为"不报错的半失效"（`FrameClock` 的类注释与
 * `AppBootstrapLoop` 那条 lint 规则都是为这类事故补的）。等待层一拆，这段还要
 * 再长一倍 —— 4 份拷贝就是 4 个出错机会。
 *
 * ⇒ 2026-10-30（N2）**把上面那一整套换掉了，不留两套**：`pump()`、`pace()`、
 *   `tick()`、`stop()` 全部删除（用户裁决：能一套就一套，否则维护要同时看两条路）。
 *   组合根现在只剩一句 `EventLoop::run($platform, $app, $handle)`。
 *   ★ 删而不是留委托，理由不只是"少一个方法"：`pump()` **表达不了超时**，
 *     留着它等于留着一个"看起来还能用、实则必然退回恒定心跳"的入口 ——
 *     那是 2026-10-25 那次"界面停住、日志一个字都没有"的同款入口。
 *
 * ## 不违反「循环归平台」（§5.2 裁决 1）
 *
 * 那条裁决禁的是**框架自建事件源**（自己 `GetMessage` / 自己 `select`）。
 * 本类一行 OS 调用都没有：等靠 `Platform::waitEvents()`、派发靠
 * `Platform::pumpEvents()`、`quit` 由平台给出。它只是**编排**，等价于原先把
 * 同样几行写在组合根里 —— 差别是只写一次。
 *
 * ## 一轮的次序（不可换）
 *
 * ```text
 * ① 问"还要等多久"        所有窗口的 FrameClock::nextWaitMs() 取**最小**
 * ② 等                    Platform::waitEvents()     —— 无限 / 到点 / 立刻
 * ③ 看为什么醒            WakeReason::Quit ⇒ 走
 * ④ 派发                  Platform::pumpEvents()     —— 按 hwnd 投进各自窗口的 input()
 * ⑤ 逐窗逐条派给应用      dispatchInput()            —— 光标 / IME 必须在每条之后
 * ⑥ 逐窗跑一帧            FrameClock::advance()      —— 内含 frame() + 帧耗时记账
 * ⑦ 逐窗帧末同步 IME 位置
 * ```
 *
 * ★ **多窗口**（2026-10-30，M1-b）：`$apps` 是 `array<int, Application>`，
 *   **键就是窗口句柄**。每窗一个 `FrameClock`（帧记账 / 卡顿判据都是按窗口的，
 *   两窗各自有各自的节奏），`nextWaitMs` 取最小 —— "最急的那个窗口决定睡多久"。
 *   ★ 模态时长（`consumeModalBlockedMs()`）**读一次、每个窗都扣**：同一次原生
 *     模态把整个循环都挂起了，对每个窗口都是"真实发生过"的阻塞；分成两次读
 *     只会让第二个窗口白弹一次 RECOVERABLE（第一窗读后即清）。
 *   ★ **关掉一个窗不退出循环**：把它从 `$apps` 里摘掉继续跑剩下的；表空了才走
 *     （与壳侧"活窗口表空了才 `PostQuitMessage`"同一判据）。
 *
 * ★ **⑤ 里有一个例外**（2026-10-30）：`CloseRequestedEvent` 不进 `dispatchInput()`
 *   —— 关闭没有命中目标，它是窗口级生命周期（与 `WakeReason::Quit` 同族）。
 *   应用同意 ⇒ 框架 `destroy()` 并**立刻收工**（理由见那一行的注释）。
 *
 * ★ **④ 必须在 ⑤ 之前**：`input()` 是队列，`pumpEvents()` 往里投、`poll()` 往外取。
 *   反了就是"取到一个空队列、然后帧里再也没有事件"（不报错，窗口就是不动）。
 * ★ **⑥ 每轮都跑**：`waitEvents` 返回 `Timeout` 也跑（IO 轮询、定时器到点都要在帧里
 *   消费）。"这一帧有没有东西可画"由 N1 的 paint 门控回答，不由这里回答 ——
 *   两件事分开，才不会出现"为了省一次空帧而漏掉一次该画的帧"。
 * ★ **`waitEvents(0)` 仍会调**（`nextWaitMs` 给 0 = 有一笔立刻要画的欠账）——
 *   故意不退化成"跳过等待"：跳过就等于"能跑多快跑多快"，而 Win32 上 0 最多被
 *   量化到一个系统 tick（~15.6ms），代价与今天的心跳同量级，换来的是**不可能
 *   空转**（没有"某个欠账谓词一直为真 ⇒ 100% CPU 且收不到输入"这种死法）。
 *
 * @api 组合根一行：`EventLoop::run($platform, [$handle => $app])`
 */
final class EventLoop
{
    /**
     * 跑到**窗口表空了**为止（单窗口时与 `WakeReason::Quit` 等价）。
     *
     * @param Platform $platform 任意平台实现（架构无关：本类不认识 Win32）
     * @param array<int, Application> $apps **键 = 窗口句柄**，值 = 已 `attach()` 过
     *   **该窗口**表面的应用。单窗口也走数组 —— 不留"单窗重载"：两套写法就是
     *   两条路径，而只有一条会被真机跑到。
     */
    public static function run(Platform $platform, array $apps): void
    {
        /** @var array<int, FrameClock> $clocks 每窗一个：帧记账 / 卡顿判据按窗口算 */
        $clocks = [];
        $window = $platform->window();

        while (true) {
            // ① 睡多久：**最急的那个窗口**说了算（两窗节奏不同，取最小不是取平均）
            //   ★ `-1` 是"无限等"的哨兵，**不能参与取最小**：`min(4000, -1)` 是 -1
            //     ⇒ 有定时器的窗口会被空闲窗口拖成永远睡（单窗口永远碰不到这一支）。
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

            // ② 等
            $why = $platform->waitEvents($wait);

            // ③ 退出：**先走再派发** —— 与拆分前的 `pump()` 同口径（quit 不投事件）
            if ($why === WakeReason::Quit) {
                break;
            }

            // ④ 平台暂存的原始事件 → **各自窗口**的队列（`translate()` 按事件自带的 hwnd 投）
            $platform->pumpEvents();

            // ★ **读一次、逐窗都扣**，不放在 `advance()` 的实参里：同一次原生模态
            //   把整个循环都挂起了，对每个窗口都是真实发生的阻塞。读两次只会让
            //   第二个窗口拿到 0 ⇒ 白弹一次 RECOVERABLE（读后即清的契约）。
            $modal = $platform->consumeModalBlockedMs();

            $done = [];

            // ⑤ 逐窗逐条派发
            foreach ($apps as $handle => $app) {
                // ★ **壳直接销毁的窗口**（没有声明关闭守卫 ⇒ 用户点 X 就没了）：
                //   框架收不到任何事件，只能问。不摘掉它就会对着死句柄跑一帧 ⇒
                //   客户区 0×0 紧约束 ⇒ 布局硬校验 Fatal（真机现象：点 X 之后闪一下
                //   错误框、进程停在框上不退 —— 且**时好时坏**，取决于 `WM_QUIT`
                //   前面还排着几条 `WM_KILLFOCUS` / `WM_ACTIVATE`）。
                //   ★ 判据必须是显式存活查询，**不能**用"客户区 0×0"—— 最小化也是 0×0。
                if (!$window->isAlive($handle)) {
                    $done[] = $handle;

                    continue;
                }

                $input = $platform->input($handle);

                while (($event = $input->poll()) !== null) {
                    // ★ 关闭请求**不进命中链**（2026-10-30）：它不是"点在某个控件上"，
                    //   没有命中目标、也不该被浮层拦下 —— 它是**窗口级生命周期**，
                    //   与 `WakeReason::Quit` 同族。所以这一分支在 `dispatchInput()`
                    //   之前，且 `continue` 掉（IME 位置不会因为"有人按了 X"而变）。
                    if ($event instanceof CloseRequestedEvent) {
                        // ★ 应用说"可以"⇒ **框架**执行销毁（不是应用自己 close ——
                        //   那会再发一条 WM_CLOSE，自己问自己）。
                        if ($app->closeRequested()) {
                            // ★ 销毁之后**这一窗立刻收工**：再跑一帧就是"对着死句柄
                            //   求客户区" ⇒ 0×0 紧约束 ⇒ 布局硬校验 Fatal（真机现象：
                            //   点 X 之后闪一下错误框）。
                            // ★ 但**不退出循环**（2026-10-30，M1-b）：其余窗口继续跑，
                            //   表空了才走。
                            $window->destroy($handle);
                            $done[] = $handle;

                            continue 2;   // 跳过这个窗口剩下的动作，直接看下一个窗
                        }

                        continue;   // 应用拒绝：窗口原样留着，什么都不做
                    }

                    $shape = $app->dispatchInput($event, $platform->now());
                    if ($shape !== null) {
                        // ★ 光标由**这里**喂给 `WindowHost`：应用它需要平台类型，
                        //   而框架不持平台（§5.1）⇒ 组合根那一层是唯一合法位置。
                        $window->setCursor($handle, $shape);
                    }
                    // ★ IME 时序双保险（2026-10-19）：组字可能发生在同帧的**下一个**输入
                    //   事件（`TranslateMessage` 在消息循环层触发 WM_IME_STARTCOMPOSITION）
                    //   ⇒ 事件处理后立即同步 caret，不等帧末（帧末版会晚到，输入法已按旧
                    //   位置放过一次候选框）。与帧末那次同口径。
                    $platform->imeCaret($handle, $app->imeCaretRect());
                }

                // ⑥ 帧（顺序不可换：`wantsFrame()` 读的是跑完这一帧之后的状态）
                //   ★ 第 ④ 个参数 = 这轮里被原生模态挂起的时长（对话框 / fatalBox）。
                //     不剔除的话，用户在对话框里停留几秒，下一帧就报一次
                //     "两帧间隔 N ms"的 RECOVERABLE（2026-10-30 真机取证）。
                $clocks[$handle]->advance(
                    $app,
                    $platform->now(),
                    $platform->screen($handle)->viewport(),
                    $modal,
                );

                // ⑦ IME 定位：框架算几何（逻辑像素）、平台做量化。
                //   组合根不调这一行 = 输入法候选框贴在窗口左上角（能打字、候选框位置错）。
                $platform->imeCaret($handle, $app->imeCaretRect());
            }

            // ★ 摘掉已经关掉的窗口：在 `foreach` 里改 `$apps` 会破坏遍历 ⇒ 循环后统一摘
            foreach ($done as $handle) {
                unset($apps[$handle], $clocks[$handle]);
            }

            // ★ 表空 ⇒ 收工，**不等壳的 `Quit`**：此时 `nextWaitMs` 没有输入，
            //   下一轮会算出 -1（无限等）而没有窗口能再叫醒它 —— 那不是退出，是挂死。
            if ($apps === []) {
                break;
            }
        }
    }
}
