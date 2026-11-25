<?php
declare(strict_types=1);

namespace Drift\Dev\Debug;

use Drift\App\DebugSession;
use Drift\App\Replay;
use Drift\Debug\Session\Script;
use Drift\Debug\Session\Step;

/**
 * 自走宿主的**驱动**：按脚本推帧，并且能被外部叫停。
 *
 * ## 一次只推一步
 *
 * 这里的"一步"是脚本里的一步（帧 / 输入 / 副作用）。推完就返回，宿主回头看一眼
 * 有没有人说话 —— 这是"暂停"能生效的唯一前提。早先它在一个 `while` 里把整个脚本
 * 推到底，于是 `--speed=0`（默认）的宿主从开局到 `finished` 完全不可中断：
 * 实测 2000 帧的脚本，观察者连上时已经是 `finished`。
 *
 * 代价是每步多一次 `wait(0)`（实测 5.35 µs），`--speed=0` 的总时长涨三成。这买的是
 * "干预功能在默认配置下存在"，不是性能回归 —— 帧耗时 `cost` 里不含它。
 *
 * ## 停下来这件事只有一处真相
 *
 * "要不要推进"读的是会话的 `state`，不是这里的某个布尔。所以 `--at` / 单步 /
 * 客户端暂停 / 跑完 / 崩了在宿主眼里是同一句话（不是 `running`），而它们对观察者的
 * 区别由 `reason` 表达。两套记账（宿主一个 `$paused`、会话一个 `state`）必然漂开。
 *
 * ## 停下之后立刻改状态
 *
 * `settle()` 在推完那一步的**同一个函数**里改状态，不留"帧已经推完但 `state` 还是
 * `running`"的窗口。原来的判定在下一次 tick 的开头，那个窗口在 `--speed=0` 下只有
 * 微秒级，但判据偶发红只需要一次。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class Drive implements DrivePort
{
    /** @var list<Step> */
    private readonly array $queue;

    private readonly int $total;

    /** 下一次要推的步号。 */
    private int $index = 0;

    /** 已经推出去的帧数（输入与副作用不算）。 */
    private int $pushed = 0;

    /** @var null|array{0: float, 1: float} 脚本首帧的 `t` 与当时的墙钟，限速的锚点 */
    private ?array $pace = null;

    /** 推到第几帧就停；`null` = 不限额。单步靠它。 */
    private ?int $limit = null;

    /**
     * 观察点：推到第 `$at` 帧停下。
     *
     * **命中一次就清空**：`--at` 的含义是"这儿有东西给你看"，看完了说继续，
     * 它就不该再挡第二次 —— 否则 `resume` 一步都走不动。
     */
    private ?int $at;

    private ?string $failure = null;

    /**
     * @param Script|null           $script 要推的脚本（也用它当开局骨架）
     * @param int|null              $at     推到第 N 帧停（`paused` + `reason=at`）
     * @param float                 $speed  限速倍数：`0` 不等，`1` 实时，`4` 四倍速。
     *                                     它只改**两次推之间等多久**，不改喂进去的 `t`
     * @param null|\Closure(string):void $report 崩溃详情往哪说；`null` = 不报
     */
    public function __construct(
        private readonly Script $script,
        ?int $at = null,
        private readonly float $speed = 0.0,
        private readonly ?\Closure $report = null,
    ) {
        $this->queue = $script->steps();
        $this->total = \count($this->queue);
        $this->at = $at;
    }

    /** 开局脚本：自走宿主没有客户端发 `open`，会话只能由它自己建。 */
    public function script(): Script
    {
        return $this->script;
    }

    public function live(): bool
    {
        return false;
    }

    /** 最近一次崩溃的详情；没崩过为 `null`。 */
    public function failure(): ?string
    {
        return $this->failure;
    }

    /**
     * 推进一步。推不动（会话已停 / 限速未到 / 脚本已尽）就什么都不做。
     *
     * 不返回"推没推"：调用方关心的下一件事是 `$session->canAdvance()`，而那个问题
     * 该问会话本身，不该由这里转述 —— 转述就会有两个答案。
     */
    public function advance(DebugSession $session): void
    {
        if (!$session->canAdvance()) {
            return;
        }

        if ($this->index >= $this->total) {
            $session->markState('finished');

            return;
        }

        $step = $this->queue[$this->index];
        $isFrame = $step->kind === Step::FRAME;

        // 限速只对帧生效：输入与副作用没有时长，等它们等于凭空插一段延迟。
        if ($isFrame && $this->speed > 0.0 && $step->t !== null) {
            $now = \hrtime(true) / 1e6;
            $this->pace ??= [$step->t, $now];
            $waitMs = $this->pace[1] + ($step->t - $this->pace[0]) / $this->speed - $now;
            if ($waitMs > 0.5) {
                // 单次最多 4ms：16ms 一帧也就问 4 次，够宿主插进来收消息。
                \usleep((int) (\min($waitMs, 4.0) * 1000));

                return;
            }
        }

        try {
            Replay::advance($session, $step, $this->index + 1);
        } catch (\Throwable $e) {
            // 崩了也要留着会话：观察者还得能查出"崩之前录到了什么"。
            $this->failure = $e->getMessage();
            $session->markState('crashed', 'exception');
            if ($this->report !== null) {
                ($this->report)($this->failure);
            }

            return;
        }

        ++$this->index;
        if ($isFrame) {
            ++$this->pushed;
        }

        $this->settle($session);
    }

    /** 客户端按了暂停。已经停着的不动，免得把 `at` / `step` 的原因抹掉。 */
    public function pause(DebugSession $session): void
    {
        if ($session->canAdvance()) {
            $session->markState('paused', 'requested');
        }
    }

    /**
     * 继续跑；`$frames` 给了就只跑这么多帧（`1` = 单步）。
     *
     * 锚点必须清空：暂停期间时钟照走，旧锚点会让恢复后连推几十帧去"补账"，
     * 而 `t` 序列与 `digest` 都不显形（`t` 来自脚本）—— 观察者只会看到"恢复之后
     * 突然多了一堆帧"。
     */
    public function resume(DebugSession $session, ?int $frames = null): void
    {
        if ($frames !== null) {
            $this->limit = $this->pushed + \max(1, $frames);
        }
        $this->pace = null;
        $session->markState('running');
    }

    /**
     * 推完一步之后立刻判停。
     *
     * `at` 与 `limit` 命中同一帧时报 `at`：预设观察点承载"这儿有问题"的语义，
     * 比"你要的那一步走完了"更该被优先告知。两者都会被清掉，不留悬空限额。
     */
    private function settle(DebugSession $session): void
    {
        $reason = null;

        if ($this->at !== null && $this->pushed >= $this->at) {
            $this->at = null;
            $reason = 'at';
        }

        if ($this->limit !== null && $this->pushed >= $this->limit) {
            $this->limit = null;
            $reason ??= 'step';
        }

        if ($reason !== null) {
            $session->markState('paused', $reason);

            return;
        }

        if ($this->index >= $this->total) {
            $session->markState('finished');
        }
    }
}
