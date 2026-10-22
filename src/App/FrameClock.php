<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Platform\TimerHost;

/**
 * 自适应帧时钟（§17.4.9）—— 把 `TimerHost` 接到消息循环上。
 *
 * ## 它修的是什么缺陷（不是优化）
 *
 * 平台的消息泵是**阻塞**的：`drift_win_pump()` 里 `GetMessageW` 没有消息就一直睡。
 * 于是后台任务的结果、分片任务的进度、socket 的可读，全都**只能等下一条用户消息
 * 捎带**。现象是"任务 1.2s 就完了，界面 3s 后（用户动了一下鼠标）才变"，以及
 * "Loading 转圈图一动不动"——**不报错**，所以一直没人发现。
 *
 * 修法只有一条：**有活的时候，让系统定时把循环叫醒**。`TimerHost` 早就实现了
 * （`SetTimer` → `WM_TIMER`），但全仓零调用者 —— 本类就是那个调用者。
 *
 * ★ **2026-10-18 反例自检**：把组合根里的 `pace()` / `tick()` 摘掉重建，
 *   `tools/probe-async.py` 的列表区墨量**始终 0.0%**（界面停在 Loading 不动）；
 *   接回来立刻 0.0% → 9.3%。这条判据不是自述，是抓得住反例的。
 *
 * ## 为什么是"自适应"而不是"永远 60fps"
 *
 * 恒定跳帧 = 空闲也在烧 CPU（笔记本风扇会告诉你）。所以判据交给
 * `Application::wantsFrame()`：它把"任务在途 / 分片没跑完 / 有流在监听"三件事
 * 收成一个是非题。三件事都做完 ⇒ 定时器停掉 ⇒ 消息泵重新阻塞 ⇒ **CPU 归零**。
 *
 * ## 15ms 不是 16ms（★ 别改成 16）
 *
 * Win32 的 `SetTimer` 量化到系统时钟中断（默认 ~15.6ms）。请求 16ms 会落到**第二**
 * 个 tick（≈31ms ⇒ 32fps）；请求 15ms 才落在第一个 tick（≈15.6ms ⇒ 64fps）。
 * 同一现象在探针 M 里实测过（`Coroutine::sleep(0.016)` → 31.6ms，
 * `sleep(0.008)` → 16.5ms，`probes/RESULTS.md`）。
 *
 * ## 卡顿观测（§17.4.9 的护栏）
 *
 * 上膛期间量**两帧间隔**（不是帧内耗时 —— 那需要读环境时钟，违反 G3 的
 * "时间戳由平台注入"）。间隔由 `Platform::now()` 给，框架不多读一次钟。
 * 间隔超阈值 ⇒ 说明上一帧里有阻塞调用（同步 IO / 重计算），这才是"UI 卡住"的
 * 直接证据。**只在 `armed` 时量**：空闲时两帧本来隔几秒，那不是卡。
 *
 * ★ 精度边界（诚实）：`GetTickCount64` 的粒度就是系统时钟中断（~15.6ms）⇒ 这个
 *   数字只能判**量级**（"卡了一下" vs "没卡"），不能判"卡了 23ms 还是 31ms"。
 *
 * @api 组合根每帧调一次（见 `apps/users/bin/win32/Bootstrap.php`）
 */
final class FrameClock
{
    /**
     * 帧间隔（毫秒）。
     *
     * ★ **不要改成 16** —— 见类注释：`SetTimer` 量化到 ~15.6ms 的系统 tick，
     *   请求 16 会变成 31ms/帧（32fps）。
     */
    public const FRAME_MS = 15;

    /** 明显卡顿阈值：约 4 帧。低于它的间隔可能只是漏了一个 tick（量化噪声）。 */
    public const STALL_MS = 60.0;

    /**
     * 冻结阈值：超过它就不是"抖了一下"，是"界面真的停了"。
     *
     * ★ 这一档走 `RECOVERABLE`（dev 下弹框中止，release 下只写日志），
     *   因为它是**应用代码里有同步调用**的确凿证据；60–500ms 那档只记
     *   `DIAGNOSTIC`（进环形缓冲，随任何一次中止一起落盘 —— 事后取证用）。
     */
    public const FREEZE_MS = 500.0;

    /**
     * 定时器 id（`SetTimer` 的 `nIDEvent`）。
     *
     * ★ 框架只用一个定时器 ⇒ 常量足够。`0x4452` = "DR"，便于在调试器里认出来。
     */
    private const TIMER_ID = 0x4452;

    /** 定时器当前是否在跑（`TimerHost::start()` 被接受且还没 `stop()`） */
    private bool $armed = false;

    /** 上一帧的时间戳（`null` = 还没跳过帧） */
    private ?float $lastTs = null;

    /** 平台拒绝起定时器时只报一次（否则每帧一条，把日志刷满） */
    private bool $armReported = false;

    /** 卡顿次数与最差间隔 —— devtools / 自检的观测面 */
    private int $stalls = 0;

    private float $worstGapMs = 0.0;

    public function __construct(
        private readonly TimerHost $timers,
    ) {
    }

    /**
     * 跑一帧，然后决定下一帧要不要被叫醒。
     *
     * ★ **顺序不可换**：先 `frame()` 再 `pace()`。`wantsFrame()` 读的是**跑完这一帧
     *   之后**的状态（任务可能刚回贴完、分片可能刚跑完）⇒ 先判后跑会多跳一帧。
     *
     * @param float $timestampMs 平台注入的单调毫秒（`Platform::now()`）
     */
    public function tick(Application $app, float $timestampMs, BoxConstraints $constraints): void
    {
        if ($this->armed && $this->lastTs !== null) {
            $gap = $timestampMs - $this->lastTs;
            if ($gap > self::STALL_MS) {
                $this->observe($gap);
            }
        }

        $this->lastTs = $timestampMs;

        // ★ 帧耗时**在这里量**（`06` §3.4）：`FrameClock` 本来就是"谁在管时间"
        //   的那一层，应用只收一个数字（不自己读钟 —— G3：帧的输入只有约束与树）。
        //   一对 `hrtime` 约 60ns，与一帧 3.1ms 相比可忽略。
        //   ★ 量的范围 = `frame()` 全段（四个泵 + settle + layout + paint + present），
        //   正是 `06` §3.2 那张六阶段表的合计口径。
        $startedNs = \hrtime(true);
        $app->frame($timestampMs, $constraints);
        $app->noteFrameCost((float) (\hrtime(true) - $startedNs) / 1e6);

        $this->pace($app->wantsFrame());
    }

    /**
     * 按"还有没有活"开关定时器（幂等 —— 状态没变就什么都不做）。
     *
     * ★ 组合根**必须在进循环之前先调一次**：此刻可能已经有在途任务（应用构造器里
     *   就提交了首屏取数），不定时器的话第一次 `pump()` 就会无限期阻塞。
     *
     * ★ `start()` 被平台拒绝（id 冲突 / 系统资源不足 / 还没建窗）⇒ 记一次
     *   `RECOVERABLE`：此时异步结果会退回"等下一条消息"的旧行为，**必须留痕**，
     *   否则又是一次"不报错的半失效"。
     */
    public function pace(bool $busy): void
    {
        if ($busy === $this->armed) {
            return;
        }

        if (!$busy) {
            $this->timers->stop(self::TIMER_ID);
            $this->armed = false;

            return;
        }

        $this->armed = $this->timers->start(self::TIMER_ID, self::FRAME_MS);

        if (!$this->armed && !$this->armReported) {
            $this->armReported = true;
            Errors::report(
                '帧时钟：平台拒绝了定时器（id 冲突 / 系统资源不足 / 窗口句柄无效）'
                . ' —— 后台结果将退回"等下一条用户消息捎带"的旧行为',
                Errors::RECOVERABLE,
            );
        }
    }

    /** 收尾：循环退出前停掉定时器（窗口销毁后它本来也会消失，这里只为对称） */
    public function stop(): void
    {
        if (!$this->armed) {
            return;
        }

        $this->timers->stop(self::TIMER_ID);
        $this->armed = false;
    }

    /** @internal devtools / 自检：定时器当前是否在跳 */
    public function isArmed(): bool
    {
        return $this->armed;
    }

    /** @internal devtools / 自检：上膛期间间隔超阈值的次数 */
    public function stallCount(): int
    {
        return $this->stalls;
    }

    /** @internal devtools / 自检：观测到的最差两帧间隔（毫秒；0 = 从未卡顿） */
    public function worstGapMs(): float
    {
        return $this->worstGapMs;
    }

    /**
     * 记录一次卡顿。
     *
     * ★ 间隔来自 `Platform::now()`（`GetTickCount64`），它的粒度就是系统时钟中断
     *   （~15.6ms）⇒ 这个数字**只能用来判断量级**，不是精确耗时。
     */
    private function observe(float $gap): void
    {
        $this->stalls++;

        if ($gap > $this->worstGapMs) {
            $this->worstGapMs = $gap;
        }

        Errors::report(
            \sprintf(
                '帧时钟：两帧间隔 %.0fms（阈值 %.0fms）—— 上一帧里有阻塞调用'
                . '（同步 IO / 重计算 / 忘了走 TaskHost？）',
                $gap,
                self::STALL_MS,
            ),
            $gap >= self::FREEZE_MS ? Errors::RECOVERABLE : Errors::DIAGNOSTIC,
        );
    }
}
