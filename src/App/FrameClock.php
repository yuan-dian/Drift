<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;

/**
 * 帧时钟：**管"什么时候该跑下一帧"与"这一帧花了多久"**（§17.4.9）。
 *
 * ## 它解决的问题（不是优化，是缺陷）
 *
 * 平台的消息泵是**阻塞**的：没有输入事件时 `GetMessageW` 一直睡。于是后台任务的
 * 结果、分片任务的进度、socket 的可读，全都**只能等下一条用户消息捎带**。现象是
 * "任务 1.2s 就完了，界面 3s 后（用户动了一下鼠标）才变"，以及"Loading 转圈图
 * 一动不动"——**不报错**，所以一直没人发现。
 *
 * ★ **2026-10-18 反例自检**：把组合根里的时钟摘掉重建，`tools/probe-async.py` 的
 *   列表区墨量**始终 0.0%**（界面停在 Loading 不动）；接回来立刻 0.0% → 9.3%。
 *
 * ## 2026-10-30（N2）之后的形态：**只出策略，不持有任何东西**
 *
 * 改动之前它自己开关一个 15ms `SetTimer`（"心跳"）：跑完一帧就上膛，`wantsFrame()`
 * 转假就落闸。那套机制的问题是**它无法表达"等 400ms"** —— 请求了 `after(400)` 的
 * 应用照样每 15ms 醒一次、跑一帧空帧（**33 次唤醒等一个定时器**），而且它假定了
 * "叫醒"只能靠定时器。
 *
 * 现在叫醒归**平台**（`Platform::waitEvents($timeoutMs)`），本类只回答两个问题：
 *
 * ```text
 * nextWaitMs(app, now)  →  下一轮该等多久（-1 无限 / 0 尽快 / >0 到点）
 * advance(app, ts, c)   →  跑一帧 + 记三笔账（间隔、lastTs、帧耗时）
 * ```
 *
 * ⇒ 本类**不再 import `TimerHost`**：它没有副作用，"问策略"与"动系统"分开。
 *   好处不是洁癖 —— 策略立刻变成可测的纯计算（`Feature/App/FrameWaitTest`）。
 *
 * ## 15ms 不是 16ms（★ 别改成 16）
 *
 * Win32 的 `SetTimer`/系统定时器量化到系统时钟中断（默认 ~15.6ms）。请求 16ms 会
 * 落到**第二**个 tick（≈31ms ⇒ 32fps）；请求 15ms 才落在第一个 tick（≈15.6ms ⇒
 * 64fps）。同一现象在探针 M 里实测过（`Coroutine::sleep(0.016)` → 31.6ms，
 * `sleep(0.008)` → 16.5ms，`probes/RESULTS.md`）。
 *
 * ## 卡顿观测（§17.4.9 的护栏）
 *
 * 量**两帧间隔**（不是帧内耗时 —— 那需要读环境时钟，违反 G3 的"时间戳由平台注入"）。
 * 间隔由 `Platform::now()` 给，框架不多读一次钟。间隔超阈值 ⇒ 说明上一帧里有阻塞
 * 调用（同步 IO / 重计算），这才是"UI 卡住"的直接证据。
 *
 * ★★ **只在"按帧节奏跑"的那一轮才量**：等了一个 400ms 的定时器之后再来跑帧，
 *   间隔本来就该是 400ms —— 那不是卡，是我们自己要求的。判据是
 *   `nextWaitMs()` 上一轮给的值落在 `[0, FRAME_MS]`（见 `advance()`）。
 *
 * ★ 精度边界（诚实）：`GetTickCount64` 的粒度就是系统时钟中断（~15.6ms）⇒ 这个
 *   数字只能判**量级**（"卡了一下" vs "没卡"），不能判"卡了 23ms 还是 31ms"。
 *
 * @api 组合根不直接用 —— `EventLoop` 每轮问一次
 */
final class FrameClock
{
    /**
     * 帧间隔（毫秒）。
     *
     * ★ **不要改成 16** —— 见类注释：定时器量化到 ~15.6ms 的系统 tick，
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

    /** 上一帧的时间戳（`null` = 还没跑过帧） */
    private ?float $lastTs = null;

    /**
     * 上一轮 `nextWaitMs()` 给出的等待时长（`null` = 没问过）。
     *
     * ★ 只服务一件事：判断**这一轮的间隔值不值得看**（见 `advance()`）。
     */
    private ?float $lastWaitMs = null;

    /** 卡顿次数与最差间隔 —— devtools / 自检的观测面 */
    private int $stalls = 0;

    private float $worstGapMs = 0.0;

    /**
     * 跑一帧并记三笔账，**不碰任何平台资源**。
     *
     * @param float $timestampMs 平台注入的单调毫秒（`Platform::now()`）
     */
    public function advance(Application $app, float $timestampMs, BoxConstraints $constraints): void
    {
        // ★ 只在"上一轮是**按帧节奏**等的"时候量间隔：`-1`（无限等，说明空闲）与
        //   `> FRAME_MS`（等到一个定时器）都不算卡 —— 那是我们要的等待。
        $waited = $this->lastWaitMs;
        if ($waited !== null && $waited >= 0.0 && $waited <= self::FRAME_MS && $this->lastTs !== null) {
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
    }

    /**
     * 下一轮**该等多久**（毫秒）—— 三态，与 `Platform::waitEvents()` 同一个表示。
     *
     * | 返回值 | 含义 | 什么时候 |
     * |---|---|---|
     * | `< 0`（`-1`） | 无限等 | 真的没事（无动画、无定时器、无异步在途、无欠账） |
     * | `0` | **尽快**跑帧 | 有一笔"立刻要画"的欠账（环 / 面板落后） |
     * | `> 0` | 等到点 | 按帧节奏（动画 / 异步在途），或等最早的定时器 |
     *
     * ★★ **这里推翻了设计册 §3.5 的初稿**：初稿写"动画中 ⇒ 返回 `0.0`"
     *   （winit 的 `ControlFlow::Poll` 直译）。在本仓这是错的 —— 本仓**没有 vsync**，
     *   `0` 意味着"能跑多快跑多快"：一帧 3.1ms ⇒ 300fps 空转，而动画相位是
     *   `f(timestamp)`，帧多了只会烧 CPU、不会更顺。今天的 15ms 帧节奏**必须保留**，
     *   在这里它变成"等 15ms 再来"，而不是"不等、连跑"。
     * ★★ **同样推翻初稿的另一半**：初稿说"有 IO watcher 但没数据 ⇒ 走 `Wait`、
     *   CPU 归零"。**那会把 socket 饿死** —— 本仓的 `IoPoller` 只能在帧里被
     *   `poll()`（没有唤醒通道，也拿不到 SOCKET 去等 fd），走无限等就再没人问它。
     *   所以"有 watcher"必须是**轮询**（帧节奏），直到 N4 的唤醒通道 / N5 的
     *   C++ IO 线程把它变成真事件。
     * ★ `0` 与 `> 0` 的分界不是"有没有活"，而是"**这笔活是不是只欠一次**"。
     */
    public function nextWaitMs(Application $app, float $nowMs): float
    {
        // ④ 真的没事 —— `wantsFrame()` 是三个谓词的"或"，所以它是唯一的空转判据
        if (!$app->wantsFrame()) {
            return $this->lastWaitMs = -1.0;
        }

        // ① 连续帧：按帧节奏醒（`0` 在这里是错的，见上）
        $wait = $app->wantsFrameRhythm() ? (float) self::FRAME_MS : \PHP_FLOAT_MAX;

        // ② 定时器：到点才醒（可能比帧节奏近得多，也可能远得多 —— 后者正是收益）
        $due = $app->nextDeadlineMs();
        if ($due !== null) {
            $until = $due - $nowMs;
            if ($until < 0.0) {
                $until = 0.0;   // 已经过期 ⇒ 下一轮立刻跑
            }
            if ($until < $wait) {
                $wait = $until;
            }
        }

        // ③ 走到这里还没定 ⇒ 只剩"一次性欠账"（环 / 面板落后），画一帧就清
        return $this->lastWaitMs = ($wait === \PHP_FLOAT_MAX ? 0.0 : $wait);
    }

    /** @internal devtools / 自检：间隔超阈值的次数 */
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
