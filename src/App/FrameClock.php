<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;

/**
 * 帧时钟：只出"何时跑下一帧、这一帧花了多久"的策略，**不持有平台资源**，叫醒归平台。
 * nextWaitMs(-1/0/>0) 告诉 Platform::waitEvents() 该等多久，advance() 跑一帧并记间隔/帧耗时。
 * 平台消息泵空闲时后台任务只能等用户消息捎带，故卡顿观测只看"两帧间隔"，且只在按帧节奏等的那轮量。
 * @api 组合根不直接用 —— `EventLoop` 每轮问一次
 */
final class FrameClock
{
    /**
     * 帧间隔（毫秒）。
     * ★ **不要改成 16** —— 定时器量化到 ~15.6ms 的系统 tick，请求 16 会变成 31ms/帧（32fps）。
     */
    public const FRAME_MS = 15;

    /** 明显卡顿阈值：约 4 帧。低于它的间隔可能只是漏了一个 tick（量化噪声）。 */
    public const STALL_MS = 60.0;

    /**
     * 冻结阈值：超过它就不是"抖了一下"，是"界面真的停了"。
     * 这一档走 `RECOVERABLE`（dev 弹框中止，release 只写日志），因为是应用有同步调用的确凿证据；
     * 60–500ms 那档只记 `DIAGNOSTIC`（进环形缓冲，随任何一次中止一起落盘 —— 事后取证）。
     */
    public const FREEZE_MS = 500.0;

    /** 上一帧的时间戳（`null` = 还没跑过帧） */
    private ?float $lastTs = null;

    /**
     * 上一轮 `nextWaitMs()` 给出的等待时长（`null` = 没问过）。
     * 只服务一件事：判断**这一轮的间隔值不值得看**（见 `advance()`）。
     */
    private ?float $lastWaitMs = null;

    /** 卡顿次数与最差间隔 —— devtools / 自检的观测面 */
    private int $stalls = 0;

    private float $worstGapMs = 0.0;

    /**
     * 跑一帧并记账，**不碰任何平台资源**。
     * @param float $timestampMs 平台注入的单调毫秒（`Platform::now()`）
     * @param float $suspendedMs 上一帧后**原生模态**挂起的时长（毫秒，`Platform::consumeModalBlockedMs()` 读后即清）。
     *        ★ 这段必须**从间隔里剔除**：用户在原生对话框里停留的时间全被算进两帧间隔，
     *        不剔就会"每次弹框白弹一个错误框"。剔除只对模态生效：慢处理器/慢帧照样报。
     */
    public function advance(Application $app, float $timestampMs, BoxConstraints $constraints, float $suspendedMs = 0.0): void
    {
        // 只在"上一轮是按帧节奏等的"时候量间隔：`-1`（无限等）与
        // `> FRAME_MS`（等到一个定时器）都不算卡 —— 那是我们要的等待。
        $waited = $this->lastWaitMs;
        if ($waited !== null && $waited >= 0.0 && $waited <= self::FRAME_MS && $this->lastTs !== null) {
            // 模态挂起的时间**先扣再比**：扣完还超阈值才是真卡
            // （同步 IO / 重计算 / 忘了走 TaskHost）。负数不可能出现
            // （模态时间 ⊆ 两帧之间），但夹到 0 便宜且免解释。
            $gap = \max(0.0, $timestampMs - $this->lastTs - \max(0.0, $suspendedMs));
            if ($gap > self::STALL_MS) {
                $this->observe($gap);
            }
        }

        $this->lastTs = $timestampMs;

        // 帧耗时在这里量：应用只收一个数字（不自己读钟）。一对 `hrtime` 约 60ns，可忽略。
        $startedNs = \hrtime(true);
        $app->frame($timestampMs, $constraints);
        $app->noteFrameCost((float) (\hrtime(true) - $startedNs) / 1e6);
    }

    /**
     * 下一轮**该等多久**（毫秒）—— 三态，与 `Platform::waitEvents()` 同一表示：
     * `< 0` 无限等（无动画/定时器/异步在途/欠账），`0` 尽快跑帧（有一笔立刻要画的欠账），
     * `> 0` 等到点（按帧节奏，或等最早定时器）。
     * ★ `0` 与 `> 0` 的分界不是"有没有活"，而是"**这笔活是不是只欠一次**"：
     *   `0` 意味着"能跑多快跑多快"——本仓没有 vsync，会空转到 300fps 烧 CPU。
     */
    public function nextWaitMs(Application $app, float $nowMs): float
    {
        $pending = $app->wantsFrame();
        $due = $app->nextDeadlineMs();

        // 真的没事 —— 既没有欠账、也没有定时器在等
        if (!$pending && $due === null) {
            return $this->lastWaitMs = -1.0;
        }

        // ① 欠账：**一次性**的给 `0`（马上去跑），**按节奏**的给帧间隔。
        //    分界不是"有没有活"，而是"这笔活是不是只欠一次"。
        $wait = \PHP_FLOAT_MAX;
        if ($pending) {
            $wait = $app->needsFrameImmediately() ? 0.0 : (float) self::FRAME_MS;
        }

        // ② 定时器：到点才醒（可能比帧节奏近得多，也可能远得多）。
        //    定时器回答"什么时候才要"，故与 ① 取较早者，而不是并进"要帧"。
        if ($due !== null) {
            $until = $due - $nowMs;
            if ($until < 0.0) {
                $until = 0.0;   // 已经过期 ⇒ 下一轮立刻跑
            }
            if ($until < $wait) {
                $wait = $until;
            }
        }

        return $this->lastWaitMs = $wait;
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
     * 记录一次卡顿。间隔来自 `Platform::now()`（µs 级，**真实耗时**，不是"量级"）。
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
